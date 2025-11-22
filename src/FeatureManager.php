<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl;

use BackedEnum;
use Cline\Toggl\Conductors\ActivationConductor;
use Cline\Toggl\Conductors\AuditConductor;
use Cline\Toggl\Conductors\BatchActivationConductor;
use Cline\Toggl\Conductors\BulkValueConductor;
use Cline\Toggl\Conductors\CascadeConductor;
use Cline\Toggl\Conductors\CleanupConductor;
use Cline\Toggl\Conductors\ComparisonConductor;
use Cline\Toggl\Conductors\ContextConductor;
use Cline\Toggl\Conductors\CopyConductor;
use Cline\Toggl\Conductors\DeactivationConductor;
use Cline\Toggl\Conductors\DependencyConductor;
use Cline\Toggl\Conductors\FluentDefinitionConductor;
use Cline\Toggl\Conductors\GroupActivationConductor;
use Cline\Toggl\Conductors\GroupDeactivationConductor;
use Cline\Toggl\Conductors\InheritConductor;
use Cline\Toggl\Conductors\LazyEvaluationConductor;
use Cline\Toggl\Conductors\MetadataConductor;
use Cline\Toggl\Conductors\ObserveConductor;
use Cline\Toggl\Conductors\PermissionStyleConductor;
use Cline\Toggl\Conductors\PipelineConductor;
use Cline\Toggl\Conductors\QueryConductor;
use Cline\Toggl\Conductors\RolloutConductor;
use Cline\Toggl\Conductors\ScheduleConductor;
use Cline\Toggl\Conductors\SnapshotConductor;
use Cline\Toggl\Conductors\StrategyConductor;
use Cline\Toggl\Conductors\SyncConductor;
use Cline\Toggl\Conductors\TestingConductor;
use Cline\Toggl\Conductors\TransactionConductor;
use Cline\Toggl\Conductors\VariantConductor;
use Cline\Toggl\Contracts\Context;
use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Contracts\FeatureGroupMembershipRepository;
use Cline\Toggl\Contracts\GroupRepository;
use Cline\Toggl\Contracts\Serializable;
use Cline\Toggl\Drivers\ArrayDriver;
use Cline\Toggl\Drivers\CacheDriver;
use Cline\Toggl\Drivers\DatabaseDriver;
use Cline\Toggl\Drivers\Decorator;
use Cline\Toggl\Drivers\GateDriver;
use Cline\Toggl\Exceptions\CannotSerializeContextException;
use Cline\Toggl\Exceptions\InvalidVariantWeightsException;
use Cline\Toggl\Exceptions\UndefinedFeatureStoreException;
use Cline\Toggl\Exceptions\UnsupportedDriverException;
use Cline\Toggl\GroupRepositories\ArrayFeatureGroupMembershipRepository;
use Cline\Toggl\GroupRepositories\ArrayGroupRepository;
use Cline\Toggl\GroupRepositories\DatabaseFeatureGroupMembershipRepository;
use Cline\Toggl\GroupRepositories\DatabaseGroupRepository;
use Cline\Toggl\Support\BatchEvaluationResult;
use Cline\Toggl\Support\EvaluationEntry;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

use function abs;
use function array_key_exists;
use function array_key_last;
use function assert;
use function auth;
use function crc32;
use function is_array;
use function is_int;
use function is_numeric;
use function is_object;
use function is_string;
use function md5;
use function method_exists;
use function serialize;
use function spl_object_hash;
use function throw_if;
use function ucfirst;

/**
 * Central manager for feature flag stores and driver instances.
 *
 * This class manages multiple feature flag storage drivers, handles context serialization,
 * and provides a unified interface for working with feature flags across the application.
 *
 * @mixin Decorator
 *
 * @author Brian Faust <brian@cline.sh>
 */
#[Singleton()]
final class FeatureManager
{
    /**
     * The array of resolved feature flag stores.
     *
     * Caches instantiated Decorator instances by store name to avoid recreating
     * them on each access. Each store corresponds to a configured driver in toggl.stores.
     *
     * @var array<string, Decorator>
     */
    private array $stores = [];

    /**
     * The registered custom driver creators.
     *
     * Maps driver type names to closure factories for creating custom driver implementations.
     * Allows extending the feature flag system with custom storage backends beyond array/database.
     *
     * @var array<string, Closure>
     */
    private array $customCreators = [];

    /**
     * The default context resolver.
     *
     * Optional callback that determines the current context when none is explicitly provided.
     * Receives the driver name and returns a context value (typically the authenticated user).
     *
     * @var null|(callable(string): mixed)
     */
    private $defaultContextResolver;

    /**
     * Indicates if the Eloquent "morph map" should be used when serializing.
     *
     * When true, uses model morph aliases instead of full class names for context serialization.
     * Useful when you want stable identifiers that don't change if models are refactored.
     */
    private bool $useMorphMap = false;

    /**
     * The context manager instance.
     *
     * Manages feature context and context information for advanced scoping scenarios.
     * Lazily instantiated on first access to context manager.
     */
    private ?Context $contextManager = null;

    /**
     * Create a new feature manager instance.
     *
     * @param Container $container the Laravel service container used for dependency injection,
     *                             resolving driver instances, and creating feature class instances
     */
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Dynamically call the default store instance.
     *
     * @param  string            $method     The method name to call
     * @param  array<int, mixed> $parameters The method parameters
     * @return mixed             The result of the method call
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->store()->{$method}(...$parameters);
    }

    /**
     * Get a feature flag store instance.
     *
     * @param null|string $store The store name, or null to use the default
     *
     * @throws InvalidArgumentException If the store is not defined
     *
     * @return Decorator The store decorator instance
     */
    public function store(?string $store = null): Decorator
    {
        return $this->driver($store);
    }

    /**
     * Get a feature flag store instance by name.
     *
     * @param null|string $name The driver name, or null to use the default
     *
     * @throws InvalidArgumentException If the driver is not defined or supported
     *
     * @return Decorator The driver decorator instance
     */
    public function driver(?string $name = null): Decorator
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->stores[$name] = $this->get($name);
    }

    /**
     * Create an instance of the array driver.
     *
     * @return ArrayDriver The created array driver instance
     */
    public function createArrayDriver(): ArrayDriver
    {
        return new ArrayDriver(
            $this->container->make(Dispatcher::class),
            [],
        );
    }

    /**
     * Create an instance of the database driver.
     *
     * @param  array<string, mixed> $config The driver configuration
     * @param  string               $name   The driver name
     * @return DatabaseDriver       The created database driver instance
     */
    public function createDatabaseDriver(array $config, string $name): DatabaseDriver
    {
        return $this->container->make(DatabaseDriver::class, [
            'name' => $name,
            'featureStateResolvers' => [],
        ]);
    }

    /**
     * Create an instance of the cache driver.
     *
     * @param  array<string, mixed> $config The driver configuration
     * @param  string               $name   The driver name
     * @return CacheDriver          The created cache driver instance
     */
    public function createCacheDriver(array $config, string $name): CacheDriver
    {
        return $this->container->make(CacheDriver::class, [
            'cache' => $this->container->make(CacheRepository::class),
            'name' => $name,
            'featureStateResolvers' => [],
        ]);
    }

    /**
     * Create an instance of the gate driver.
     *
     * @param  array<string, mixed> $config The driver configuration
     * @param  string               $name   The driver name
     * @return GateDriver           The created gate driver instance
     */
    public function createGateDriver(array $config, string $name): GateDriver
    {
        return $this->container->make(GateDriver::class, [
            'gate' => $this->container->make(Gate::class),
            'name' => $name,
            'featureStateResolvers' => [],
        ]);
    }

    /**
     * Serialize the given context for storage.
     *
     * Converts various context types (models, strings, numbers, null) into a string
     * representation that can be stored and compared consistently.
     *
     * @param mixed $context The context to serialize
     *
     * @throws CannotSerializeContextException If the context cannot be serialized
     *
     * @return string The serialized context identifier
     */
    public function serializeContext(mixed $context): string
    {
        if ($context instanceof Model) {
            $key = $context->getKey();
            assert(is_string($key) || is_int($key));
            $keyString = (string) $key;

            return $this->useMorphMap
                ? $context->getMorphClass().'|'.$keyString
                : $context::class.'|'.$keyString;
        }

        return match (true) {
            $context instanceof Serializable => $context->serialize(),
            $context === null => '__laravel_null',
            is_string($context) => $context,
            is_numeric($context) => (string) $context,
            is_object($context) => spl_object_hash($context),
            is_array($context) => md5(serialize($context)),
            default => throw CannotSerializeContextException::notSerializable(),
        };
    }

    /**
     * Calculate which variant to assign based on consistent hashing.
     *
     * Uses CRC32 hashing to deterministically assign a context to a variant bucket
     * based on the configured weight distribution. The same feature+context combination
     * will always produce the same variant, ensuring users consistently see the same
     * experience across multiple requests.
     *
     * @param  string             $feature The feature name used in the hash calculation
     * @param  mixed              $context The context to calculate for (user, team, etc.)
     * @param  array<string, int> $weights Map of variant names to their percentage weights (must sum to 100)
     * @return string             The assigned variant name based on consistent hashing
     */
    public function calculateVariant(string $feature, mixed $context, array $weights): string
    {
        $contextString = $this->serializeContext($context);
        $hashInput = $feature.'|'.$contextString;
        $hash = crc32($hashInput);

        $bucket = abs($hash) % 100;

        $cumulative = 0;

        foreach ($weights as $variant => $weight) {
            $cumulative += $weight;

            if ($bucket < $cumulative) {
                return $variant;
            }
        }

        $lastKey = array_key_last($weights);

        throw_if($lastKey === null, InvalidVariantWeightsException::cannotBeEmpty());

        return $lastKey;
    }

    /**
     * Specify that the Eloquent morph map should be used when serializing.
     *
     * @param  bool   $value Whether to use the morph map
     * @return static Fluent interface for method chaining
     */
    public function useMorphMap(bool $value = true): static
    {
        $this->useMorphMap = $value;

        return $this;
    }

    /**
     * Flush the driver caches.
     *
     * Clears cached feature flag values across all registered drivers.
     */
    public function flushCache(): void
    {
        foreach ($this->stores as $driver) {
            $driver->flushCache();
        }
    }

    /**
     * Set the default context resolver.
     *
     * @param callable(string): mixed $resolver The resolver callback that receives the driver
     *                                          name and returns the current context
     */
    public function resolveContextUsing(callable $resolver): void
    {
        $this->defaultContextResolver = $resolver;
    }

    /**
     * Get the default store name.
     *
     * @return string The default driver name
     */
    public function getDefaultDriver(): string
    {
        $default = Config::get('toggl.default', 'array');
        assert(is_string($default));

        return $default;
    }

    /**
     * Set the default store name.
     *
     * @param string $name The default driver name
     */
    public function setDefaultDriver(string $name): void
    {
        Config::set(['toggl.default' => $name]);
    }

    /**
     * Unset the given store instances.
     *
     * @param  null|array<int, string>|string $name The driver name(s) to forget, or null for default
     * @return static                         Fluent interface for method chaining
     */
    public function forgetDriver(array|string|null $name = null): static
    {
        $name ??= $this->getDefaultDriver();

        foreach ((array) $name as $storeName) {
            if (array_key_exists($storeName, $this->stores)) {
                unset($this->stores[$storeName]);
            }
        }

        return $this;
    }

    /**
     * Forget all of the resolved store instances.
     *
     * @return static Fluent interface for method chaining
     */
    public function forgetDrivers(): static
    {
        $this->stores = [];

        return $this;
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string  $driver   The driver name
     * @param  Closure $callback The creator callback
     * @return static  Fluent interface for method chaining
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Set the container instance used by the manager.
     *
     * @param  Container $container The container instance
     * @return static    Fluent interface for method chaining
     */
    public function setContainer(Container $container): static
    {
        foreach ($this->stores as $store) {
            $store->setContainer($container);
        }

        return $this;
    }

    /**
     * Get or create the context manager instance.
     *
     * @return Context The context manager
     */
    public function context(): Context
    {
        if (!$this->contextManager instanceof Context) {
            $this->contextManager = new ContextManager();
            $this->contextManager->setFeatureManager($this);
        }

        return $this->contextManager;
    }

    /**
     * Set a custom context manager implementation.
     *
     * @param  Context $context The context manager instance
     * @return static  Fluent interface for method chaining
     */
    public function setContextManager(Context $context): static
    {
        $this->contextManager = $context;

        return $this;
    }

    /**
     * Create a feature activation conductor for feature-first pattern.
     *
     * Use withValue() to set custom values:
     * Toggl::activate('theme')->withValue('dark')->for($user)
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $features The feature(s) to activate
     * @return ActivationConductor                        The conductor for fluent activation operations
     */
    public function activate(string|BackedEnum|array $features): ActivationConductor
    {
        return new ActivationConductor($this, $features);
    }

    /**
     * Create a feature deactivation conductor for feature-first pattern.
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $features The feature(s) to deactivate
     * @return DeactivationConductor                      The conductor for fluent deactivation operations
     */
    public function deactivate(string|BackedEnum|array $features): DeactivationConductor
    {
        return new DeactivationConductor($this, $features);
    }

    /**
     * Create a group activation conductor for group-first pattern.
     *
     * @param  string                   $groupName The feature group name
     * @return GroupActivationConductor The conductor for fluent group activation
     */
    public function activateGroupConductor(string $groupName): GroupActivationConductor
    {
        return new GroupActivationConductor($this, $groupName);
    }

    /**
     * Create a query conductor for checking feature status.
     *
     * @param  BackedEnum|string $feature The feature to query
     * @return QueryConductor    The conductor for conditional execution based on feature state
     */
    public function when(string|BackedEnum $feature): QueryConductor
    {
        return new QueryConductor($this, $feature);
    }

    /**
     * Create a sync conductor for replacing all features/groups for a context.
     *
     * @param  mixed         $context The context to sync for
     * @return SyncConductor The conductor for atomic feature/group replacement
     */
    public function sync(mixed $context): SyncConductor
    {
        return new SyncConductor($this, $context);
    }

    /**
     * Create a context conductor for performing multiple operations on a context.
     *
     * Enables chaining multiple operations on the same context:
     * Toggl::within($team)->activate('feat-1')->activate('feat-2')->deactivate('old-feat')
     *
     * @param  mixed            $context The context
     * @return ContextConductor The conductor for chaining context-scoped operations
     */
    public function within(mixed $context): ContextConductor
    {
        return new ContextConductor($this, $context);
    }

    /**
     * Create a bulk value conductor for setting multiple feature/value pairs at once.
     *
     * Enables setting multiple features with different values in one operation:
     * Toggl::bulk(['theme' => 'dark', 'language' => 'es', 'timezone' => 'UTC'])->for($user)
     *
     * @param  array<string, mixed> $values Feature name => value pairs
     * @return BulkValueConductor   The conductor for bulk feature value assignment
     */
    public function bulk(array $values): BulkValueConductor
    {
        return new BulkValueConductor($this, $values);
    }

    /**
     * Create a variant conductor for A/B testing and feature variants.
     *
     * @param  string           $feature The variant feature name
     * @return VariantConductor The conductor for managing feature variants
     */
    public function variant(string $feature): VariantConductor
    {
        return new VariantConductor($this, $feature);
    }

    /**
     * Create a batch activation conductor for Cartesian product operations.
     *
     * Enables pattern: Toggl::batch()->activate(['f1','f2'])->for([$u1,$u2])
     * Executes all features × all contexts.
     *
     * @return BatchActivationConductor The conductor for batch operations
     */
    public function batch(): BatchActivationConductor
    {
        return new BatchActivationConductor($this);
    }

    /**
     * Create a lazy evaluation conductor for batch feature checks.
     *
     * Enables pattern: Toggl::lazy('feature')->for($context) to create
     * LazyEvaluation instances that can be batch-evaluated together.
     *
     * ```php
     * $results = Toggl::evaluate([
     *     Toggl::lazy('premium')->for($user1),
     *     Toggl::lazy('analytics')->for($user2),
     * ]);
     * ```
     *
     * @param  BackedEnum|string       $feature Feature to evaluate lazily
     * @return LazyEvaluationConductor The conductor for lazy feature evaluation
     */
    public function lazy(string|BackedEnum $feature): LazyEvaluationConductor
    {
        return new LazyEvaluationConductor($feature);
    }

    /**
     * Evaluate multiple feature-context pairs in batch.
     *
     * Accepts an array of LazyEvaluation instances and returns a BatchEvaluationResult
     * with rich analysis capabilities (all, any, none, filtering by feature/context).
     *
     * ```php
     * $results = Toggl::evaluate([
     *     Toggl::lazy('premium')->for($user1),
     *     Toggl::lazy('premium')->for($user2),
     *     Toggl::lazy('analytics')->for($user1),
     * ]);
     *
     * $results->all();                    // true if all are active
     * $results->any();                    // true if any is active
     * $results->forFeature('premium');    // filter by feature
     * $results->forContext($user1);       // filter by context
     * ```
     *
     * @param  array<Support\LazyEvaluation> $evaluations Array of lazy evaluations
     * @return BatchEvaluationResult         Result container with analysis methods
     */
    public function evaluate(array $evaluations): BatchEvaluationResult
    {
        $serializer = fn (mixed $context): string => $this->serializeContext($context);
        $entries = [];

        foreach ($evaluations as $evaluation) {
            $key = $evaluation->getKey($serializer);
            $contextKey = $serializer($evaluation->context);
            $value = $this->for($evaluation->context)->value($evaluation->feature);

            $entries[$key] = new EvaluationEntry(
                feature: $evaluation->feature,
                contextKey: $contextKey,
                context: $evaluation->context,
                value: $value,
            );
        }

        return new BatchEvaluationResult($entries, $serializer);
    }

    /**
     * Create a permission-style conductor for allowing access.
     *
     * Warden-inspired pattern: Toggl::allow($user)->to('premium-dashboard')
     *
     * @param  array<mixed>|mixed       $contexts Context(s) to grant access
     * @return PermissionStyleConductor The conductor for permission-style access grants
     */
    public function allow(mixed $contexts): PermissionStyleConductor
    {
        return new PermissionStyleConductor($this, $contexts, true);
    }

    /**
     * Create a permission-style conductor for denying access.
     *
     * Warden-inspired pattern: Toggl::deny($user)->to('beta-features')
     *
     * @param  array<mixed>|mixed       $contexts Context(s) to revoke access
     * @return PermissionStyleConductor The conductor for permission-style access revocation
     */
    public function deny(mixed $contexts): PermissionStyleConductor
    {
        return new PermissionStyleConductor($this, $contexts, false);
    }

    /**
     * Create a strategy conductor for strategy-based activations.
     *
     * Enables patterns: Toggl::strategy('rollout')->percentage(25)->for($user)
     *
     * @param  BackedEnum|string $feature Feature to apply strategy to
     * @return StrategyConductor The conductor for strategy-based feature management
     */
    public function strategy(string|BackedEnum $feature): StrategyConductor
    {
        return new StrategyConductor($this, $feature);
    }

    /**
     * Create a dependency conductor for prerequisite management.
     *
     * Enables pattern: Toggl::require('basic')->before('advanced')->for($user)
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $prerequisites Required feature(s)
     * @return DependencyConductor                        The conductor for managing feature dependencies
     */
    public function require(string|BackedEnum|array $prerequisites): DependencyConductor
    {
        return new DependencyConductor($this, $prerequisites);
    }

    /**
     * Create a copy conductor for copying features between contexts.
     *
     * Enables pattern: Toggl::from($source)->copyTo($target)
     *
     * @param  mixed         $sourceContext Source context to copy from
     * @return CopyConductor The conductor for copying features between contexts
     */
    public function from(mixed $sourceContext): CopyConductor
    {
        return new CopyConductor($this, $sourceContext);
    }

    /**
     * Create an inherit conductor for context scope inheritance.
     *
     * Enables pattern: Toggl::for($user)->inherit($team)
     *
     * @param  mixed            $childContext Child context that will inherit features
     * @return InheritConductor The conductor for context inheritance
     */
    public function inherit(mixed $childContext): InheritConductor
    {
        return new InheritConductor($this, $childContext);
    }

    /**
     * Create an observe conductor for watching feature changes.
     *
     * Enables pattern: Toggl::observe('premium')->onChange(fn() => ...)->for($user)
     *
     * @param  BackedEnum|string $feature Feature to observe
     * @return ObserveConductor  The conductor for observing feature changes
     */
    public function observe(string|BackedEnum $feature): ObserveConductor
    {
        return new ObserveConductor($this, $feature);
    }

    /**
     * Create a comparison conductor for comparing feature states between contexts.
     *
     * @param  mixed               $context1 First context to compare
     * @param  null|mixed          $context2 Optional second context (use against() if not provided)
     * @return ComparisonConductor The conductor for comparing feature states
     */
    public function compare(mixed $context1, mixed $context2 = null): ComparisonConductor
    {
        return new ComparisonConductor($this, $context1, $context2);
    }

    /**
     * Create a cascade conductor for cascading feature activation/deactivation.
     *
     * Enables pattern: Toggl::cascade('premium')->activating(['analytics', 'export'])->for($user)
     *
     * @param  BackedEnum|string $feature Primary feature to cascade from
     * @return CascadeConductor  The conductor for cascading feature operations
     */
    public function cascade(string|BackedEnum $feature): CascadeConductor
    {
        return new CascadeConductor($this, $feature);
    }

    /**
     * Create a fluent definition conductor for defining features.
     *
     * Enables pattern: Toggl::definition('premium')->resolvedBy($resolver)->register()
     *
     * @param  BackedEnum|string         $feature Feature name to define
     * @return FluentDefinitionConductor The conductor for fluent feature definition
     */
    public function definition(string|BackedEnum $feature): FluentDefinitionConductor
    {
        return new FluentDefinitionConductor($this, $feature);
    }

    /**
     * Create a testing conductor for feature fakes/test doubles.
     *
     * Enables pattern: Toggl::testing('premium')->fake(true)->for($user)
     *
     * @param  null|BackedEnum|string $feature Feature name to fake (null for batch)
     * @return TestingConductor       The conductor for testing and feature fakes
     */
    public function testing(string|BackedEnum|null $feature = null): TestingConductor
    {
        return new TestingConductor($this, $feature);
    }

    /**
     * Create a pipeline conductor for chaining operations.
     *
     * Enables pattern: Toggl::pipeline()->activate($features)->deactivate($others)->for($user)
     *
     * @return PipelineConductor The conductor for chaining multiple operations
     */
    public function pipeline(): PipelineConductor
    {
        return new PipelineConductor($this);
    }

    /**
     * Create a transaction conductor for atomic operations.
     *
     * @return TransactionConductor The conductor for atomic feature operations
     */
    public function transaction(): TransactionConductor
    {
        return new TransactionConductor($this);
    }

    /**
     * Create a metadata conductor for managing feature metadata.
     *
     * @param  BackedEnum|string $feature Feature name
     * @return MetadataConductor The conductor for managing feature metadata
     */
    public function metadata(string|BackedEnum $feature): MetadataConductor
    {
        return new MetadataConductor($this, $feature);
    }

    /**
     * Create an audit conductor for tracking feature state changes.
     *
     * @param  BackedEnum|string $feature Feature name
     * @return AuditConductor    The conductor for auditing feature changes
     */
    public function audit(string|BackedEnum $feature): AuditConductor
    {
        return new AuditConductor($this, $feature);
    }

    /**
     * Create a snapshot conductor for capturing and restoring feature states.
     *
     * @return SnapshotConductor The conductor for snapshot operations
     */
    public function snapshot(): SnapshotConductor
    {
        return new SnapshotConductor();
    }

    /**
     * Create a cleanup conductor for removing stale data.
     *
     * @return CleanupConductor The conductor for cleanup operations
     */
    public function cleanup(): CleanupConductor
    {
        return new CleanupConductor($this);
    }

    /**
     * Create a schedule conductor for time-based activation.
     *
     * @param  BackedEnum|string $feature Feature name
     * @return ScheduleConductor The conductor for scheduled feature activation
     */
    public function schedule(string|BackedEnum $feature): ScheduleConductor
    {
        return new ScheduleConductor($this, $feature);
    }

    /**
     * Create a rollout conductor for gradual feature rollouts.
     *
     * @param  BackedEnum|string $feature Feature name
     * @return RolloutConductor  The conductor for gradual feature rollouts
     */
    public function rollout(string|BackedEnum $feature): RolloutConductor
    {
        return new RolloutConductor($this, $feature);
    }

    /**
     * Create a dependency conductor for managing feature prerequisites.
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $prerequisites Required feature(s)
     * @return DependencyConductor                        The conductor for dependency management
     */
    public function dependency(string|BackedEnum|array $prerequisites): DependencyConductor
    {
        return new DependencyConductor($this, $prerequisites);
    }

    /**
     * Create a group deactivation conductor for group-first pattern.
     *
     * @param  string                     $groupName The feature group name
     * @return GroupDeactivationConductor The conductor for fluent group deactivation
     */
    public function deactivateGroupConductor(string $groupName): GroupDeactivationConductor
    {
        return new GroupDeactivationConductor($this, $groupName);
    }

    /**
     * Attempt to get the store from the local cache.
     *
     * @param  string    $name The store name
     * @return Decorator The store decorator instance
     */
    private function get(string $name): Decorator
    {
        return $this->stores[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given store.
     *
     * Creates a new driver instance based on configuration and wraps it in a decorator.
     *
     * @param string $name The store name
     *
     * @throws InvalidArgumentException If the store is not defined or the driver is not supported
     *
     * @return Decorator The resolved decorator instance
     */
    private function resolve(string $name): Decorator
    {
        $config = $this->getConfig($name);

        throw_if($config === null, UndefinedFeatureStoreException::forName($name));

        assert(is_string($config['driver']));

        if (array_key_exists($config['driver'], $this->customCreators)) {
            $driver = $this->callCustomCreator($config);
        } else {
            $driverMethod = 'create'.ucfirst($config['driver']).'Driver';

            if (method_exists($this, $driverMethod)) {
                $driver = $this->{$driverMethod}($config, $name);
            } else {
                throw UnsupportedDriverException::forDriver($config['driver']);
            }
        }

        assert($driver instanceof Driver);

        return new Decorator(
            $name,
            $driver,
            $this->defaultContextResolver($name),
            $this->container,
            $this->createGroupRepository($config),
            $this->createFeatureGroupMembershipRepository($config),
        );
    }

    /**
     * Create the appropriate group repository based on driver configuration.
     *
     * @param  array<string, mixed> $config The driver configuration
     * @return GroupRepository      The group repository instance
     */
    private function createGroupRepository(array $config): GroupRepository
    {
        $storageDriver = Config::get('toggl.group_storage', $config['driver'] ?? 'array');

        return match ($storageDriver) {
            'database' => $this->container->make(DatabaseGroupRepository::class),
            default => new ArrayGroupRepository(),
        };
    }

    /**
     * Create the appropriate feature group membership repository based on driver configuration.
     *
     * @param  array<string, mixed>             $config The driver configuration
     * @return FeatureGroupMembershipRepository The membership repository instance
     */
    private function createFeatureGroupMembershipRepository(array $config): FeatureGroupMembershipRepository
    {
        $storageDriver = Config::get('toggl.group_storage', $config['driver'] ?? 'array');

        return match ($storageDriver) {
            'database' => $this->container->make(DatabaseFeatureGroupMembershipRepository::class),
            default => new ArrayFeatureGroupMembershipRepository(),
        };
    }

    /**
     * Call a custom driver creator.
     *
     * @param  array<string, mixed> $config The driver configuration
     * @return mixed                The created driver instance
     */
    private function callCustomCreator(array $config): mixed
    {
        assert(is_string($config['driver']));

        return $this->customCreators[$config['driver']]($this->container, $config);
    }

    /**
     * The default context resolver.
     *
     * Returns a closure that resolves the current context, either using the custom
     * resolver or falling back to the authenticated user.
     *
     * @param  string  $driver The driver name
     * @return Closure The context resolver closure
     */
    private function defaultContextResolver(string $driver): Closure
    {
        return fn () => $this->defaultContextResolver
            ? ($this->defaultContextResolver)($driver)
            : auth()->guard()->user();
    }

    /**
     * Get the feature flag store configuration.
     *
     * @param  string                    $name The store name
     * @return null|array<string, mixed> The store configuration, or null if not found
     */
    private function getConfig(string $name): ?array
    {
        /** @var null|array<string, mixed> $config */
        $config = Config::get('toggl.stores.'.$name);

        return is_array($config) ? $config : null;
    }
}
