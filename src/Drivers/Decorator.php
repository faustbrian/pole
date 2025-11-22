<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Drivers;

use BackedEnum;
use Carbon\CarbonInterface;
use Cline\Toggl\Concerns\NormalizesFeatureInput;
use Cline\Toggl\Contracts\CanListStoredFeatures;
use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Contracts\FeatureGroupMembershipRepository;
use Cline\Toggl\Contracts\GroupRepository;
use Cline\Toggl\Contracts\HasFlushableCache;
use Cline\Toggl\Database\Feature;
use Cline\Toggl\Events\FeatureActivated;
use Cline\Toggl\Events\FeatureDeactivated;
use Cline\Toggl\Exceptions\CannotListStoredFeaturesException;
use Cline\Toggl\Exceptions\InvalidVariantWeightsException;
use Cline\Toggl\FeatureManager;
use Cline\Toggl\GroupManager;
use Cline\Toggl\LazilyResolvedFeature;
use Cline\Toggl\PendingContextualFeatureInteraction;
use Cline\Toggl\Support\ContextResolver;
use Cline\Toggl\Support\FeatureScope;
use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ReflectionFunction;

use const JSON_OBJECT_AS_ARRAY;
use const JSON_THROW_ON_ERROR;

use function array_all;
use function array_any;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_pop;
use function array_sum;
use function array_values;
use function assert;
use function class_exists;
use function collect;
use function config;
use function func_num_args;
use function in_array;
use function is_array;
use function is_callable;
use function is_int;
use function is_object;
use function is_string;
use function json_decode;
use function method_exists;
use function property_exists;
use function tap;
use function throw_if;

/**
 * Feature flag driver decorator that adds caching, grouping, variants, and dependency management.
 *
 * This decorator wraps around any Driver implementation to provide:
 * - In-memory caching of feature flag values
 * - Feature grouping and bulk operations
 * - A/B testing via weighted variants
 * - Feature expiration dates
 * - Dependency management between features
 * - Dynamic feature discovery and definition
 *
 * @mixin PendingContextualFeatureInteraction
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class Decorator implements CanListStoredFeatures, Driver, HasFlushableCache
{
    use Macroable {
        __call as macroCall;
    }
    use NormalizesFeatureInput;

    /**
     * The in-memory feature state cache.
     *
     * Stores resolved feature values to avoid redundant driver queries within a request.
     * Each entry contains the feature name, context cache key, and cached value.
     *
     * @var Collection<int, array{ feature: string, context: string, value: mixed }>
     */
    private Collection $cache;

    /**
     * Map of feature names to their implementations.
     *
     * Maps user-friendly feature names to their underlying implementation, which can be:
     * - A feature class name (string)
     * - A LazilyResolvedFeature instance (for fluent API definitions)
     * - A resolver closure or callable
     * - A static value
     *
     * @var array<string, mixed>
     */
    private array $nameMap = [];

    /**
     * Map of group names to their feature lists.
     *
     * Groups allow managing multiple related features together. Each group name
     * maps to an array of feature names that belong to that group.
     *
     * @var array<string, array<string>>
     */
    private array $groups = [];

    /**
     * Stack to track dependency checking to prevent infinite recursion.
     *
     * Stores feature+context combinations currently being checked for dependencies.
     * Used to detect circular dependencies between features (e.g., A requires B, B requires A).
     *
     * @var array<string>
     */
    private array $dependencyCheckStack = [];

    /**
     * Map of variant feature names to their weight distributions.
     *
     * For A/B testing, maps variant feature names to their variant options and percentage
     * weights. Weights must sum to 100. Used for consistent hash-based variant assignment.
     *
     * @var array<string, array<string, int>>
     */
    private array $variants = [];

    /**
     * Create a new driver decorator instance.
     *
     * @param string                           $name                   The driver name used for identification and configuration lookups.
     *                                                                 This name corresponds to a key in the toggl.stores config array,
     *                                                                 enabling multiple feature stores with different backends
     * @param Driver                           $driver                 The underlying driver instance to decorate (ArrayDriver, DatabaseDriver, etc).
     *                                                                 The decorator adds in-memory caching, grouping, A/B testing variants,
     *                                                                 dependency management, and expiration features on top of the base driver
     * @param callable(): mixed                $defaultContextResolver Closure that resolves the current default context (typically the authenticated user).
     *                                                                 Called when no context is explicitly provided for feature checks. Returns null,
     *                                                                 a user model, an ID, or any contextable value for contextual feature evaluation
     * @param Container                        $container              Laravel service container instance used for dependency injection when
     *                                                                 instantiating feature classes, resolving feature dependencies, and accessing
     *                                                                 the FeatureManager for global context management
     * @param GroupRepository                  $groupRepository        Repository implementation for persisting and retrieving feature group definitions.
     *                                                                 Can be array-based (in-memory) or database-backed depending on configuration,
     *                                                                 enabling logical grouping of related features for bulk operations
     * @param FeatureGroupMembershipRepository $membershipRepository   Repository implementation for managing which contexts (users, teams) belong to
     *                                                                 which feature groups, enabling group-based feature activation patterns and
     *                                                                 tier-based access control for subscription or permission systems
     */
    public function __construct(
        private readonly string $name,
        private readonly Driver $driver,
        private readonly mixed $defaultContextResolver,
        private Container $container,
        private readonly GroupRepository $groupRepository,
        private readonly FeatureGroupMembershipRepository $membershipRepository,
    ) {
        $this->cache = new Collection();
        $this->loadGroupsFromConfig();
    }

    /**
     * Dynamically create a pending feature interaction.
     *
     * Handles macro calls and creates PendingContextualFeatureInteraction instances
     * for fluent method chaining when checking feature flags.
     *
     * @param  string            $name       The method name being called
     * @param  array<int, mixed> $parameters The method parameters
     * @return mixed             The result of the macro or pending interaction
     */
    public function __call(string $name, array $parameters): mixed
    {
        if (self::hasMacro($name)) {
            return $this->macroCall($name, $parameters);
        }

        return tap(
            new PendingContextualFeatureInteraction($this),
            function ($interaction) use ($name): void {
                if ($name !== 'for' && ($this->defaultContextResolver)() !== null) {
                    $interaction->for(($this->defaultContextResolver)());
                }
            },
        )->{$name}(...$parameters);
    }

    /**
     * Define an initial feature flag state resolver.
     *
     * Supports multiple definition patterns:
     * - Class-based features (auto-discovery): define(MyToggl::class)
     * - Lazy definition (fluent API): define('feature-name') returns LazilyResolvedFeature
     * - With resolver: define('feature-name', fn($context) => true)
     * - With LazilyResolvedFeature: define('feature-name', $lazilyResolved)
     *
     * @param  string                                                             $feature  The feature name or class name
     * @param  null|(callable(mixed $context): mixed)|LazilyResolvedFeature|mixed $resolver The resolver callback or value
     * @return null|LazilyResolvedFeature                                         Returns LazilyResolvedFeature for fluent API, otherwise null
     */
    public function define(string|BackedEnum $feature, mixed $resolver = null): mixed
    {
        $feature = $this->normalizeFeature($feature);

        // If only one argument and it's a string that can be instantiated
        if (func_num_args() === 1 && class_exists($feature)) {
            // Feature class auto-discovery pattern
            $instance = $this->container->make($feature);
            assert(is_object($instance));

            /** @var string $featureName */
            $featureName = property_exists($instance, 'name') && is_string($instance->name) ? $instance->name : $feature;
            $this->nameMap[$featureName] = $feature;

            $this->driver->define($featureName, function ($context) use ($feature, $instance) {
                if (method_exists($instance, 'resolve')) {
                    // PHPStan doesn't understand dynamic method_exists check
                    /** @phpstan-ignore-next-line callable.nonNativeMethod */
                    $resolver = $instance->resolve(...);
                } else {
                    assert(is_callable($instance));
                    $resolver = Closure::fromCallable($instance);
                }

                return $this->resolve($feature, $resolver, $context);
            });

            return null;
        }

        // Return LazilyResolvedFeature for fluent API when no resolver provided
        if ($resolver === null) {
            $lazilyResolved = new LazilyResolvedFeature($feature, fn (): false => false, $this);
            $this->nameMap[$feature] = $lazilyResolved;

            return $lazilyResolved;
        }

        // Check if resolver is already a LazilyResolvedFeature (from fluent call)
        if ($resolver instanceof LazilyResolvedFeature) {
            $this->nameMap[$feature] = $resolver;

            $this->driver->define($feature, function ($context) use ($feature, $resolver) {
                /** @var callable $resolverCallable */
                $resolverCallable = $resolver->getResolver();

                return $this->resolve($feature, $resolverCallable, $context);
            });

            return null;
        }

        // Standard definition with resolver
        $this->nameMap[$feature] = $resolver;

        // Wrap resolver to pass through to resolve() method which handles context
        $this->driver->define($feature, function ($context) use ($feature, $resolver) {
            if (!$resolver instanceof Closure) {
                return $this->resolve($feature, fn ($context, $meta = null): mixed => $resolver, $context);
            }

            return $this->resolve($feature, $resolver, $context);
        });

        return null;
    }

    /**
     * Retrieve the names of all defined features.
     *
     * @return array<string>
     */
    public function defined(): array
    {
        return $this->driver->defined();
    }

    /**
     * Retrieve the names of all stored features.
     *
     * Returns features that have been persisted by the underlying driver.
     * Only works if the underlying driver implements CanListStoredFeatures.
     *
     * @throws CannotListStoredFeaturesException If the underlying driver doesn't support listing stored features
     *
     * @return array<string> List of stored feature names
     */
    public function stored(): array
    {
        if (!$this->driver instanceof CanListStoredFeatures) {
            throw CannotListStoredFeaturesException::forDriver($this->name);
        }

        return $this->driver->stored();
    }

    /**
     * Get multiple feature flag values.
     *
     * Retrieves values for multiple features at once and caches the results.
     * More efficient than multiple individual get() calls.
     *
     * @internal
     *
     * @param  array<int|string, mixed>|string  $features Feature names or feature-to-contexts mapping
     * @return array<string, array<int, mixed>> Nested array of feature values by feature and context
     */
    public function getAll(array|string $features): array
    {
        $features = $this->normalizeFeaturesToLoad($features);

        if ($features->isEmpty()) {
            return [];
        }

        /** @var array<string, array<int, TogglContext>> $featureArray */
        $featureArray = $features->all();
        $results = $this->driver->getAll($featureArray);

        // Cache the results
        foreach ($features as $key => $contexts) {
            /** @var array<int, TogglContext> $contexts */
            foreach ($contexts as $index => $context) {
                $value = $results[$key][$index] ?? null;
                $this->putInCache($key, $context, $value);
            }
        }

        return $results;
    }

    /**
     * Get multiple feature flag values that are missing from cache.
     *
     * Only queries the underlying driver for features not already cached,
     * improving performance for repeated checks.
     *
     * @internal
     *
     * @param  array<int|string, mixed>|string  $features Feature names or feature-to-contexts mapping
     * @return array<string, array<int, mixed>> Nested array of feature values by feature and context
     */
    public function getAllMissing(array|string $features): array
    {
        $normalized = $this->normalizeFeaturesToLoad($features);
        $missing = [];

        foreach ($normalized as $feature => $contexts) {
            /** @var array<int, TogglContext> $contexts */
            $uncachedContexts = [];

            foreach ($contexts as $context) {
                if (!$this->isCached($feature, $context)) {
                    $uncachedContexts[] = $context;
                }
            }

            if ($uncachedContexts !== []) {
                $missing[$feature] = $uncachedContexts;
            }
        }

        return $this->getAll($missing);
    }

    /**
     * Retrieve a feature flag's value.
     *
     * Checks expiration, dependencies, and cache before querying the underlying driver.
     * Includes circular dependency detection to prevent infinite recursion.
     *
     * @internal
     *
     * @param  string $feature The feature name
     * @param  mixed  $context The context to check (user, team, etc.)
     * @return mixed  The feature value (typically bool, but can be any type)
     */
    public function get(string|BackedEnum $feature, mixed $context): mixed
    {
        $feature = $this->normalizeFeature($feature);
        $feature = $this->resolveFeature($feature);

        $context = $this->resolveContext($context);

        // Check if feature is expired first (before checking cache)
        $lazilyResolved = $this->getLazilyResolvedFeature($feature);

        if ($lazilyResolved instanceof LazilyResolvedFeature && $lazilyResolved->isExpired()) {
            return false;
        }

        // Check dependencies (with recursion guard)
        if ($lazilyResolved instanceof LazilyResolvedFeature && $lazilyResolved->getRequires() !== []) {
            // Prevent infinite recursion from circular dependencies
            $stackKey = $feature.'|'.$context->toCacheKey();

            if (in_array($stackKey, $this->dependencyCheckStack, true)) {
                // Circular dependency detected
                return false;
            }

            $this->dependencyCheckStack[] = $stackKey;

            try {
                foreach ($lazilyResolved->getRequires() as $requiredFeature) {
                    if ($this->get($requiredFeature, $context) === false) {
                        return false;
                    }
                }
            } finally {
                // Always remove from stack
                array_pop($this->dependencyCheckStack);
            }
        }

        $item = $this->cache
            ->whereStrict('context', $context->toCacheKey())
            ->whereStrict('feature', $feature)
            ->first();

        if ($item !== null) {
            return $item['value'];
        }

        $value = $this->driver->get($feature, $context);

        // If feature is not directly active (false), check feature group membership
        // Note: null is treated as an explicitly set value, not "inactive"
        if ($value === false) {
            $value = $this->checkFeatureViaFeatureGroupMembership($feature, $context);
        }

        $this->putInCache($feature, $context, $value);

        return $value;
    }

    /**
     * Retrieve a feature flag's value with scoped scope matching.
     *
     * When scope is enabled, this method checks both direct context matches
     * and scope scope matches, prioritizing direct matches.
     *
     * @param  BackedEnum|string $feature The feature name
     * @param  mixed             $context The context to check (user, team, etc.)
     * @param  FeatureScope      $scope   The scope scope to match against
     * @return mixed             The feature value (typically bool, but can be any type)
     */
    public function getWithScope(string|BackedEnum $feature, mixed $context, FeatureScope $scope): mixed
    {
        $feature = $this->normalizeFeature($feature);
        $feature = $this->resolveFeature($feature);

        $context = $this->resolveContext($context);

        // Check cache first (scope lookup isn't cached differently)
        $item = $this->cache
            ->whereStrict('context', $context->toCacheKey())
            ->whereStrict('feature', $feature)
            ->first();

        if ($item !== null) {
            return $item['value'];
        }

        // Use scope-aware retrieval if driver supports it
        if ($this->driver instanceof DatabaseDriver) {
            $record = $this->driver->retrieveWithScope($feature, $context, $scope);
            $value = $record instanceof Feature
                ? json_decode($record->value, flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR)
                : false;
        } else {
            // For non-database drivers, attach scope scope to context for matching
            $contextWithScope = $context->withFeatureScope($scope);
            $value = $this->driver->get($feature, $contextWithScope);
        }

        // If feature is not directly active (false), check feature group membership
        if ($value === false) {
            $value = $this->checkFeatureViaFeatureGroupMembership($feature, $context);
        }

        $this->putInCache($feature, $context, $value);

        return $value;
    }

    /**
     * Set a feature flag's value.
     *
     * Updates both the underlying driver and the in-memory cache.
     * Dispatches FeatureActivated or FeatureDeactivated events.
     *
     * @internal
     *
     * @param string $feature The feature name
     * @param mixed  $context The context to set (user, team, etc.)
     * @param mixed  $value   The value to set
     */
    public function set(string|BackedEnum $feature, mixed $context, mixed $value): void
    {
        $feature = $this->normalizeFeature($feature);
        $feature = $this->resolveFeature($feature);

        $context = $this->resolveContext($context);

        $this->driver->set($feature, $context, $value);

        $this->putInCache($feature, $context, $value);

        $this->dispatchFeatureEvent($feature, $context, $value);
    }

    /**
     * Activate the feature for everyone.
     *
     * Sets the feature value globally across all contexts. This updates all existing
     * stored values for the feature, making it active for every user/entity in the system.
     *
     * @param array<string>|string $feature Feature name(s) to activate
     * @param mixed                $value   The value to set (defaults to true)
     */
    public function activateForEveryone(string|BackedEnum|array $feature, mixed $value = true): void
    {
        $feature = $this->normalizeFeatureInput($feature);
        $features = is_array($feature) ? $feature : [$feature];

        foreach ($features as $name) {
            $this->setForAllContexts($name, $value);
        }
    }

    /**
     * Deactivate the feature for everyone.
     *
     * Sets the feature to false globally across all contexts. This updates all existing
     * stored values for the feature, making it inactive for every user/entity in the system.
     *
     * @param array<string>|string $feature Feature name(s) to deactivate
     */
    public function deactivateForEveryone(string|BackedEnum|array $feature): void
    {
        $feature = $this->normalizeFeatureInput($feature);
        $features = is_array($feature) ? $feature : [$feature];

        foreach ($features as $name) {
            $this->setForAllContexts($name, false);
        }
    }

    /**
     * Alias for activateForEveryone() - enable a feature globally.
     *
     * @param array<string>|BackedEnum|string $feature Feature name(s) to enable
     * @param mixed                           $value   The value to set (defaults to true)
     */
    public function enableGlobally(string|BackedEnum|array $feature, mixed $value = true): void
    {
        $this->activateForEveryone($feature, $value);
    }

    /**
     * Alias for deactivateForEveryone() - disable a feature globally.
     *
     * @param array<string>|BackedEnum|string $feature Feature name(s) to disable
     */
    public function disableGlobally(string|BackedEnum|array $feature): void
    {
        $this->deactivateForEveryone($feature);
    }

    /**
     * Set a feature flag's value for all contexts.
     *
     * Delegates to the underlying driver and clears cached values for this feature.
     *
     * @internal
     *
     * @param string $feature The feature name
     * @param mixed  $value   The value to set globally
     */
    public function setForAllContexts(string $feature, mixed $value): void
    {
        $feature = $this->resolveFeature($feature);

        $this->driver->setForAllContexts($feature, $value);

        $filtered = [];

        foreach ($this->cache as $item) {
            if ($item['feature'] !== $feature) {
                $filtered[] = $item;
            }
        }

        $this->cache = collect($filtered);
    }

    /**
     * Delete a feature flag's value.
     *
     * Removes the feature value for a specific context from storage and cache.
     *
     * @internal
     *
     * @param string $feature The feature name
     * @param mixed  $context The context to delete
     */
    public function delete(string $feature, mixed $context): void
    {
        $feature = $this->resolveFeature($feature);
        $context = $this->resolveContext($context);

        $this->driver->delete($feature, $context);

        $this->removeFromCache($feature, $context);
    }

    /**
     * Purge the given features from storage.
     *
     * Removes all stored values for the specified features from both the underlying
     * driver and the in-memory cache. If no features are specified, purges all features
     * from storage and clears the entire cache. This is a destructive operation.
     *
     * @param null|array<BackedEnum|string>|BackedEnum|string $features Feature name(s) or enum(s) to purge, or null to purge all
     */
    public function purge(string|BackedEnum|array|null $features = null): void
    {
        if ($features === null) {
            $this->driver->purge(null);
            $this->cache = new Collection();
        } else {
            $features = $this->normalizeFeatureInput($features);
            $featureArray = is_array($features) ? $features : [$features];

            $resolved = [];

            foreach ($featureArray as $feature) {
                $resolved[] = $this->resolveFeature($feature);
            }

            $this->driver->purge($resolved);

            // Remove from cache
            $keysToRemove = [];

            foreach ($this->cache as $key => $item) {
                if (in_array($item['feature'], $resolved, true)) {
                    $keysToRemove[] = $key;
                }
            }

            $this->cache->forget($keysToRemove);
        }
    }

    /**
     * Retrieve the feature's name.
     *
     * Resolves a feature class to its registered name.
     *
     * @param  string $feature The feature name or class
     * @return string The resolved feature name
     */
    public function name(string|BackedEnum $feature): string
    {
        $feature = $this->normalizeFeature($feature);

        return $this->resolveFeature($feature);
    }

    /**
     * Retrieve the map of feature names to their implementations.
     *
     * @return array<string, mixed>
     */
    public function nameMap(): array
    {
        return $this->nameMap;
    }

    /**
     * Retrieve the feature's instance.
     *
     * Returns the underlying implementation for a feature - either a class instance,
     * a closure, or a wrapped value.
     *
     * @param  string $name The feature name
     * @return mixed  The feature instance, closure, or wrapped value
     */
    public function instance(string $name): mixed
    {
        $feature = $this->nameMap[$name] ?? $name;

        if (is_string($feature) && class_exists($feature)) {
            return $this->container->make($feature);
        }

        if ($feature instanceof Closure) {
            return $feature;
        }

        return fn () => $feature;
    }

    /**
     * Get the group manager for fluent group operations.
     *
     * @return GroupManager Fluent interface for managing groups
     */
    public function groups(): GroupManager
    {
        return new GroupManager($this->groupRepository, $this->membershipRepository);
    }

    /**
     * Define a feature group.
     *
     * Groups allow managing multiple related features together as a single unit.
     * Useful for activating/deactivating sets of related features or checking
     * if all features in a tier are enabled.
     *
     * @param string             $name     The group name
     * @param array<int, string> $features List of feature names in this group
     */
    public function defineGroup(string $name, array $features): void
    {
        $features = array_values($features);
        $this->groupRepository->define($name, $features);
        $this->groups[$name] = $features;
    }

    /**
     * Get a feature group.
     *
     * @param string $name The group name
     *
     * @throws InvalidArgumentException If the group doesn't exist
     *
     * @return array<string> List of feature names in the group
     */
    public function getGroup(string $name): array
    {
        if (array_key_exists($name, $this->groups)) {
            return $this->groups[$name];
        }

        $features = $this->groupRepository->get($name);
        $this->groups[$name] = $features;

        return $features;
    }

    /**
     * Get all feature groups.
     *
     * @return array<string, array<string>>
     */
    public function allGroups(): array
    {
        return array_merge($this->groups, $this->groupRepository->all());
    }

    /**
     * Activate all features in a group.
     *
     * Sets all features in the group to true across all contexts.
     *
     * @param string $name The group name
     */
    public function activateGroup(string $name): void
    {
        $features = $this->getGroup($name);

        foreach ($features as $feature) {
            $this->setForAllContexts($feature, true);
        }
    }

    /**
     * Deactivate all features in a group.
     *
     * Sets all features in the group to false across all contexts.
     *
     * @param string $name The group name
     */
    public function deactivateGroup(string $name): void
    {
        $features = $this->getGroup($name);

        foreach ($features as $feature) {
            $this->setForAllContexts($feature, false);
        }
    }

    /**
     * Activate all features in a group for everyone.
     *
     * Alias for activateGroup() - sets all features to true across all contexts.
     *
     * @param string $name The group name
     */
    public function activateGroupForEveryone(string $name): void
    {
        $this->activateGroup($name);
    }

    /**
     * Deactivate all features in a group for everyone.
     *
     * Alias for deactivateGroup() - sets all features to false across all contexts.
     *
     * @param string $name The group name
     */
    public function deactivateGroupForEveryone(string $name): void
    {
        $this->deactivateGroup($name);
    }

    /**
     * Check if all features in a group are active.
     *
     * Returns true only if every feature in the group is active for the default context.
     * Empty groups are considered "all active".
     *
     * @param  string $name The group name
     * @return bool   True if all features are active, false otherwise
     */
    public function activeInGroup(string $name): bool
    {
        $features = $this->getGroup($name);

        if ($features === []) {
            return true; // Empty group is considered "all active"
        }

        return array_all($features, fn (string $feature): bool => $this->get($feature, $this->defaultContext()) !== false);
    }

    /**
     * Check if any features in a group are active.
     *
     * Returns true if at least one feature in the group is active for the default context.
     * Empty groups return false.
     *
     * @param  string $name The group name
     * @return bool   True if at least one feature is active, false otherwise
     */
    public function someActiveInGroup(string $name): bool
    {
        $features = $this->getGroup($name);

        if ($features === []) {
            return false; // Empty group has no features to be active
        }

        return array_any($features, fn (string $feature): bool => $this->get($feature, $this->defaultContext()) !== false);
    }

    /**
     * Load groups from configuration.
     *
     * Reads the 'toggl.groups' configuration and defines all groups found.
     * Each group must have a 'features' array key containing feature names.
     * Called automatically during driver initialization.
     */
    public function loadGroupsFromConfig(): void
    {
        $groups = Config::get('toggl.groups', []);

        if (!is_array($groups)) {
            return;
        }

        foreach ($groups as $name => $data) {
            if (!is_string($name)) {
                continue;
            }

            if (!is_array($data)) {
                continue;
            }

            if (array_key_exists('features', $data) && is_array($data['features'])) {
                $features = array_filter($data['features'], is_string(...));
                $this->groups[$name] = $features;
            }
        }
    }

    /**
     * Check if a feature is expired.
     *
     * Features can have expiration dates set via LazilyResolvedToggl::expiresAt().
     * Expired features automatically return false when checked.
     *
     * @param  string $feature The feature name
     * @return bool   True if the feature is expired, false otherwise
     */
    public function isExpired(string|BackedEnum $feature): bool
    {
        $feature = $this->normalizeFeature($feature);
        $lazilyResolved = $this->getLazilyResolvedFeature($feature);

        if (!$lazilyResolved instanceof LazilyResolvedFeature) {
            return false;
        }

        return $lazilyResolved->isExpired();
    }

    /**
     * Get the expiration date for a feature.
     *
     * @param  string               $feature The feature name
     * @return null|CarbonInterface The expiration date, or null if not set
     */
    public function expiresAt(string|BackedEnum $feature): ?CarbonInterface
    {
        $feature = $this->normalizeFeature($feature);
        $lazilyResolved = $this->getLazilyResolvedFeature($feature);

        if (!$lazilyResolved instanceof LazilyResolvedFeature) {
            return null;
        }

        return $lazilyResolved->getExpiresAt();
    }

    /**
     * Check if a feature is expiring soon.
     *
     * Useful for warning about features that will expire within a specified timeframe.
     *
     * @param  string $feature The feature name
     * @param  int    $days    Number of days to check ahead
     * @return bool   True if the feature expires within the specified days, false otherwise
     */
    public function isExpiringSoon(string|BackedEnum $feature, int $days): bool
    {
        $feature = $this->normalizeFeature($feature);
        $lazilyResolved = $this->getLazilyResolvedFeature($feature);

        if (!$lazilyResolved instanceof LazilyResolvedFeature) {
            return false;
        }

        return $lazilyResolved->isExpiringSoon($days);
    }

    /**
     * Get all features that are expiring soon.
     *
     * Scans all defined features and returns those expiring within the specified timeframe.
     *
     * @param  int           $days Number of days to check ahead
     * @return array<string> List of feature names expiring soon
     */
    public function expiringSoon(int $days): array
    {
        $expiring = [];

        foreach ($this->nameMap as $name => $resolver) {
            if ($resolver instanceof LazilyResolvedFeature && $resolver->isExpiringSoon($days)) {
                $expiring[] = $name;
            }
        }

        return $expiring;
    }

    /**
     * Get the dependencies for a feature.
     *
     * Returns features that must be active for this feature to be active.
     * Dependencies are set via LazilyResolvedToggl::requires().
     *
     * @param  string        $feature The feature name
     * @return array<string> List of required feature names
     */
    public function getDependencies(string $feature): array
    {
        $lazilyResolved = $this->getLazilyResolvedFeature($feature);

        if (!$lazilyResolved instanceof LazilyResolvedFeature) {
            return [];
        }

        return $lazilyResolved->getRequires();
    }

    /**
     * Check if all dependencies for a feature are met.
     *
     * Verifies that all required features are active for the default context.
     *
     * @param  string $feature The feature name
     * @return bool   True if all dependencies are met (or no dependencies exist), false otherwise
     */
    public function dependenciesMet(string|BackedEnum $feature): bool
    {
        $feature = $this->normalizeFeature($feature);
        $dependencies = $this->getDependencies($feature);

        if ($dependencies === []) {
            return true;
        }

        $context = $this->defaultContext();

        return array_all($dependencies, fn (string $dependency): bool => $this->get($dependency, $context) !== false);
    }

    /**
     * Flush the in-memory cache of feature values.
     *
     * Clears the decorator's cache and optionally the underlying driver's cache.
     * Useful for testing or when feature values have been modified externally.
     */
    public function flushCache(): void
    {
        $this->cache = new Collection();

        if ($this->driver instanceof HasFlushableCache) {
            $this->driver->flushCache();
        }
    }

    /**
     * Get the underlying feature driver.
     *
     * Provides access to the wrapped driver instance for direct operations.
     *
     * @return Driver The underlying driver implementation
     */
    public function getDriver(): Driver
    {
        return $this->driver;
    }

    /**
     * Set the container instance used by the decorator.
     *
     * Allows runtime replacement of the Laravel service container.
     *
     * @param  Container $container The Laravel service container
     * @return static    Fluent interface
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Define a variant feature with distribution weights.
     *
     * Variants enable A/B testing by distributing users across different variations
     * based on weighted percentages. Users are consistently assigned to the same
     * variant using a hash of their context.
     *
     * @param string             $name    The variant feature name
     * @param array<string, int> $weights Map of variant names to percentage weights (must sum to 100)
     *
     * @throws InvalidArgumentException If weights don't sum to 100
     */
    public function defineVariant(string $name, array $weights): void
    {
        // Validate weights sum to 100
        $total = array_sum($weights);

        throw_if($total !== 100, InvalidVariantWeightsException::mustSumTo100($total));

        $this->variants[$name] = $weights;
    }

    /**
     * Get the variant for a feature based on context.
     *
     * Uses consistent hashing to assign the default context to a variant.
     * The assignment is deterministic and stored for consistency.
     *
     * @param  string      $name The variant feature name
     * @return null|string The assigned variant name, or null if not defined
     */
    public function variant(string $name): ?string
    {
        if (!array_key_exists($name, $this->variants)) {
            return null;
        }

        $context = $this->defaultContext();

        // Check if variant is already stored for this context
        $storedVariant = $this->driver->get($name, $context);

        if ($storedVariant !== false && is_string($storedVariant)) {
            return $storedVariant;
        }

        // Calculate variant based on consistent hashing
        $variant = Toggl::calculateVariant($name, $context, $this->variants[$name]);

        // Store the variant
        $this->driver->set($name, $context, $variant);

        return $variant;
    }

    /**
     * Get the variant weights for a feature.
     *
     * @param  string             $name The variant feature name
     * @return array<string, int> Map of variant names to their weights, or empty array if not defined
     */
    public function getVariants(string $name): array
    {
        return $this->variants[$name] ?? [];
    }

    /**
     * Get the variant names for a feature.
     *
     * @param  string        $name The variant feature name
     * @return array<string> List of variant names, or empty array if not defined
     */
    public function variantNames(string $name): array
    {
        if (!array_key_exists($name, $this->variants)) {
            return [];
        }

        return array_keys($this->variants[$name]);
    }

    /**
     * Check if a feature is active via feature group membership.
     *
     * Checks if the context belongs to any groups that have this feature active.
     *
     * @param  string       $feature The feature name
     * @param  TogglContext $context The context to check
     * @return mixed        The feature value if active via group, false otherwise
     */
    private function checkFeatureViaFeatureGroupMembership(string $feature, TogglContext $context): mixed
    {
        // Get all groups this context belongs to
        $groups = $this->membershipRepository->getGroupsForContext($context);

        foreach ($groups as $groupName) {
            // Get features in this group
            $groupFeatures = $this->getGroup($groupName);

            // Check if our feature is in this group
            if (in_array($feature, $groupFeatures, true)) {
                // Check if the feature is active for this group (using global context)
                $globalContext = TogglContext::simple('__all__', '__global__');
                $groupValue = $this->driver->get($feature, $globalContext);

                if ($groupValue !== false && $groupValue !== null) {
                    return $groupValue;
                }
            }
        }

        return false;
    }

    /**
     * Resolve the feature value and dispatch event.
     *
     * Handles expiration checks, dependency validation, and invokable objects
     * (like Laravel Lottery). Includes circular dependency detection.
     *
     * @param  string   $feature  The feature name
     * @param  callable $resolver The resolver callback
     * @param  mixed    $context  The context to resolve for
     * @return mixed    The resolved feature value
     */
    private function resolve(string $feature, callable $resolver, mixed $context): mixed
    {
        // Check if feature is expired
        $lazilyResolved = $this->getLazilyResolvedFeature($feature);

        if ($lazilyResolved instanceof LazilyResolvedFeature && $lazilyResolved->isExpired()) {
            return false;
        }

        // Check dependencies (circular dependency guard is in get() method)
        if ($lazilyResolved instanceof LazilyResolvedFeature && $lazilyResolved->getRequires() !== []) {
            foreach ($lazilyResolved->getRequires() as $requiredFeature) {
                if ($this->get($requiredFeature, $context) === false) {
                    return false;
                }
            }
        }

        // Get current context from context manager
        $globalContext = $this->container->make(FeatureManager::class)->context()->current();

        // Pass context to resolver - check if it accepts the second parameter
        $reflection = new ReflectionFunction($resolver instanceof Closure ? $resolver : $resolver(...));
        $paramCount = $reflection->getNumberOfParameters();

        $value = $paramCount >= 2 ? $resolver($context, $globalContext) : $resolver($context);

        // Support Laravel Lottery if available
        if (is_object($value) && method_exists($value, '__invoke')) {
            return $value();
        }

        return $value;
    }

    /**
     * Get the LazilyResolvedFeature instance for a feature if it exists.
     *
     * @param  string                     $feature The feature name
     * @return null|LazilyResolvedFeature The LazilyResolvedFeature instance, or null if not applicable
     */
    private function getLazilyResolvedFeature(string $feature): ?LazilyResolvedFeature
    {
        if (!array_key_exists($feature, $this->nameMap)) {
            return null;
        }

        return $this->nameMap[$feature] instanceof LazilyResolvedFeature
            ? $this->nameMap[$feature]
            : null;
    }

    /**
     * Normalize the features to load.
     *
     * Converts various input formats into a consistent structure mapping
     * feature names to their contexts.
     *
     * @param  array<int|string, mixed>|string              $features Feature names or feature-to-contexts mapping
     * @return Collection<string, array<int, TogglContext>> Normalized collection of features to contexts
     */
    private function normalizeFeaturesToLoad(array|string $features): Collection
    {
        $wrapped = is_array($features) ? $features : [$features];
        $mapped = [];

        foreach ($wrapped as $key => $value) {
            if (is_int($key)) {
                assert(is_string($value) || is_int($value));
                $mapped[$value] = [$this->defaultContext()];
            } else {
                $mapped[$key] = is_array($value) ? $value : [$value];
            }
        }

        $resolved = [];

        foreach ($mapped as $feature => $contexts) {
            $resolvedFeature = $this->resolveFeature((string) $feature);
            $resolvedContexts = [];

            foreach ($contexts as $context) {
                $resolvedContexts[] = $this->resolveContext($context);
            }

            $resolved[$resolvedFeature] = $resolvedContexts;
        }

        return collect($resolved);
    }

    /**
     * Resolve the feature name and ensure it is defined.
     *
     * Handles dynamic feature discovery for class-based features.
     *
     * @param  string $feature The feature name or class
     * @return string The resolved feature name
     */
    private function resolveFeature(string $feature): string
    {
        return $this->shouldDynamicallyDefine($feature)
            ? $this->ensureDynamicFeatureIsDefined($feature)
            : $feature;
    }

    /**
     * Determine if the feature should be dynamically defined.
     *
     * Checks if the feature is a class with resolve() or __invoke() methods.
     *
     * @param  string $feature The feature name or class
     * @return bool   True if the feature should be auto-discovered, false otherwise
     */
    private function shouldDynamicallyDefine(string $feature): bool
    {
        return !in_array($feature, $this->defined(), true)
            && class_exists($feature)
            && (method_exists($feature, 'resolve') || method_exists($feature, '__invoke'));
    }

    /**
     * Dynamically define the feature.
     *
     * Instantiates the feature class and registers it if not already defined.
     *
     * @param  string $feature The feature class name
     * @return string The feature name (extracted from instance or using class name)
     */
    private function ensureDynamicFeatureIsDefined(string $feature): string
    {
        $instance = $this->container->make($feature);
        $name = (is_object($instance) && property_exists($instance, 'name') && is_string($instance->name))
            ? $instance->name
            : $feature;

        if (!in_array($name, $this->defined(), true)) {
            $this->define($feature);
        }

        return $name;
    }

    /**
     * Resolve context to TogglContext.
     *
     * Normalizes any context (model, TogglContextable, etc.) to a TogglContext
     * value object for consistent handling across all driver operations.
     *
     * @param  mixed        $context The context (can be a model, ID, or TogglContextable)
     * @return TogglContext The normalized TogglContext
     */
    private function resolveContext(mixed $context): TogglContext
    {
        return ContextResolver::resolve($context);
    }

    /**
     * Determine if a feature's value is in the cache for the given context.
     *
     * @param  string       $feature The feature name
     * @param  TogglContext $context The context to check
     * @return bool         True if cached, false otherwise
     */
    private function isCached(string $feature, TogglContext $context): bool
    {
        $contextKey = $context->toCacheKey();

        return $this->cache->search(
            fn ($item): bool => $item['feature'] === $feature
                && $item['context'] === $contextKey,
        ) !== false;
    }

    /**
     * Put the given feature's value into the cache.
     *
     * Updates existing cache entries or adds new ones.
     *
     * @param string       $feature The feature name
     * @param TogglContext $context The context
     * @param mixed        $value   The value to cache
     */
    private function putInCache(string $feature, TogglContext $context, mixed $value): void
    {
        $contextKey = $context->toCacheKey();

        $position = $this->cache->search(
            fn ($item): bool => $item['feature'] === $feature
                && $item['context'] === $contextKey,
        );

        if ($position === false) {
            $this->cache[] = ['feature' => $feature, 'context' => $contextKey, 'value' => $value];
        } else {
            $this->cache[$position] = ['feature' => $feature, 'context' => $contextKey, 'value' => $value];
        }
    }

    /**
     * Remove the given feature's value from the cache.
     *
     * @param string       $feature The feature name
     * @param TogglContext $context The context to remove
     */
    private function removeFromCache(string $feature, TogglContext $context): void
    {
        $contextKey = $context->toCacheKey();

        $position = $this->cache->search(
            fn ($item): bool => $item['feature'] === $feature && $item['context'] === $contextKey,
        );

        if ($position !== false) {
            unset($this->cache[$position]);
        }
    }

    /**
     * Retrieve the default context as TogglContext.
     *
     * Invokes the default context resolver and normalizes to TogglContext.
     *
     * @return TogglContext The default context as TogglContext
     */
    private function defaultContext(): TogglContext
    {
        return ContextResolver::resolve(($this->defaultContextResolver)());
    }

    /**
     * Dispatch feature activation or deactivation event.
     *
     * @param string       $feature The feature name
     * @param TogglContext $context The context
     * @param mixed        $value   The value set
     */
    private function dispatchFeatureEvent(string $feature, TogglContext $context, mixed $value): void
    {
        if (!Config::get('toggl.events.enabled', true)) {
            return;
        }

        if (!$this->container->bound(Dispatcher::class)) {
            return;
        }

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->container->make(Dispatcher::class);

        if ($value === false) {
            $dispatcher->dispatch(
                new FeatureDeactivated($feature, $context),
            );
        } else {
            $dispatcher->dispatch(
                new FeatureActivated($feature, $value, $context),
            );
        }
    }
}
