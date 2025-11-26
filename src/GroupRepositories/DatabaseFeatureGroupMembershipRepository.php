<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\GroupRepositories;

use Cline\Toggl\Contracts\FeatureGroupMembershipRepository;
use Cline\Toggl\Database\FeatureGroupMembership;
use Cline\Toggl\Exceptions\InvalidContextException;
use Cline\Toggl\QueryBuilder;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;

use function assert;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Database-backed feature group membership repository for persistent storage.
 *
 * Stores feature group memberships in the database using polymorphic relationships,
 * enabling persistence across requests. Uses the feature_group_memberships table with
 * morphable context_type and context_id columns for flexible entity support.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
#[Singleton()]
final readonly class DatabaseFeatureGroupMembershipRepository implements FeatureGroupMembershipRepository
{
    /**
     * Add a context to a feature group membership.
     *
     * Uses updateOrCreate to handle both new memberships and timestamp updates
     * for existing memberships. Polymorphic columns enable support for any context type.
     *
     * @param string       $groupName Group name to add the context to
     * @param TogglContext $context   Context to add to the group
     */
    public function addToGroup(string $groupName, TogglContext $context): void
    {
        [$contextType, $contextId] = $this->extractContextMorph($context);

        $this->newQuery()->updateOrCreate(
            [
                'group_name' => $groupName,
                'context_type' => $contextType,
                'context_id' => $contextId,
            ],
        );
    }

    /**
     * Add multiple contexts to a feature group membership.
     *
     * Batch operation for adding several contexts at once.
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
     * Remove a context from a feature group membership.
     *
     * Deletes the membership record. Idempotent - does nothing if the context
     * is not a member.
     *
     * @param string       $groupName Group name to remove the context from
     * @param TogglContext $context   Context to remove from the group
     */
    public function removeFromGroup(string $groupName, TogglContext $context): void
    {
        [$contextType, $contextId] = $this->extractContextMorph($context);

        $this->newQuery()
            ->where('group_name', $groupName)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->delete();
    }

    /**
     * Remove multiple contexts from a feature group membership.
     *
     * Batch operation for removing several contexts at once.
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
     * @param  string       $groupName Group name to check
     * @param  TogglContext $context   Context to check for membership
     * @return bool         True if the context is in the group, false otherwise
     */
    public function isInGroup(string $groupName, TogglContext $context): bool
    {
        [$contextType, $contextId] = $this->extractContextMorph($context);

        return $this->newQuery()
            ->where('group_name', $groupName)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->exists();
    }

    /**
     * Retrieve all members of a group.
     *
     * Returns serialized context identifiers in Type|ID format (e.g., 'User|1').
     *
     * @param  string             $groupName Group name to retrieve members for
     * @return array<int, string> Array of serialized context identifiers
     */
    public function getGroupMembers(string $groupName): array
    {
        $records = $this->newQuery()
            ->where('group_name', $groupName)
            ->get(['context_type', 'context_id']);

        $result = [];

        foreach ($records as $record) {
            $result[] = $record->context_type.'|'.$record->context_id;
        }

        return $result;
    }

    /**
     * Retrieve all groups a context belongs to.
     *
     * Useful for determining which feature groups apply to a context.
     *
     * @param  TogglContext       $context Context to check
     * @return array<int, string> Array of group names the context is a member of
     */
    public function getGroupsForContext(TogglContext $context): array
    {
        [$contextType, $contextId] = $this->extractContextMorph($context);

        /** @var array<int, string> */
        return $this->newQuery()
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->pluck('group_name')
            ->toArray();
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
        $this->newQuery()
            ->where('group_name', $groupName)
            ->delete();
    }

    /**
     * Remove a context from all feature group memberships.
     *
     * Useful when deleting a user or team and needing to clean up all memberships.
     *
     * @param TogglContext $context Context to remove from all groups
     */
    public function removeContextFromAllGroups(TogglContext $context): void
    {
        [$contextType, $contextId] = $this->extractContextMorph($context);

        $this->newQuery()
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->delete();
    }

    /**
     * Create a new query builder for the FeatureGroupMembership model.
     *
     * Configures the connection based on toggl.default and toggl.stores configuration.
     *
     * @return Builder<FeatureGroupMembership> Eloquent query builder instance
     */
    private function newQuery(): Builder
    {
        $query = QueryBuilder::featureGroupMembership();

        $defaultStore = Config::get('toggl.default', 'database');
        assert(is_string($defaultStore));

        $connection = Config::get(sprintf('toggl.stores.%s.connection', $defaultStore));

        if ($connection !== null) {
            assert(is_string($connection));
            $query->getModel()->setConnection($connection);
        }

        return $query;
    }

    /**
     * Extract polymorphic type and ID from a TogglContext.
     *
     * @param TogglContext $context Context to extract morphable data from
     *
     * @throws InvalidContextException When context has no ID
     *
     * @return array{string, int|string} Tuple of [context_type, context_id]
     */
    private function extractContextMorph(TogglContext $context): array
    {
        if ($context->id === null) {
            throw InvalidContextException::mustBeEloquentModelWithId();
        }

        assert(is_int($context->id) || is_string($context->id));

        return [$context->type, $context->id];
    }
}
