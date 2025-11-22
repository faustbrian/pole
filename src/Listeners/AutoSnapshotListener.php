<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Listeners;

use Cline\Toggl\Events\FeatureActivated;
use Cline\Toggl\Events\FeatureDeactivated;
use Cline\Toggl\Facades\Snapshot;
use Cline\Toggl\Toggl;
use Illuminate\Support\Facades\Config;

use function now;
use function sprintf;
use function str_starts_with;

/**
 * Example listener for automatic snapshot creation on feature changes.
 *
 * This listener demonstrates how to automatically capture snapshots whenever
 * features are activated or deactivated. This provides an automatic audit trail
 * of all feature flag changes without requiring manual snapshot calls.
 *
 * To enable automatic snapshots, register this listener in your EventServiceProvider:
 *
 * ```php
 * use Cline\Toggl\Events\FeatureActivated;
 * use Cline\Toggl\Events\FeatureDeactivated;
 * use Cline\Toggl\Listeners\AutoSnapshotListener;
 *
 * protected $listen = [
 *     FeatureActivated::class => [
 *         AutoSnapshotListener::class,
 *     ],
 *     FeatureDeactivated::class => [
 *         AutoSnapshotListener::class,
 *     ],
 * ];
 * ```
 *
 * Or use event discovery with the #[Subscribes] attribute (Laravel 11+).
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class AutoSnapshotListener
{
    /**
     * Handle feature activation/deactivation events.
     *
     * Creates a snapshot when a feature is activated or deactivated, capturing
     * the complete current state for audit and rollback purposes.
     *
     * @param FeatureActivated|FeatureDeactivated $event The feature event
     */
    public function handle(FeatureActivated|FeatureDeactivated $event): void
    {
        // Check if snapshots are enabled
        if (!Config::boolean('toggl.snapshots.enabled', true)) {
            return;
        }

        // Only auto-snapshot for specific contexts (e.g., user models)
        // This prevents creating snapshots for global or system-level changes
        if (!$this->shouldCreateSnapshot($event)) {
            return;
        }

        // Get all stored features with their values
        $storedFeatures = Toggl::for($event->context)->stored();
        $features = [];

        foreach ($storedFeatures as $feature => $value) {
            // Skip internal keys (like __snapshots__, __audit__, etc.)
            if (str_starts_with((string) $feature, '__')) {
                continue;
            }

            $features[$feature] = $value;
        }

        // Create snapshot with descriptive label
        $action = $event instanceof FeatureActivated ? 'activated' : 'deactivated';
        $label = sprintf('auto-%s-%s', $action, $event->feature);

        Snapshot::create(
            context: $event->context,
            features: $features,
            label: $label,
            createdBy: null, // Could pass authenticated user here
            metadata: [
                'auto_created' => true,
                'event_type' => $action,
                'feature' => $event->feature,
                'timestamp' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Determine if a snapshot should be created for this event.
     *
     * Implement your own logic here to control when automatic snapshots
     * are created. For example:
     * - Only for certain feature names
     * - Only for user contexts (not global changes)
     * - Only during business hours
     * - Only in production
     *
     * @param  FeatureActivated|FeatureDeactivated $event The feature event
     * @return bool                                True if snapshot should be created
     */
    private function shouldCreateSnapshot(FeatureActivated|FeatureDeactivated $event): bool
    {
        // Don't snapshot internal features
        // Example: Only snapshot in production
        // if (!app()->isProduction()) {
        //     return false;
        // }
        // Example: Only snapshot specific features
        // $criticalFeatures = ['premium', 'billing', 'admin-access'];
        // if (!in_array($event->feature, $criticalFeatures, true)) {
        //     return false;
        // }
        return !str_starts_with($event->feature, '__');
    }
}
