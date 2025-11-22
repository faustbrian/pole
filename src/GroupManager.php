<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl;

use Cline\Toggl\Contracts\FeatureGroupMembershipRepository;
use Cline\Toggl\Contracts\GroupRepository;
use Cline\Toggl\Support\ContextResolver;

use function array_map;

/**
 * Central manager for feature groups and feature group memberships.
 *
 * Provides a fluent API for defining, updating, and managing feature groups
 * along with context-to-feature group membership operations. Combines both group
 * definition management (which features are in which groups) and membership
 * management (which users/teams belong to which groups).
 *
 * ```php
 * // Define a group
 * Toggl::groups()->create('premium')->with('analytics', 'support')->save();
 *
 * // Assign users to groups
 * Toggl::groups()->assign('premium', $user);
 * Toggl::groups()->for($user)->assign('beta-testers');
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class GroupManager
{
    /**
     * Create a new group manager instance.
     *
     * @param GroupRepository                  $repository           Repository implementation for persisting group definitions
     *                                                               (which features belong to which groups). Can be array-based
     *                                                               or database-backed depending on toggl.group_storage configuration.
     * @param FeatureGroupMembershipRepository $membershipRepository Repository implementation for managing feature group memberships
     *                                                               (which contexts/users belong to which groups). Enables
     *                                                               group-based feature activation for specific users or teams.
     */
    public function __construct(
        private GroupRepository $repository,
        private FeatureGroupMembershipRepository $membershipRepository,
    ) {}

    /**
     * Define a feature group with a list of features.
     *
     * Directly creates or updates a group with the specified features. For incremental
     * building with metadata support, use create() instead.
     *
     * @param  string             $name     Unique group identifier
     * @param  array<int, string> $features Feature flag names to include in this group
     * @return static             Fluent interface for method chaining
     */
    public function define(string $name, array $features): self
    {
        $this->repository->define($name, $features);

        return $this;
    }

    /**
     * Create a fluent builder for defining a new group.
     *
     * Returns a GroupBuilder instance for incremental feature and metadata additions.
     * Call save() on the builder to persist the group definition.
     *
     * @param  string       $name Unique group identifier
     * @return GroupBuilder Fluent builder for incremental group construction
     */
    public function create(string $name): GroupBuilder
    {
        return new GroupBuilder($this->repository, $name);
    }

    /**
     * Replace all features in an existing group.
     *
     * Completely replaces the group's feature list. For incremental updates,
     * use add() or remove() instead.
     *
     * @param  string             $name     Group name to update
     * @param  array<int, string> $features New complete list of feature flag names
     * @return static             Fluent interface for method chaining
     */
    public function update(string $name, array $features): self
    {
        $this->repository->update($name, $features);

        return $this;
    }

    /**
     * Delete a group definition.
     *
     * Removes the group and all its features. Does not affect membership records
     * (use clearMembers() first if needed).
     *
     * @param  string $name Group name to delete
     * @return static Fluent interface for method chaining
     */
    public function delete(string $name): self
    {
        $this->repository->delete($name);

        return $this;
    }

    /**
     * Retrieve all features in a specific group.
     *
     * @param  string             $name Group name to retrieve
     * @return array<int, string> Array of feature flag names in the group
     */
    public function get(string $name): array
    {
        return $this->repository->get($name);
    }

    /**
     * Retrieve all defined groups with their features.
     *
     * @return array<string, array<int, string>> Associative array mapping group names to feature lists
     */
    public function all(): array
    {
        return $this->repository->all();
    }

    /**
     * Check if a group is defined.
     *
     * @param  string $name Group name to check
     * @return bool   True if the group exists, false otherwise
     */
    public function exists(string $name): bool
    {
        return $this->repository->exists($name);
    }

    /**
     * Add features to an existing group.
     *
     * Appends features to the group's existing feature list. Duplicates are
     * automatically removed.
     *
     * @param  string             $name     Group name to modify
     * @param  array<int, string> $features Feature flag names to add
     * @return static             Fluent interface for method chaining
     */
    public function add(string $name, array $features): self
    {
        $this->repository->addFeatures($name, $features);

        return $this;
    }

    /**
     * Remove features from an existing group.
     *
     * Removes specified features from the group's feature list. Non-existent
     * features are silently ignored.
     *
     * @param  string             $name     Group name to modify
     * @param  array<int, string> $features Feature flag names to remove
     * @return static             Fluent interface for method chaining
     */
    public function remove(string $name, array $features): self
    {
        $this->repository->removeFeatures($name, $features);

        return $this;
    }

    /**
     * Add a context to a feature group membership.
     *
     * Assigns the specified context (user, team, etc.) to the group, granting
     * access to all features in that group.
     *
     * @param  string $groupName Group name to assign to
     * @param  mixed  $context   Context to add (user, team, or any TogglContext-compatible entity)
     * @return static Fluent interface for method chaining
     */
    public function assign(string $groupName, mixed $context): self
    {
        $this->membershipRepository->addToGroup($groupName, ContextResolver::resolve($context));

        return $this;
    }

    /**
     * Add multiple contexts to a feature group membership.
     *
     * Bulk operation for assigning several contexts to a group at once.
     *
     * @param  string            $groupName Group name to assign to
     * @param  array<int, mixed> $contexts  Array of contexts to add
     * @return static            Fluent interface for method chaining
     */
    public function assignMany(string $groupName, array $contexts): self
    {
        $resolved = array_map(
            ContextResolver::resolve(...),
            $contexts,
        );

        $this->membershipRepository->addManyToGroup($groupName, $resolved);

        return $this;
    }

    /**
     * Remove a context from a feature group membership.
     *
     * Removes the context from the group, revoking access to the group's features.
     *
     * @param  string $groupName Group name to remove from
     * @param  mixed  $context   Context to remove
     * @return static Fluent interface for method chaining
     */
    public function unassign(string $groupName, mixed $context): self
    {
        $this->membershipRepository->removeFromGroup($groupName, ContextResolver::resolve($context));

        return $this;
    }

    /**
     * Remove multiple contexts from a feature group membership.
     *
     * Bulk operation for removing several contexts from a group at once.
     *
     * @param  string            $groupName Group name to remove from
     * @param  array<int, mixed> $contexts  Array of contexts to remove
     * @return static            Fluent interface for method chaining
     */
    public function unassignMany(string $groupName, array $contexts): self
    {
        $resolved = array_map(
            ContextResolver::resolve(...),
            $contexts,
        );

        $this->membershipRepository->removeManyFromGroup($groupName, $resolved);

        return $this;
    }

    /**
     * Check if a context is a member of a group.
     *
     * @param  string $groupName Group name to check
     * @param  mixed  $context   Context to check for membership
     * @return bool   True if the context belongs to the group, false otherwise
     */
    public function isInGroup(string $groupName, mixed $context): bool
    {
        return $this->membershipRepository->isInGroup($groupName, ContextResolver::resolve($context));
    }

    /**
     * Retrieve all members of a group.
     *
     * Returns serialized context identifiers in Type|ID format (e.g., 'User|1').
     *
     * @param  string             $groupName Group name to retrieve members for
     * @return array<int, string> Array of serialized context identifiers
     */
    public function members(string $groupName): array
    {
        return $this->membershipRepository->getGroupMembers($groupName);
    }

    /**
     * Retrieve all groups a context belongs to.
     *
     * Useful for determining which feature groups apply to a given user or team.
     *
     * @param  mixed              $context Context to check
     * @return array<int, string> Array of group names the context is a member of
     */
    public function groupsFor(mixed $context): array
    {
        return $this->membershipRepository->getGroupsForContext(ContextResolver::resolve($context));
    }

    /**
     * Remove all members from a group.
     *
     * Clears the entire membership list for the group. The group definition
     * itself remains intact.
     *
     * @param  string $groupName Group name to clear
     * @return static Fluent interface for method chaining
     */
    public function clearMembers(string $groupName): self
    {
        $this->membershipRepository->clearGroup($groupName);

        return $this;
    }

    /**
     * Create a context-focused conductor for fluent group operations.
     *
     * Returns a conductor that allows natural context-first APIs inspired by Warden's pattern.
     *
     * ```php
     * // Context-first fluent API
     * Toggl::groups()->for($user)->assign('premium');
     * Toggl::groups()->for($user)->unassign('beta-testers');
     * Toggl::groups()->for($user)->isIn('premium'); // true/false
     * $groups = Toggl::groups()->for($user)->groups(); // ['premium', ...]
     * ```
     *
     * @param  mixed                  $context The context (user, team, etc.) to operate on
     * @return GroupManagerForContext Context-aware conductor for fluent operations
     */
    public function for(mixed $context): GroupManagerForContext
    {
        return new GroupManagerForContext(
            $this->membershipRepository,
            $context,
        );
    }
}
