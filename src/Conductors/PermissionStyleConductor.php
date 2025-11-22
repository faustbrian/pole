<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use BackedEnum;
use Cline\Toggl\FeatureManager;

use function is_array;

/**
 * Conductor for permission-style feature activation.
 *
 * Warden-inspired pattern: Toggl::allow($user)->to('premium-dashboard')
 * Supports single/bulk features, single/bulk contexts, and feature groups.
 * Provides intuitive permission-style API for feature access control.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class PermissionStyleConductor
{
    /**
     * Create a new permission-style conductor instance.
     *
     * @param FeatureManager     $manager  The feature manager instance for managing feature state
     *                                     across different contexts and handling activation/deactivation operations
     * @param array<mixed>|mixed $contexts Context(s) to grant or revoke access. Can be a single context
     *                                     or array of contexts. All context types supported by the feature
     *                                     manager are accepted (user, team, organization, etc.)
     * @param bool               $allow    Whether to allow (true) or deny (false) access. When true,
     *                                     features will be activated; when false, features will be deactivated
     */
    public function __construct(
        private FeatureManager $manager,
        private mixed $contexts,
        private bool $allow,
    ) {}

    /**
     * Grant/revoke access to feature(s) (terminal method).
     *
     * Executes activation or deactivation for all context/feature combinations in a
     * cartesian product pattern. When both contexts and features are arrays, operates
     * on all combinations.
     *
     * @param array<BackedEnum|string>|BackedEnum|string $features feature(s) to grant or revoke access to.
     *                                                             Can be a single feature or array of features.
     */
    public function to(string|BackedEnum|array $features): void
    {
        $featureArray = is_array($features) ? $features : [$features];
        $contextArray = is_array($this->contexts) ? $this->contexts : [$this->contexts];

        foreach ($contextArray as $context) {
            foreach ($featureArray as $feature) {
                if ($this->allow) {
                    $this->manager->for($context)->activate($feature);
                } else {
                    $this->manager->for($context)->deactivate($feature);
                }
            }
        }
    }

    /**
     * Grant/revoke access to all features in a group (terminal method).
     *
     * Executes group activation or deactivation for all specified contexts.
     * All features defined within the group will be affected.
     *
     * @param string $group group name identifying which feature group to activate or deactivate
     */
    public function toGroup(string $group): void
    {
        $contextArray = is_array($this->contexts) ? $this->contexts : [$this->contexts];

        foreach ($contextArray as $context) {
            if ($this->allow) {
                $this->manager->for($context)->activateGroup($group);
            } else {
                $this->manager->for($context)->deactivateGroup($group);
            }
        }
    }

    /**
     * Get the contexts being granted/revoked access.
     *
     * Returns the context(s) configured for this permission operation. May be
     * a single context or an array of contexts.
     *
     * @return array<mixed>|mixed Single context or array of contexts
     */
    public function contexts(): mixed
    {
        return $this->contexts;
    }

    /**
     * Whether this is an allow or deny operation.
     *
     * Returns true if this conductor will activate features (allow access),
     * or false if it will deactivate features (deny access).
     *
     * @return bool true for allow operation, false for deny operation
     */
    public function isAllow(): bool
    {
        return $this->allow;
    }
}
