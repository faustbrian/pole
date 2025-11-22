<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

/**
 * Contract for managing collections of related feature flags.
 *
 * Feature groups provide organizational structure for related features that should
 * typically be managed together. This is particularly valuable for:
 * - Beta programmes with multiple experimental features
 * - Feature sets tied to subscription tiers or plans
 * - Phased rollouts where related features activate as a unit
 * - Development workflows requiring coordinated feature toggles
 *
 * Groups enable bulk operations, reducing the need to manage individual features
 * when they represent a cohesive capability or product offering. All operations
 * affect the group's features atomically, ensuring consistent state.
 *
 * ```php
 * // Define a group with related features
 * $manager->defineGroup('premium-tier', [
 *     'advanced-analytics',
 *     'priority-support',
 *     'custom-branding'
 * ]);
 *
 * // Activate all features in the group at once
 * $manager->activateGroup('premium-tier');
 *
 * // Or deactivate when subscription expires
 * $manager->deactivateGroup('premium-tier');
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface FeatureGroup
{
    /**
     * Define a named group of feature flags.
     *
     * Registers a group with its constituent features. If the group already
     * exists, implementations may either replace the definition or merge the
     * features depending on driver behavior.
     *
     * @param string             $name     Unique group identifier
     * @param array<int, string> $features Feature names to include in this group
     */
    public function defineGroup(string $name, array $features): void;

    /**
     * Retrieve all features in a group.
     *
     * Returns the complete list of feature names associated with the specified
     * group. This can be used to inspect feature group membership or iterate over
     * features for custom operations.
     *
     * @param  string             $name Group identifier
     * @return array<int, string> Feature names in the group
     */
    public function getGroup(string $name): array;

    /**
     * Check if a group exists.
     *
     * Determines whether a group has been defined, useful for conditional
     * logic before attempting group operations.
     *
     * @param  string $name Group identifier to check
     * @return bool   True if the group is defined, false otherwise
     */
    public function hasGroup(string $name): bool;

    /**
     * Activate all features in a group.
     *
     * Sets all features in the group to the specified value, typically true
     * for boolean features. This provides a convenient way to enable entire
     * feature sets in a single operation.
     *
     * @param string $name  Group identifier
     * @param mixed  $value Value to set for all features (defaults to true)
     */
    public function activateGroup(string $name, mixed $value = true): void;

    /**
     * Deactivate all features in a group.
     *
     * Sets all features in the group to false, effectively disabling the
     * entire feature set. Useful for emergency rollbacks or subscription
     * downgrades.
     *
     * @param string $name Group identifier
     */
    public function deactivateGroup(string $name): void;
}
