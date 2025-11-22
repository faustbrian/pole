<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

use Cline\Toggl\Support\TogglContext;

/**
 * Core contract for feature flag storage and retrieval drivers.
 *
 * Drivers provide the storage abstraction layer for the Toggl feature flag system,
 * handling persistence of feature definitions, values, and context-specific states
 * across various backends (database, array, cache, authorization gates).
 *
 * Each driver implementation manages:
 * - Feature definition registration with optional default resolvers
 * - Context-aware value storage and retrieval
 * - Bulk operations for efficient multi-feature lookups
 * - Feature lifecycle operations (create, update, delete, purge)
 *
 * Contexts enable contextual feature evaluation, allowing different feature states
 * for different users, teams, tenants, or other domain entities. Drivers must
 * support null contexts for global features that apply system-wide.
 *
 * ```php
 * $driver->define('new-ui', fn($context) => $context->isAdmin());
 * $driver->set('new-ui', $user, true);
 * $enabled = $driver->get('new-ui', $user); // true
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Driver
{
    /**
     * Define a feature with an optional default value resolver.
     *
     * Registers a feature flag with the driver, optionally providing a resolver
     * that determines the feature's initial state when no explicit value exists.
     * The resolver receives the context and returns the initial value.
     *
     * Static values are also supported as resolvers for features that should
     * have a consistent default across all contexts.
     *
     * @param  string                                         $feature  Unique feature identifier
     * @param  (callable(TogglContext $context): mixed)|mixed $resolver Callback to determine initial state, or static default value
     * @return mixed                                          Driver-specific return value (often void or the feature instance)
     */
    public function define(string $feature, mixed $resolver = null): mixed;

    /**
     * Retrieve the names of all features registered with this driver.
     *
     * Returns feature names that have been defined via the define() method.
     * This includes both features with and without custom resolvers, but may
     * not include features that only exist in storage without being explicitly
     * defined, depending on the driver implementation.
     *
     * @return array<int, string> List of defined feature names
     */
    public function defined(): array;

    /**
     * Retrieve values for multiple features across multiple contexts efficiently.
     *
     * Optimized for bulk lookups to minimize storage queries when checking
     * multiple features simultaneously. This is particularly useful for
     * rendering UI that depends on several feature flags, or for batch
     * processing that needs to evaluate features for many contexts.
     *
     * @param  array<string, array<int, TogglContext>> $features Map of feature names to arrays of contexts to check
     * @return array<string, array<int, mixed>>        Map of feature names to arrays of resolved values for each context
     */
    public function getAll(array $features): array;

    /**
     * Retrieve a feature flag's value for a specific context.
     *
     * Returns the current value of a feature for the given context. If no
     * explicit value has been set via set(), the feature's resolver (if
     * defined) determines the value.
     *
     * @param  string       $feature Feature identifier to retrieve
     * @param  TogglContext $context Context (user, team, etc.)
     * @return mixed        The feature's resolved value for the given context
     */
    public function get(string $feature, TogglContext $context): mixed;

    /**
     * Set a feature flag's value for a specific context.
     *
     * Persists an explicit value for the feature and context combination,
     * overriding any default resolver behavior. This allows runtime feature
     * activation/deactivation without code changes.
     *
     * @param string       $feature Feature identifier to update
     * @param TogglContext $context Context to set the value for
     * @param mixed        $value   Value to persist (often boolean, but can be any type)
     */
    public function set(string $feature, TogglContext $context, mixed $value): void;

    /**
     * Set a feature flag's value globally across all contexts.
     *
     * Updates the feature value system-wide, typically used for emergency
     * kill switches or global feature rollouts. This affects all contexts
     * unless they have explicit context-specific overrides.
     *
     * @param string $feature Feature identifier to update globally
     * @param mixed  $value   Value to set for all contexts
     */
    public function setForAllContexts(string $feature, mixed $value): void;

    /**
     * Delete a feature flag's stored value for a specific context.
     *
     * Removes the persisted value, causing subsequent get() calls to fall
     * back to the feature's resolver. This is useful for resetting features
     * to their default behavior without permanently altering the definition.
     *
     * @param string       $feature Feature identifier to delete
     * @param TogglContext $context Context whose value should be removed
     */
    public function delete(string $feature, TogglContext $context): void;

    /**
     * Purge features from storage completely.
     *
     * Removes all stored values for the specified features across all contexts.
     * If features is null, purges all feature data from storage. This is
     * destructive and typically used during cleanup or when retiring features.
     *
     * Feature definitions remain intact; only stored values are removed.
     *
     * @param null|array<int, string> $features Feature names to purge, or null to purge all
     */
    public function purge(?array $features): void;
}
