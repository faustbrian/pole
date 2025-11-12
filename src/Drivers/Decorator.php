<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Drivers;

use Carbon\CarbonInterface;
use Cline\Pole\Contracts\CanListStoredFeatures;
use Cline\Pole\Contracts\Driver;
use Cline\Pole\Contracts\FeatureScopeable;
use Cline\Pole\Contracts\HasFlushableCache;
use Cline\Pole\Feature;
use Cline\Pole\LazilyResolvedFeature;
use Cline\Pole\PendingScopedFeatureInteraction;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use RuntimeException;

use function abs;
use function array_all;
use function array_any;
use function array_key_exists;
use function array_key_last;
use function array_keys;
use function array_pop;
use function array_sum;
use function assert;
use function class_exists;
use function crc32;
use function func_num_args;
use function in_array;
use function is_array;
use function is_callable;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;
use function property_exists;
use function sprintf;
use function tap;
use function throw_if;
use function throw_unless;

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
 * @mixin PendingScopedFeatureInteraction
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class Decorator implements CanListStoredFeatures, Driver, HasFlushableCache
{
    use Macroable {
        __call as macroCall;
    }

    /**
     * The in-memory feature state cache.
     *
     * @var Collection<int, array{ feature: string, scope: mixed, value: mixed }>
     */
    private Collection $cache;

    /**
     * Map of feature names to their implementations.
     *
     * @var array<string, mixed>
     */
    private array $nameMap = [];

    /**
     * Map of group names to their feature lists.
     *
     * @var array<string, array<string>>
     */
    private array $groups = [];

    /**
     * Stack to track dependency checking to prevent infinite recursion.
     *
     * @var array<string>
     */
    private array $dependencyCheckStack = [];

    /**
     * Map of variant feature names to their weight distributions.
     *
     * @var array<string, array<string, int>>
     */
    private array $variants = [];

    /**
     * Create a new driver decorator instance.
     *
     * @param string            $name                 The driver name for identification
     * @param Driver            $driver               The underlying driver to decorate
     * @param callable(): mixed $defaultScopeResolver Resolver for the default scope
     * @param Container         $container            Laravel service container instance
     */
    public function __construct(
        private readonly string $name,
        private readonly Driver $driver,
        private readonly mixed $defaultScopeResolver,
        private Container $container,
    ) {
        $this->cache = new Collection();
        $this->loadGroupsFromConfig();
    }

    /**
     * Dynamically create a pending feature interaction.
     *
     * Handles macro calls and creates PendingScopedFeatureInteraction instances
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
            new PendingScopedFeatureInteraction($this),
            function ($interaction) use ($name): void {
                if ($name !== 'for' && ($this->defaultScopeResolver)() !== null) {
                    $interaction->for(($this->defaultScopeResolver)());
                }
            },
        )->{$name}(...$parameters);
    }

    /**
     * Define an initial feature flag state resolver.
     *
     * Supports multiple definition patterns:
     * - Class-based features (auto-discovery): define(MyFeature::class)
     * - Lazy definition (fluent API): define('feature-name') returns LazilyResolvedFeature
     * - With resolver: define('feature-name', fn($scope) => true)
     * - With LazilyResolvedFeature: define('feature-name', $lazilyResolved)
     *
     * @param  string                                                           $feature  The feature name or class name
     * @param  null|(callable(mixed $scope): mixed)|LazilyResolvedFeature|mixed $resolver The resolver callback or value
     * @return null|LazilyResolvedFeature                                       Returns LazilyResolvedFeature for fluent API, otherwise null
     */
    public function define(string $feature, mixed $resolver = null): mixed
    {
        // If only one argument and it's a string that can be instantiated
        if (func_num_args() === 1 && class_exists($feature)) {
            // Feature class auto-discovery pattern
            $instance = $this->container->make($feature);
            assert(is_object($instance));

            /** @var string $featureName */
            $featureName = property_exists($instance, 'name') && is_string($instance->name) ? $instance->name : $feature;
            $this->nameMap[$featureName] = $feature;

            $this->driver->define($featureName, function ($scope) use ($feature, $instance) {
                if (method_exists($instance, 'resolve')) {
                    // PHPStan doesn't understand dynamic method_exists check
                    /** @phpstan-ignore-next-line callable.nonNativeMethod */
                    $resolver = $instance->resolve(...);
                } else {
                    assert(is_callable($instance));
                    $resolver = Closure::fromCallable($instance);
                }

                return $this->resolve($feature, $resolver, $scope);
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

            $this->driver->define($feature, function ($scope) use ($feature, $resolver) {
                /** @var callable $resolverCallable */
                $resolverCallable = $resolver->getResolver();

                return $this->resolve($feature, $resolverCallable, $scope);
            });

            return null;
        }

        // Standard definition with resolver
        $this->nameMap[$feature] = $resolver;

        $this->driver->define($feature, function ($scope) use ($feature, $resolver) {
            if (!$resolver instanceof Closure) {
                return $this->resolve($feature, fn (): mixed => $resolver, $scope);
            }

            return $this->resolve($feature, $resolver, $scope);
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
     * @throws RuntimeException If the underlying driver doesn't support listing stored features
     *
     * @return array<string> List of stored feature names
     */
    public function stored(): array
    {
        if (!$this->driver instanceof CanListStoredFeatures) {
            throw new RuntimeException(sprintf('The [%s] driver does not support listing stored features.', $this->name));
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
     * @param  array<int|string, mixed>|string  $features Feature names or feature-to-scopes mapping
     * @return array<string, array<int, mixed>> Nested array of feature values by feature and scope
     */
    public function getAll(array|string $features): array
    {
        $features = $this->normalizeFeaturesToLoad($features);

        if ($features->isEmpty()) {
            return [];
        }

        $results = $this->driver->getAll($features->all());

        // Cache the results
        $features->flatMap(fn ($scopes, $key) => Collection::make($scopes)
            ->zip($results[$key])
            ->map(fn ($scopes) => $scopes->push($key)))
            ->each(function ($value): void {
                assert(is_string($value[2]));
                $this->putInCache($value[2], $value[0], $value[1]);
            });

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
     * @param  array<int|string, mixed>|string  $features Feature names or feature-to-scopes mapping
     * @return array<string, array<int, mixed>> Nested array of feature values by feature and scope
     */
    public function getAllMissing(array|string $features): array
    {
        return $this->normalizeFeaturesToLoad($features)
            ->map(fn ($scopes, string $feature) => Collection::make($scopes)
                ->reject(fn ($scope): bool => $this->isCached($feature, $scope))
                ->all())
            ->reject(fn ($scopes): bool => $scopes === [])
            ->pipe(fn ($features): array => $this->getAll($features->all()));
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
     * @param  mixed  $scope   The scope to check (user, team, etc.)
     * @return mixed  The feature value (typically bool, but can be any type)
     */
    public function get(string $feature, mixed $scope): mixed
    {
        $feature = $this->resolveFeature($feature);
        $scope = $this->resolveScope($scope);

        // Check if feature is expired first (before checking cache)
        $lazilyResolved = $this->getLazilyResolvedFeature($feature);

        if ($lazilyResolved instanceof LazilyResolvedFeature && $lazilyResolved->isExpired()) {
            return false;
        }

        // Check dependencies (with recursion guard)
        if ($lazilyResolved instanceof LazilyResolvedFeature && $lazilyResolved->getRequires() !== []) {
            // Prevent infinite recursion from circular dependencies
            $stackKey = $feature.'|'.Feature::serializeScope($scope);

            if (in_array($stackKey, $this->dependencyCheckStack, true)) {
                // Circular dependency detected
                return false;
            }

            $this->dependencyCheckStack[] = $stackKey;

            try {
                foreach ($lazilyResolved->getRequires() as $requiredFeature) {
                    if ($this->get($requiredFeature, $scope) === false) {
                        return false;
                    }
                }
            } finally {
                // Always remove from stack
                array_pop($this->dependencyCheckStack);
            }
        }

        $item = $this->cache
            ->whereStrict('scope', Feature::serializeScope($scope))
            ->whereStrict('feature', $feature)
            ->first();

        if ($item !== null) {
            return $item['value'];
        }

        $value = $this->driver->get($feature, $scope);

        $this->putInCache($feature, $scope, $value);

        return $value;
    }

    /**
     * Set a feature flag's value.
     *
     * Updates both the underlying driver and the in-memory cache.
     *
     * @internal
     *
     * @param string $feature The feature name
     * @param mixed  $scope   The scope to set (user, team, etc.)
     * @param mixed  $value   The value to set
     */
    public function set(string $feature, mixed $scope, mixed $value): void
    {
        $feature = $this->resolveFeature($feature);
        $scope = $this->resolveScope($scope);

        $this->driver->set($feature, $scope, $value);

        $this->putInCache($feature, $scope, $value);
    }

    /**
     * Activate the feature for everyone.
     *
     * Sets the feature value globally across all scopes.
     *
     * @param array<string>|string $feature Feature name(s) to activate
     * @param mixed                $value   The value to set (defaults to true)
     */
    public function activateForEveryone(string|array $feature, mixed $value = true): void
    {
        Collection::wrap($feature)
            ->each(fn (string $name) => $this->setForAllScopes($name, $value));
    }

    /**
     * Deactivate the feature for everyone.
     *
     * Sets the feature to false globally across all scopes.
     *
     * @param array<string>|string $feature Feature name(s) to deactivate
     */
    public function deactivateForEveryone(string|array $feature): void
    {
        Collection::wrap($feature)
            ->each(fn (string $name) => $this->setForAllScopes($name, false));
    }

    /**
     * Set a feature flag's value for all scopes.
     *
     * Delegates to the underlying driver and clears cached values for this feature.
     *
     * @internal
     *
     * @param string $feature The feature name
     * @param mixed  $value   The value to set globally
     */
    public function setForAllScopes(string $feature, mixed $value): void
    {
        $feature = $this->resolveFeature($feature);

        $this->driver->setForAllScopes($feature, $value);

        $this->cache = $this->cache->reject(
            fn ($item): bool => $item['feature'] === $feature,
        );
    }

    /**
     * Delete a feature flag's value.
     *
     * Removes the feature value for a specific scope from storage and cache.
     *
     * @internal
     *
     * @param string $feature The feature name
     * @param mixed  $scope   The scope to delete
     */
    public function delete(string $feature, mixed $scope): void
    {
        $feature = $this->resolveFeature($feature);
        $scope = $this->resolveScope($scope);

        $this->driver->delete($feature, $scope);

        $this->removeFromCache($feature, $scope);
    }

    /**
     * Purge the given feature from storage.
     *
     * Removes all stored values for the specified features. If no features are specified,
     * purges all features from storage and clears the entire cache.
     *
     * @param null|array<string>|string $features Feature name(s) to purge, or null to purge all
     */
    public function purge(string|array|null $features = null): void
    {
        if ($features === null) {
            $this->driver->purge(null);
            $this->cache = new Collection();
        } else {
            Collection::wrap($features)
                ->map($this->resolveFeature(...))
                ->pipe(function ($features): void {
                    $this->driver->purge($features->all());

                    $this->cache->forget(
                        $this->cache->whereInStrict('feature', $features)->keys()->all(),
                    );
                });
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
    public function name(string $feature): string
    {
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
     * Define a feature group.
     *
     * Groups allow managing multiple related features together as a single unit.
     *
     * @param string        $name     The group name
     * @param array<string> $features List of feature names in this group
     */
    public function defineGroup(string $name, array $features): void
    {
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
        throw_unless(array_key_exists($name, $this->groups), InvalidArgumentException::class, sprintf('Feature group [%s] is not defined.', $name));

        return $this->groups[$name];
    }

    /**
     * Get all feature groups.
     *
     * @return array<string, array<string>>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * Activate all features in a group.
     *
     * Sets all features in the group to true across all scopes.
     *
     * @param string $name The group name
     */
    public function activateGroup(string $name): void
    {
        $features = $this->getGroup($name);

        foreach ($features as $feature) {
            $this->setForAllScopes($feature, true);
        }
    }

    /**
     * Deactivate all features in a group.
     *
     * Sets all features in the group to false across all scopes.
     *
     * @param string $name The group name
     */
    public function deactivateGroup(string $name): void
    {
        $features = $this->getGroup($name);

        foreach ($features as $feature) {
            $this->setForAllScopes($feature, false);
        }
    }

    /**
     * Activate all features in a group for everyone.
     *
     * Alias for activateGroup() - sets all features to true across all scopes.
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
     * Alias for deactivateGroup() - sets all features to false across all scopes.
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
     * Returns true only if every feature in the group is active for the default scope.
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

        return array_all($features, fn (string $feature): bool => $this->get($feature, $this->defaultScope()) !== false);
    }

    /**
     * Check if any features in a group are active.
     *
     * Returns true if at least one feature in the group is active for the default scope.
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

        return array_any($features, fn (string $feature): bool => $this->get($feature, $this->defaultScope()) !== false);
    }

    /**
     * Load groups from configuration.
     *
     * Reads the 'pole.groups' configuration and defines all groups found.
     * Each group must have a 'features' array key.
     */
    public function loadGroupsFromConfig(): void
    {
        /** @var \Illuminate\Config\Repository $configRepo */
        $configRepo = $this->container->make(Repository::class);

        /** @var array<string, mixed> */
        $config = $configRepo->get('pole.groups', []);

        foreach ($config as $name => $data) {
            if (is_array($data) && array_key_exists('features', $data)) {
                /** @var array<string> $features */
                $features = $data['features'];
                $this->defineGroup($name, $features);
            }
        }
    }

    /**
     * Check if a feature is expired.
     *
     * Features can have expiration dates set via LazilyResolvedFeature::expiresAt().
     * Expired features automatically return false when checked.
     *
     * @param  string $feature The feature name
     * @return bool   True if the feature is expired, false otherwise
     */
    public function isExpired(string $feature): bool
    {
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
    public function expiresAt(string $feature): ?CarbonInterface
    {
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
    public function isExpiringSoon(string $feature, int $days): bool
    {
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
     * Dependencies are set via LazilyResolvedFeature::requires().
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
     * Verifies that all required features are active for the default scope.
     *
     * @param  string $feature The feature name
     * @return bool   True if all dependencies are met (or no dependencies exist), false otherwise
     */
    public function dependenciesMet(string $feature): bool
    {
        $dependencies = $this->getDependencies($feature);

        if ($dependencies === []) {
            return true;
        }

        $scope = $this->defaultScope();

        return array_all($dependencies, fn (string $dependency): bool => $this->get($dependency, $scope) !== false);
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
     * variant using a hash of their scope.
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

        throw_if($total !== 100, InvalidArgumentException::class, 'Variant weights must sum to 100, got '.$total);

        $this->variants[$name] = $weights;
    }

    /**
     * Get the variant for a feature based on scope.
     *
     * Uses consistent hashing to assign the default scope to a variant.
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

        $scope = $this->defaultScope();

        // Check if variant is already stored for this scope
        $storedVariant = $this->driver->get($name, $scope);

        if ($storedVariant !== false && is_string($storedVariant)) {
            return $storedVariant;
        }

        // Calculate variant based on consistent hashing
        $variant = $this->calculateVariant($name, $scope, $this->variants[$name]);

        // Store the variant
        $this->driver->set($name, $scope, $variant);

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
     * Resolve the feature value and dispatch event.
     *
     * Handles expiration checks, dependency validation, and invokable objects
     * (like Laravel Lottery). Includes circular dependency detection.
     *
     * @param  string   $feature  The feature name
     * @param  callable $resolver The resolver callback
     * @param  mixed    $scope    The scope to resolve for
     * @return mixed    The resolved feature value
     */
    private function resolve(string $feature, callable $resolver, mixed $scope): mixed
    {
        // Check if feature is expired
        $lazilyResolved = $this->getLazilyResolvedFeature($feature);

        if ($lazilyResolved instanceof LazilyResolvedFeature && $lazilyResolved->isExpired()) {
            return false;
        }

        // Check dependencies (with recursion guard)
        if ($lazilyResolved instanceof LazilyResolvedFeature && $lazilyResolved->getRequires() !== []) {
            // Prevent infinite recursion from circular dependencies
            $stackKey = $feature.'|'.Feature::serializeScope($scope);

            if (in_array($stackKey, $this->dependencyCheckStack, true)) {
                // Circular dependency detected
                return false;
            }

            $this->dependencyCheckStack[] = $stackKey;

            try {
                foreach ($lazilyResolved->getRequires() as $requiredFeature) {
                    if ($this->get($requiredFeature, $scope) === false) {
                        return false;
                    }
                }
            } finally {
                // Always remove from stack
                array_pop($this->dependencyCheckStack);
            }
        }

        $value = $resolver($scope);

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
     * feature names to their scopes.
     *
     * @param  array<int|string, mixed>|string       $features Feature names or feature-to-scopes mapping
     * @return Collection<string, array<int, mixed>> Normalized collection of features to scopes
     */
    private function normalizeFeaturesToLoad(array|string $features): Collection
    {
        return Collection::wrap($features)
            ->mapWithKeys(function ($value, $key): array {
                if (is_int($key)) {
                    assert(is_string($value) || is_int($value));

                    return [$value => Collection::make([$this->defaultScope()])];
                }

                return [$key => Collection::wrap($value)];
            })
            ->mapWithKeys(function ($scopes, int|string $feature): array {
                return [$this->resolveFeature((string) $feature) => $scopes];
            })
            ->map(
                fn ($scopes) => $scopes->map(fn ($scope): mixed => $this->resolveScope($scope))->all(),
            );
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
     * Resolve the scope.
     *
     * Converts FeatureScopeable objects to their identifier representation.
     *
     * @param  mixed $scope The scope (can be a model, ID, or FeatureScopeable)
     * @return mixed The resolved scope identifier
     */
    private function resolveScope(mixed $scope): mixed
    {
        return $scope instanceof FeatureScopeable
            ? $scope->toFeatureIdentifier($this->name)
            : $scope;
    }

    /**
     * Determine if a feature's value is in the cache for the given scope.
     *
     * @param  string $feature The feature name
     * @param  mixed  $scope   The scope to check
     * @return bool   True if cached, false otherwise
     */
    private function isCached(string $feature, mixed $scope): bool
    {
        $scope = Feature::serializeScope($scope);

        return $this->cache->search(
            fn ($item): bool => $item['feature'] === $feature && $item['scope'] === $scope,
        ) !== false;
    }

    /**
     * Put the given feature's value into the cache.
     *
     * Updates existing cache entries or adds new ones.
     *
     * @param string $feature The feature name
     * @param mixed  $scope   The scope
     * @param mixed  $value   The value to cache
     */
    private function putInCache(string $feature, mixed $scope, mixed $value): void
    {
        $scope = Feature::serializeScope($scope);

        $position = $this->cache->search(
            fn ($item): bool => $item['feature'] === $feature && $item['scope'] === $scope,
        );

        if ($position === false) {
            $this->cache[] = ['feature' => $feature, 'scope' => $scope, 'value' => $value];
        } else {
            $this->cache[$position] = ['feature' => $feature, 'scope' => $scope, 'value' => $value];
        }
    }

    /**
     * Remove the given feature's value from the cache.
     *
     * @param string $feature The feature name
     * @param mixed  $scope   The scope to remove
     */
    private function removeFromCache(string $feature, mixed $scope): void
    {
        $scope = Feature::serializeScope($scope);

        $position = $this->cache->search(
            fn ($item): bool => $item['feature'] === $feature && $item['scope'] === $scope,
        );

        if ($position !== false) {
            unset($this->cache[$position]);
        }
    }

    /**
     * Retrieve the default scope.
     *
     * Invokes the default scope resolver to get the current scope context.
     *
     * @return mixed The default scope (typically a user ID, model, or null)
     */
    private function defaultScope(): mixed
    {
        return ($this->defaultScopeResolver)();
    }

    /**
     * Calculate which variant to assign based on consistent hashing.
     *
     * Uses CRC32 hashing to deterministically assign a scope to a variant bucket
     * based on the configured weight distribution. The same feature+scope combination
     * will always produce the same variant.
     *
     * @param  string             $feature The feature name
     * @param  mixed              $scope   The scope to calculate for
     * @param  array<string, int> $weights The variant weights
     * @return string             The assigned variant name
     */
    private function calculateVariant(string $feature, mixed $scope, array $weights): string
    {
        // Create a hash from feature name and scope
        $scopeString = Feature::serializeScope($scope);
        $hashInput = $feature.'|'.$scopeString;
        $hash = crc32($hashInput);

        // Normalize to 0-99 range
        $bucket = abs($hash) % 100;

        // Find which variant this bucket falls into
        $cumulative = 0;

        foreach ($weights as $variant => $weight) {
            $cumulative += $weight;

            if ($bucket < $cumulative) {
                return $variant;
            }
        }

        // Fallback to last variant (shouldn't reach here if weights sum to 100)
        $lastKey = array_key_last($weights);
        assert(is_string($lastKey));

        return $lastKey;
    }
}
