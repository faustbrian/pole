<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Drivers;

use Cline\Toggl\Contracts\CanListStoredFeatures;
use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Contracts\HasFlushableCache;
use Cline\Toggl\Events\UnknownFeatureResolved;
use Cline\Toggl\Support\FeatureScope;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Config;
use stdClass;

use function array_key_exists;
use function array_keys;
use function array_unique;
use function is_callable;

/**
 * In-memory array-based feature flag driver.
 *
 * Stores feature flags in memory for the duration of the request. This driver is
 * fast and suitable for testing, development, or when persistence is not required.
 * All data is lost at the end of the request lifecycle.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ArrayDriver implements CanListStoredFeatures, Driver, HasFlushableCache
{
    /**
     * The resolved feature states.
     *
     * Maps feature names to context identifiers to their resolved values.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $resolvedFeatureStates = [];

    /**
     * Hierarchical feature scopes.
     *
     * Maps feature names to their scope scope configurations.
     *
     * @var array<string, array{context: string, scope: FeatureScope, value: mixed}>
     */
    private array $scopes = [];

    /**
     * The sentinel value for unknown features.
     *
     * Used to distinguish between features that resolve to false/null
     * and features that haven't been defined at all.
     */
    private readonly stdClass $unknownFeatureValue;

    /**
     * Create a new driver instance.
     *
     * @param Dispatcher                                              $events                Laravel event dispatcher instance used to fire UnknownFeatureResolved
     *                                                                                       events when undefined features are accessed, enabling monitoring and logging
     *                                                                                       of feature flag usage patterns and detection of missing feature definitions
     * @param array<string, (callable(TogglContext $context): mixed)> $featureStateResolvers Map of feature names to their resolver callbacks or static values.
     *                                                                                       Resolvers accept a context parameter and return the feature's value for that context.
     *                                                                                       Static values are automatically wrapped in closures during definition,
     *                                                                                       providing a consistent callable interface for all feature resolution
     */
    public function __construct(
        private readonly Dispatcher $events,
        private array $featureStateResolvers,
    ) {
        $this->unknownFeatureValue = new stdClass();
    }

    /**
     * Define a feature flag with its initial state resolver.
     *
     * Registers a feature with either a static value or a callback that computes
     * the feature's value based on context. Static values are automatically wrapped
     * in closures for consistent resolution behavior.
     *
     * @param  string                                         $feature  The feature flag name to define
     * @param  (callable(TogglContext $context): mixed)|mixed $resolver Static value or callback that returns the feature's value
     * @return mixed                                          Always returns null
     */
    public function define(string $feature, mixed $resolver = null): mixed
    {
        $this->featureStateResolvers[$feature] = is_callable($resolver)
            ? $resolver
            : fn (): mixed => $resolver;

        return null;
    }

    /**
     * Get all feature names that have been defined.
     *
     * Returns the names of all features registered with resolvers,
     * regardless of whether they have been evaluated yet.
     *
     * @return array<string> Array of defined feature names
     */
    public function defined(): array
    {
        return array_keys($this->featureStateResolvers);
    }

    /**
     * Get all feature names that have cached values.
     *
     * Returns features that have been resolved and cached in memory,
     * including both direct context storage and hierarchical scope storage.
     * This represents the subset of defined features that have been accessed.
     *
     * @return array<string> Array of feature names with cached values
     */
    public function stored(): array
    {
        return array_unique([
            ...array_keys($this->resolvedFeatureStates),
            ...array_keys($this->scopes),
        ]);
    }

    /**
     * Retrieve multiple feature flag values in batch.
     *
     * Efficiently resolves feature values for multiple contexts by delegating
     * to the single-feature get() method for each feature-context pair.
     *
     * @param  array<string, array<int, TogglContext>> $features Map of feature names to their contexts
     * @return array<string, array<int, mixed>>        Map of feature names to their resolved values
     */
    public function getAll(array $features): array
    {
        $results = [];

        foreach ($features as $feature => $contexts) {
            $results[$feature] = [];

            foreach ($contexts as $context) {
                $results[$feature][] = $this->get($feature, $context);
            }
        }

        return $results;
    }

    /**
     * Retrieve a feature flag's value for a specific context.
     *
     * Implements a three-tier resolution strategy:
     * 1. Check in-memory cache for previously resolved values
     * 2. Check hierarchical scope matching if context has scope constraints
     * 3. Resolve using the feature's registered resolver and cache the result
     *
     * Unknown features (those without resolvers) return false by default.
     *
     * @param  string       $feature The feature flag name
     * @param  TogglContext $context The context to evaluate the feature for
     * @return mixed        The resolved feature value (type depends on feature definition)
     */
    public function get(string $feature, TogglContext $context): mixed
    {
        $contextKey = $context->serialize();

        // Return cached value if available
        if (array_key_exists($feature, $this->resolvedFeatureStates) && array_key_exists($contextKey, $this->resolvedFeatureStates[$feature])) {
            return $this->resolvedFeatureStates[$feature][$contextKey];
        }

        // Check hierarchical scope matching if context has scope
        if ($context->scope instanceof FeatureScope && array_key_exists($feature, $this->scopes)) {
            $featureScope = $this->scopes[$feature]['scope'];

            if ($context->scope->matches($featureScope)) {
                return $this->scopes[$feature]['value'];
            }
        }

        // Resolve and cache the value
        $value = $this->resolveValue($feature, $context);

        if ($value === $this->unknownFeatureValue) {
            return false;
        }

        $this->set($feature, $context, $value);

        return $value;
    }

    /**
     * Store a feature flag's value for a specific context.
     *
     * When the context includes hierarchical scope constraints, stores the feature
     * with scope matching rules. Otherwise stores directly by context identifier.
     *
     * @param string       $feature The feature flag name
     * @param TogglContext $context The context to associate with this value (may include scope)
     * @param mixed        $value   The value to store
     */
    public function set(string $feature, TogglContext $context, mixed $value): void
    {
        // Check for scope scope
        if ($context->scope instanceof FeatureScope) {
            $this->scopes[$feature] = [
                'context' => $context->serialize(),
                'scope' => $context->scope,
                'value' => $value,
            ];

            return;
        }

        $this->resolvedFeatureStates[$feature] ??= [];
        $this->resolvedFeatureStates[$feature][$context->serialize()] = $value;
    }

    /**
     * Override a feature flag's value globally for all contexts.
     *
     * Replaces the existing resolver with a static value resolver and clears
     * all cached states, forcing immediate re-evaluation with the new value.
     * This is useful for runtime feature flag overrides in testing or admin interfaces.
     *
     * @param string $feature The feature flag name
     * @param mixed  $value   The new global value to apply
     */
    public function setForAllContexts(string $feature, mixed $value): void
    {
        // Override the resolver to return the fixed value for all contexts
        $this->featureStateResolvers[$feature] = fn (): mixed => $value;

        // Clear existing resolved states to force re-evaluation
        unset($this->resolvedFeatureStates[$feature]);
    }

    /**
     * Remove a feature flag's value for a specific context.
     *
     * When the context includes hierarchical scope, removes the scope-based
     * feature configuration. Otherwise removes the direct context assignment.
     *
     * @param string       $feature The feature flag name
     * @param TogglContext $context The context to remove (may include scope)
     */
    public function delete(string $feature, TogglContext $context): void
    {
        // Remove scope-based storage if context has scope
        if ($context->scope instanceof FeatureScope) {
            unset($this->scopes[$feature]);

            return;
        }

        // Remove direct context-based storage
        unset($this->resolvedFeatureStates[$feature][$context->serialize()]);
    }

    /**
     * Remove cached feature values from memory.
     *
     * Purges either all cached features or a specific set, depending on the
     * features parameter. This clears the in-memory cache but does not affect
     * the registered resolvers.
     *
     * @param null|array<string> $features Specific feature names to purge, or null to purge all
     */
    public function purge(?array $features): void
    {
        if ($features === null) {
            $this->resolvedFeatureStates = [];
        } else {
            foreach ($features as $feature) {
                unset($this->resolvedFeatureStates[$feature]);
            }
        }
    }

    /**
     * Clear all cached feature values from memory.
     *
     * Removes all resolved feature states, forcing fresh resolution on next access.
     * This does not affect registered resolvers or scoped features.
     */
    public function flushCache(): void
    {
        $this->resolvedFeatureStates = [];
    }

    /**
     * Resolve a feature's initial value using its registered resolver.
     *
     * Calls the feature's resolver callback with the given context, or dispatches
     * an UnknownFeatureResolved event if no resolver exists. Returns a sentinel
     * value for unknown features to distinguish them from false/null results.
     *
     * @param  string       $feature The feature flag name
     * @param  TogglContext $context The context to evaluate
     * @return mixed        The resolved value or the unknown feature sentinel
     */
    private function resolveValue(string $feature, TogglContext $context): mixed
    {
        if ($this->missingResolver($feature)) {
            if (Config::get('toggl.events.enabled', true)) {
                $this->events->dispatch(
                    new UnknownFeatureResolved($feature, $context),
                );
            }

            return $this->unknownFeatureValue;
        }

        return $this->featureStateResolvers[$feature]($context);
    }

    /**
     * Check if a feature lacks a registered resolver.
     *
     * @param  string $feature The feature flag name
     * @return bool   True if no resolver is registered, false otherwise
     */
    private function missingResolver(string $feature): bool
    {
        return !array_key_exists($feature, $this->featureStateResolvers);
    }
}
