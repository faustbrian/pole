<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\GroupRepositories;

use Cline\Toggl\Contracts\GroupMembershipRepository;
use Cline\Toggl\Support\TogglContext;

use function array_filter;
use function array_key_exists;
use function array_values;
use function in_array;

/**
 * In-memory array-based group membership repository for testing and simple applications.
 *
 * Stores group memberships in memory for the duration of the request lifecycle.
 * All data is lost when the request completes. Ideal for unit testing, prototyping,
 * or applications where group memberships don't need persistence.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ArrayGroupMembershipRepository implements GroupMembershipRepository
{
    /**
     * Group memberships keyed by group name.
     *
     * @var array<string, array<int, string>>
     */
    private array $memberships = [];

    /**
     * Add a context to a group membership.
     *
     * Creates the group array if it doesn't exist. Idempotent - adding the same
     * context multiple times has no effect.
     *
     * @param string       $groupName Group name to add the context to
     * @param TogglContext $context   Context to add to the group
     */
    public function addToGroup(string $groupName, TogglContext $context): void
    {
        $serialized = $context->serialize();

        if (!array_key_exists($groupName, $this->memberships)) {
            $this->memberships[$groupName] = [];
        }

        if (!in_array($serialized, $this->memberships[$groupName], true)) {
            $this->memberships[$groupName][] = $serialized;
        }
    }

    /**
     * Add multiple contexts to a group membership.
     *
     * Bulk operation for adding several contexts at once.
     *
     * @param string                   $groupName Group name to add contexts to
     * @param array<int, TogglContext> $contexts  Array of contexts to add
     */
    public function addManyToGroup(string $groupName, array $contexts): void
    {
        foreach ($contexts as $context) {
            $this->addToGroup($groupName, $context);
        }
    }

    /**
     * Remove a context from a group membership.
     *
     * Does nothing if the group doesn't exist or the context is not a member.
     * Re-indexes the array after removal.
     *
     * @param string       $groupName Group name to remove the context from
     * @param TogglContext $context   Context to remove from the group
     */
    public function removeFromGroup(string $groupName, TogglContext $context): void
    {
        if (!array_key_exists($groupName, $this->memberships)) {
            return;
        }

        $serialized = $context->serialize();

        $this->memberships[$groupName] = array_values(
            array_filter(
                $this->memberships[$groupName],
                fn (string $s): bool => $s !== $serialized,
            ),
        );
    }

    /**
     * Remove multiple contexts from a group membership.
     *
     * Bulk operation for removing several contexts at once.
     *
     * @param string                   $groupName Group name to remove contexts from
     * @param array<int, TogglContext> $contexts  Array of contexts to remove
     */
    public function removeManyFromGroup(string $groupName, array $contexts): void
    {
        foreach ($contexts as $context) {
            $this->removeFromGroup($groupName, $context);
        }
    }

    /**
     * Check if a context is a member of a group.
     *
     * Non-existent groups are treated as empty (returns false).
     *
     * @param  string       $groupName Group name to check
     * @param  TogglContext $context   Context to check for membership
     * @return bool         True if the context is in the group, false otherwise
     */
    public function isInGroup(string $groupName, TogglContext $context): bool
    {
        if (!array_key_exists($groupName, $this->memberships)) {
            return false;
        }

        $serialized = $context->serialize();

        return in_array($serialized, $this->memberships[$groupName], true);
    }

    /**
     * Retrieve all members of a group.
     *
     * Returns serialized context identifiers (e.g., 'User|1', 'Team|5').
     * Returns empty array if the group doesn't exist or has no members.
     *
     * @param  string             $groupName Group name to retrieve members for
     * @return array<int, string> Array of serialized context identifiers
     */
    public function getGroupMembers(string $groupName): array
    {
        return $this->memberships[$groupName] ?? [];
    }

    /**
     * Retrieve all groups a context belongs to.
     *
     * Searches through all groups to find memberships for the specified context.
     *
     * @param  TogglContext       $context Context to search for
     * @return array<int, string> Array of group names the context is a member of
     */
    public function getGroupsForContext(TogglContext $context): array
    {
        $serialized = $context->serialize();
        $groups = [];

        foreach ($this->memberships as $groupName => $members) {
            if (in_array($serialized, $members, true)) {
                $groups[] = $groupName;
            }
        }

        return $groups;
    }

    /**
     * Remove all members from a group.
     *
     * Clears the entire membership list. The group definition itself remains intact.
     *
     * @param string $groupName Group name to clear
     */
    public function clearGroup(string $groupName): void
    {
        unset($this->memberships[$groupName]);
    }

    /**
     * Remove a context from all group memberships.
     *
     * Useful when deleting a user or revoking all group-based feature access.
     *
     * @param TogglContext $context Context to remove from all groups
     */
    public function removeContextFromAllGroups(TogglContext $context): void
    {
        $serialized = $context->serialize();

        foreach ($this->memberships as $groupName => $members) {
            $this->memberships[$groupName] = array_values(
                array_filter(
                    $members,
                    fn (string $s): bool => $s !== $serialized,
                ),
            );
        }
    }
}
