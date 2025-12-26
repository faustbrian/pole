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
 * Contract for managing context assignments to feature groups.
 *
 * Feature group memberships enable context-based feature activation by associating contexts
 * (users, teams, organizations, etc.) with feature groups. When a context is a
 * member of a group, all features in that group can be evaluated in the context
 * of that membership.
 *
 * This is particularly powerful for:
 * - Beta programmes where specific users get access to experimental features
 * - Subscription tiers where groups represent different feature sets
 * - Organizational access control where teams have different capabilities
 * - A/B testing where cohorts are represented as feature group memberships
 *
 * Unlike feature groups which define what features belong together, memberships
 * define which contexts have access to those grouped features. This separation
 * enables flexible feature access control without modifying group definitions.
 *
 * ```php
 * // Add users to beta programme
 * $repository->addToGroup('beta-testers', $user);
 * $repository->addManyToGroup('beta-testers', [$user1, $user2, $user3]);
 *
 * // Check membership before granting access
 * if ($repository->isInGroup('beta-testers', $user)) {
 *     // User has access to all features in 'beta-testers' group
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface FeatureGroupMembershipRepository
{
    /**
     * Add a context to a feature group membership.
     *
     * Registers the context as a member of the specified group, granting access
     * to all features in that group. This operation is idempotent; adding an
     * already-member context has no effect and does not raise an error.
     *
     * @param string       $groupName Group identifier
     * @param TogglContext $context   Context to add (user, team, organization, etc.)
     */
    public function addToGroup(string $groupName, TogglContext $context): void;

    /**
     * Add multiple contexts to a feature group membership efficiently.
     *
     * Batch operation for registering multiple contexts as group members in a
     * single operation. This is optimized to minimize database queries and
     * should be preferred over multiple addToGroup() calls when adding
     * several contexts simultaneously.
     *
     * @param string                   $groupName Group identifier
     * @param array<int, TogglContext> $contexts  Contexts to add to the group
     */
    public function addManyToGroup(string $groupName, array $contexts): void;

    /**
     * Remove a context from a feature group membership.
     *
     * Unregisters the context from the specified group, revoking access to
     * the group's features. This is useful for subscription downgrades,
     * ending beta access, or removing users from experimental cohorts.
     *
     * @param string       $groupName Group identifier
     * @param TogglContext $context   Context to remove from the group
     */
    public function removeFromGroup(string $groupName, TogglContext $context): void;

    /**
     * Remove multiple contexts from a feature group membership efficiently.
     *
     * Batch operation for unregistering multiple contexts from a group in a
     * single operation. Optimized to minimize database queries when bulk
     * removing access.
     *
     * @param string                   $groupName Group identifier
     * @param array<int, TogglContext> $contexts  Contexts to remove from the group
     */
    public function removeManyFromGroup(string $groupName, array $contexts): void;

    /**
     * Check if a context is a member of a group.
     *
     * Determines whether the context has been registered as a member of the
     * specified group, which would grant access to all features in that group.
     *
     * @param  string       $groupName Group identifier to check
     * @param  TogglContext $context   Context to check for membership
     * @return bool         True if the context is a member, false otherwise
     */
    public function isInGroup(string $groupName, TogglContext $context): bool;

    /**
     * Retrieve all contexts that are members of a group.
     *
     * Returns serialized identifiers for all contexts registered as members of
     * the specified group. The format of returned identifiers depends on how
     * contexts implement serialization (via Serializable or TogglContextable
     * interfaces).
     *
     * Useful for auditing feature group membership, displaying lists of users with access
     * to specific feature sets, or generating reports on feature access distribution.
     *
     * @param  string             $groupName Group identifier
     * @return array<int, string> Serialized context identifiers (e.g., ["user:1", "user:2", "team:abc"])
     */
    public function getGroupMembers(string $groupName): array;

    /**
     * Retrieve all groups a context belongs to.
     *
     * Returns the names of all groups where the given context is registered as
     * a member. This enables inverse lookups to determine what feature sets
     * a context has access to.
     *
     * @param  TogglContext       $context Context to check feature group memberships for
     * @return array<int, string> Group names the context belongs to
     */
    public function getGroupsForContext(TogglContext $context): array;

    /**
     * Remove all members from a group.
     *
     * Clears all context memberships from the specified group, effectively
     * revoking all access to the group's features. The group definition
     * itself remains intact and can be repopulated with new members.
     *
     * This is useful when resetting beta programmes or clearing out expired
     * subscription cohorts.
     *
     * @param string $groupName Group identifier to clear
     */
    public function clearGroup(string $groupName): void;

    /**
     * Remove a context from all feature group memberships.
     *
     * Unregisters the context from every group it belongs to, revoking all
     * group-based feature access. This is typically used when deleting a
     * user, team, or other context entity to clean up orphaned memberships.
     *
     * @param TogglContext $context Context to remove from all groups
     */
    public function removeContextFromAllGroups(TogglContext $context): void;
}
