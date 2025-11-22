<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl;

use Cline\Toggl\Contracts\FeatureGroupMembershipRepository;
use Cline\Toggl\Support\ContextResolver;

/**
 * Context-focused fluent interface for feature group membership operations.
 *
 * Provides a natural API for managing feature group memberships from the perspective
 * of a specific context (user, team, etc.), inspired by Warden's conductor pattern.
 * Enables context-first operations like Toggl::groups()->for($user)->assign('premium')
 * instead of the more verbose Toggl::groups()->assign('premium', $user).
 *
 * ```php
 * $manager = Toggl::groups()->for($user);
 * $manager->assign('premium')->assign('beta-testers');
 * if ($manager->isIn('premium')) {
 *     // User has premium features
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class GroupManagerForContext
{
    /**
     * Create a new context-scoped group manager instance.
     *
     * @param FeatureGroupMembershipRepository $membershipRepository Repository implementation for managing feature group memberships
     *                                                               (database-backed or array-based depending on configuration)
     * @param mixed                            $context              The context this manager operates on. Can be a User model,
     *                                                               team, or any entity that can be resolved to a TogglContext.
     *                                                               All subsequent operations will target this context.
     */
    public function __construct(
        private FeatureGroupMembershipRepository $membershipRepository,
        private mixed $context,
    ) {}

    /**
     * Add this context to a feature group membership.
     *
     * Grants this context access to all features in the specified group.
     *
     * ```php
     * Toggl::groups()->for($user)->assign('premium');
     * ```
     *
     * @param  string $groupName Group name to join
     * @return static Fluent interface for method chaining
     */
    public function assign(string $groupName): self
    {
        $this->membershipRepository->addToGroup($groupName, ContextResolver::resolve($this->context));

        return $this;
    }

    /**
     * Remove this context from a feature group membership.
     *
     * Revokes this context's access to the features in the specified group.
     *
     * ```php
     * Toggl::groups()->for($user)->unassign('beta-testers');
     * ```
     *
     * @param  string $groupName Group name to leave
     * @return static Fluent interface for method chaining
     */
    public function unassign(string $groupName): self
    {
        $this->membershipRepository->removeFromGroup($groupName, ContextResolver::resolve($this->context));

        return $this;
    }

    /**
     * Check if this context is a member of a group.
     *
     * ```php
     * if (Toggl::groups()->for($user)->isIn('premium')) {
     *     // User is in premium group
     * }
     * ```
     *
     * @param  string $groupName Group name to check
     * @return bool   True if this context belongs to the group, false otherwise
     */
    public function isIn(string $groupName): bool
    {
        return $this->membershipRepository->isInGroup($groupName, ContextResolver::resolve($this->context));
    }

    /**
     * Retrieve all groups this context is a member of.
     *
     * Useful for determining which feature groups apply to this context.
     *
     * ```php
     * $userGroups = Toggl::groups()->for($user)->groups();
     * // ['premium', 'beta-testers', 'early-access']
     * ```
     *
     * @return array<int, string> Array of group names this context belongs to
     */
    public function groups(): array
    {
        return $this->membershipRepository->getGroupsForContext(ContextResolver::resolve($this->context));
    }
}
