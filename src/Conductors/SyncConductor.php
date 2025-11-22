<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use Cline\Toggl\FeatureManager;

/**
 * Sync conductor for complete replacement of features or groups for a context.
 *
 * Implements the sync pattern similar to Laravel's relationship syncing, where the provided
 * feature set completely replaces existing features. Any features not in the new list are
 * removed, and any in the new list are added. This ensures the context has exactly the
 * specified features, no more, no less.
 *
 * ```php
 * // Replace all features with new set
 * Toggl::sync($user)->features(['premium', 'analytics', 'notifications']);
 *
 * // Replace all group memberships
 * Toggl::sync($organization)->groups(['beta-testers', 'early-adopters']);
 *
 * // Replace with valued features
 * Toggl::sync($user)->withValues([
 *     'theme' => 'dark',
 *     'language' => 'en',
 *     'timezone' => 'UTC'
 * ]);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class SyncConductor
{
    /**
     * Create a new sync conductor instance.
     *
     * Binds a feature manager and context together for sync operations.
     *
     * @param FeatureManager $manager The feature manager instance for executing operations
     * @param mixed          $context The context entity whose features or groups will be synced
     */
    public function __construct(
        private FeatureManager $manager,
        private mixed $context,
    ) {}

    /**
     * Replace all features for the context with the specified list.
     *
     * Terminal method that removes all currently active features for the context and activates
     * only those in the provided array. Pass an empty array to remove all features.
     *
     * @param array<string> $features Complete list of features that should be active. Any
     *                                features not in this list will be deactivated.
     */
    public function features(array $features): void
    {
        // Get all currently active features across all contexts (we'll filter by checking if active for our context)
        $allFeatures = $this->manager->stored();

        // Deactivate all features that are currently active for this context
        foreach ($allFeatures as $feature) {
            if ($this->manager->for($this->context)->active($feature)) {
                $this->manager->for($this->context)->deactivate($feature);
            }
        }

        // Activate the new features
        foreach ($features as $feature) {
            $this->manager->for($this->context)->activate($feature);
        }
    }

    /**
     * Replace all group memberships for the context with the specified list.
     *
     * Terminal method that removes the context from all current groups and adds it to only
     * those in the provided array. Pass an empty array to remove from all groups.
     *
     * @param array<string> $groups Complete list of groups the context should belong to
     */
    public function groups(array $groups): void
    {
        // Get all current groups for this context
        $currentGroups = $this->manager->groups()->for($this->context)->groups();

        // Remove from all current groups
        foreach ($currentGroups as $group) {
            $this->manager->groups()->for($this->context)->unassign($group);
        }

        // Add to new groups
        foreach ($groups as $group) {
            $this->manager->groups()->for($this->context)->assign($group);
        }
    }

    /**
     * Replace all features for the context with valued feature configurations.
     *
     * Terminal method that removes all current features and activates only those specified
     * with their associated values. Useful for configuration-heavy features.
     *
     * @param array<string, mixed> $values Feature name to value mapping. Only these features
     *                                     will be active with their specified values.
     */
    public function withValues(array $values): void
    {
        // Get all currently active features across all contexts
        $allFeatures = $this->manager->stored();

        // Deactivate all features that are currently active for this context
        foreach ($allFeatures as $feature) {
            if ($this->manager->for($this->context)->active($feature)) {
                $this->manager->for($this->context)->deactivate($feature);
            }
        }

        // Activate the new features with values
        foreach ($values as $feature => $value) {
            $this->manager->for($this->context)->activate($feature, $value);
        }
    }
}
