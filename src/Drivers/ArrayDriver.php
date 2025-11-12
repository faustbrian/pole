<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Drivers;

use Cline\Pole\Contracts\CanListStoredFeatures;
use Cline\Pole\Contracts\Driver;
use Cline\Pole\Contracts\HasFlushableCache;
use Cline\Pole\Events\UnknownFeatureResolved;
use Cline\Pole\Feature;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use stdClass;

use function array_key_exists;
use function array_keys;
use function is_callable;
use function with;

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
     * Maps feature names to scope identifiers to their resolved values.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $resolvedFeatureStates = [];

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
     * @param Dispatcher                                     $events                The event dispatcher
     * @param array<string, (callable(mixed $scope): mixed)> $featureStateResolvers The feature resolvers
     */
    public function __construct(
        private readonly Dispatcher $events,
        /**
         * The feature state resolvers.
         *
         * @var array<string, (callable(mixed): mixed)>
         */
        private array $featureStateResolvers,
    ) {
        $this->unknownFeatureValue = new stdClass();
    }

    /**
     * Define an initial feature flag state resolver.
     *
     * @param  string                                $feature  The feature name
     * @param  (callable(mixed $scope): mixed)|mixed $resolver The resolver callback or static value
     * @return mixed                                 Always returns null for this driver
     */
    public function define(string $feature, mixed $resolver = null): mixed
    {
        $this->featureStateResolvers[$feature] = is_callable($resolver)
            ? $resolver
            : fn (): mixed => $resolver;

        return null;
    }

    /**
     * Retrieve the names of all defined features.
     *
     * @return array<string> The list of defined feature names
     */
    public function defined(): array
    {
        return array_keys($this->featureStateResolvers);
    }

    /**
     * Retrieve the names of all stored features.
     *
     * Returns features that have been resolved and cached in memory.
     *
     * @return array<string> The list of stored feature names
     */
    public function stored(): array
    {
        return array_keys($this->resolvedFeatureStates);
    }

    /**
     * Get multiple feature flag values.
     *
     * @param  array<string, array<int, mixed>> $features Map of feature names to their scopes
     * @return array<string, array<int, mixed>> Map of feature names to their resolved values
     */
    public function getAll(array $features): array
    {
        return Collection::make($features)
            ->map(fn ($scopes, string $feature) => Collection::make($scopes)
                ->map(fn ($scope): mixed => $this->get($feature, $scope))
                ->all())
            ->all();
    }

    /**
     * Retrieve a feature flag's value.
     *
     * Checks the in-memory cache first, then resolves using the feature's resolver if needed.
     *
     * @param  string $feature The feature name
     * @param  mixed  $scope   The scope to check
     * @return mixed  The feature's value for the given scope
     */
    public function get(string $feature, mixed $scope): mixed
    {
        $scopeKey = Feature::serializeScope($scope);

        // Return cached value if available
        if (array_key_exists($feature, $this->resolvedFeatureStates) && array_key_exists($scopeKey, $this->resolvedFeatureStates[$feature])) {
            return $this->resolvedFeatureStates[$feature][$scopeKey];
        }

        // Resolve and cache the value
        return with($this->resolveValue($feature, $scope), function ($value) use ($feature, $scopeKey) {
            if ($value === $this->unknownFeatureValue) {
                return false;
            }

            $this->set($feature, $scopeKey, $value);

            return $value;
        });
    }

    /**
     * Set a feature flag's value.
     *
     * @param string $feature The feature name
     * @param mixed  $scope   The scope to update
     * @param mixed  $value   The new value to set
     */
    public function set(string $feature, mixed $scope, mixed $value): void
    {
        $this->resolvedFeatureStates[$feature] ??= [];

        $this->resolvedFeatureStates[$feature][Feature::serializeScope($scope)] = $value;
    }

    /**
     * Set a feature flag's value for all scopes.
     *
     * Replaces the resolver with a static value and clears existing cached states.
     *
     * @param string $feature The feature name
     * @param mixed  $value   The new value to set
     */
    public function setForAllScopes(string $feature, mixed $value): void
    {
        // Override the resolver to return the fixed value for all scopes
        $this->featureStateResolvers[$feature] = fn (): mixed => $value;

        // Clear existing resolved states to force re-evaluation
        unset($this->resolvedFeatureStates[$feature]);
    }

    /**
     * Delete a feature flag's value.
     *
     * @param string $feature The feature name
     * @param mixed  $scope   The scope to delete
     */
    public function delete(string $feature, mixed $scope): void
    {
        unset($this->resolvedFeatureStates[$feature][Feature::serializeScope($scope)]);
    }

    /**
     * Purge the given feature from storage.
     *
     * @param null|array<string> $features The feature names to purge, or null to purge all
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
     * Flush the resolved feature states.
     *
     * Clears all cached feature values from memory.
     */
    public function flushCache(): void
    {
        $this->resolvedFeatureStates = [];
    }

    /**
     * Determine the initial value for a given feature and scope.
     *
     * Calls the feature's resolver or dispatches an UnknownFeatureResolved event.
     *
     * @param  string $feature The feature name
     * @param  mixed  $scope   The scope to evaluate
     * @return mixed  The resolved value or the unknown feature sentinel
     */
    private function resolveValue(string $feature, mixed $scope): mixed
    {
        if ($this->missingResolver($feature)) {
            $this->events->dispatch(
                new UnknownFeatureResolved($feature, $scope),
            );

            return $this->unknownFeatureValue;
        }

        return $this->featureStateResolvers[$feature]($scope);
    }

    /**
     * Determine if the feature does not have a resolver available.
     *
     * @param  string $feature The feature name
     * @return bool   True if no resolver is registered, false otherwise
     */
    private function missingResolver(string $feature): bool
    {
        return !array_key_exists($feature, $this->featureStateResolvers);
    }
}
