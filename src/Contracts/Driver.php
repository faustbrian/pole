<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Contracts;

/**
 * Contract for feature flag storage drivers.
 *
 * Drivers are responsible for storing and retrieving feature flag values,
 * managing feature definitions, and handling scope-specific feature states.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Driver
{
    /**
     * Define an initial feature flag state resolver.
     *
     * Registers a feature with an optional resolver that determines its initial state.
     * The resolver can be a callable that receives the scope, or a static value.
     *
     * @param  string                                $feature  The feature name
     * @param  (callable(mixed $scope): mixed)|mixed $resolver The resolver callback or static value
     * @return mixed                                 The result of defining the feature (driver-specific)
     */
    public function define(string $feature, mixed $resolver = null): mixed;

    /**
     * Retrieve the names of all defined features.
     *
     * Returns a list of all feature names that have been registered with this driver.
     *
     * @return array<string> The list of defined feature names
     */
    public function defined(): array;

    /**
     * Get multiple feature flag values.
     *
     * Efficiently retrieves values for multiple features across multiple scopes.
     * This is optimized for bulk operations to reduce database queries or lookups.
     *
     * @param  array<string, array<int, mixed>> $features Map of feature names to their scopes
     * @return array<string, array<int, mixed>> Map of feature names to their resolved values
     */
    public function getAll(array $features): array;

    /**
     * Retrieve a feature flag's value.
     *
     * Gets the current value of a feature for the specified scope.
     * If no value exists, the feature's resolver will be called to determine the initial value.
     *
     * @param  string $feature The feature name
     * @param  mixed  $scope   The scope to check (e.g., user, team, or null for global)
     * @return mixed  The feature's value for the given scope
     */
    public function get(string $feature, mixed $scope): mixed;

    /**
     * Set a feature flag's value.
     *
     * Updates the value of a feature for a specific scope.
     * This overrides any default resolver behavior.
     *
     * @param string $feature The feature name
     * @param mixed  $scope   The scope to update
     * @param mixed  $value   The new value to set
     */
    public function set(string $feature, mixed $scope, mixed $value): void;

    /**
     * Set a feature flag's value for all scopes.
     *
     * Updates the feature value globally, affecting all scopes.
     * This is typically used for enabling or disabling features system-wide.
     *
     * @param string $feature The feature name
     * @param mixed  $value   The new value to set
     */
    public function setForAllScopes(string $feature, mixed $value): void;

    /**
     * Delete a feature flag's value.
     *
     * Removes the stored value for a feature and scope combination.
     * The next access will trigger the resolver to determine the value again.
     *
     * @param string $feature The feature name
     * @param mixed  $scope   The scope to delete
     */
    public function delete(string $feature, mixed $scope): void;

    /**
     * Purge the given features from storage.
     *
     * Removes all stored values for the specified features.
     * If no features are specified (null), all feature values are purged.
     *
     * @param null|array<string> $features The feature names to purge, or null to purge all
     */
    public function purge(?array $features): void;
}
