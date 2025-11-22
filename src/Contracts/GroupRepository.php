<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

/**
 * Contract for storing and managing feature group definitions.
 *
 * Feature group repositories abstract the storage layer for group definitions,
 * enabling different backends (in-memory arrays, databases, caches, etc.) to
 * persist and retrieve collections of related features.
 *
 * This contract focuses solely on group definitions (which features belong to
 * which groups), not on membership (which contexts have access to groups). For
 * membership management, see GroupMembershipRepository.
 *
 * Use cases include:
 * - Defining feature sets for different subscription tiers
 * - Organizing experimental features into beta programmes
 * - Grouping related features that ship together in releases
 * - Managing feature dependencies through group composition
 *
 * ```php
 * // Define groups with features and metadata
 * $repository->define('premium-tier', [
 *     'advanced-analytics',
 *     'priority-support',
 *     'custom-branding'
 * ], [
 *     'description' => 'Premium subscription features',
 *     'tier' => 'premium'
 * ]);
 *
 * // Retrieve and manipulate groups
 * $features = $repository->get('premium-tier');
 * $repository->addFeatures('premium-tier', ['api-access']);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface GroupRepository
{
    /**
     * Define or replace a feature group.
     *
     * Creates a new group with the specified features and optional metadata.
     * If the group already exists, implementations typically replace the entire
     * definition with the new one. For incremental updates, use update() or
     * addFeatures() instead.
     *
     * Metadata can store arbitrary information about the group such as
     * descriptions, ownership, tier levels, or custom application data.
     *
     * @param string               $name     Unique group identifier
     * @param array<int, string>   $features Feature names to include in this group
     * @param array<string, mixed> $metadata Optional metadata (e.g., ['description' => '...', 'tier' => 'premium'])
     */
    public function define(string $name, array $features, array $metadata = []): void;

    /**
     * Retrieve all features in a group.
     *
     * Returns the complete list of feature names associated with the specified
     * group. This is the primary method for accessing group composition.
     *
     * @param  string             $name Group identifier
     * @return array<int, string> Feature names in the group
     */
    public function get(string $name): array;

    /**
     * Retrieve all defined groups with their features.
     *
     * Returns a map of all group names to their feature lists. Useful for
     * administrative interfaces, reporting, or bulk operations across all
     * groups.
     *
     * @return array<string, array<int, string>> Map of group names to feature name arrays
     */
    public function all(): array;

    /**
     * Check if a group exists.
     *
     * Determines whether a group with the given name has been defined,
     * useful for validation before attempting operations on a group.
     *
     * @param  string $name Group identifier to check
     * @return bool   True if the group is defined, false otherwise
     */
    public function exists(string $name): bool;

    /**
     * Delete a group definition.
     *
     * Removes the group from storage. This affects only the group definition itself;
     * individual features remain intact and continue to function independently.
     * Group memberships (if any) may also be affected depending on the membership
     * repository implementation.
     *
     * This operation is useful when retiring feature sets or reorganizing the group
     * structure without affecting the underlying features.
     *
     * @param string $name Group identifier to delete
     */
    public function delete(string $name): void;

    /**
     * Replace a group's entire feature list.
     *
     * Updates the group by replacing all its features with the provided list.
     * This is useful when you need to completely redefine a group's composition
     * without affecting its metadata or other properties.
     *
     * @param string             $name     Group identifier
     * @param array<int, string> $features New complete list of feature names
     */
    public function update(string $name, array $features): void;

    /**
     * Add features to an existing group.
     *
     * Appends additional features to the group's current feature list without
     * removing existing ones. This is the recommended approach for incremental
     * group updates rather than replacing the entire definition.
     *
     * @param string             $name     Group identifier
     * @param array<int, string> $features Feature names to add to the group
     */
    public function addFeatures(string $name, array $features): void;

    /**
     * Remove features from a group.
     *
     * Removes specified features from the group's feature list while preserving
     * all other features. This is useful when retiring features or reorganizing
     * feature sets without recreating the entire group definition.
     *
     * @param string             $name     Group identifier
     * @param array<int, string> $features Feature names to remove from the group
     */
    public function removeFeatures(string $name, array $features): void;
}
