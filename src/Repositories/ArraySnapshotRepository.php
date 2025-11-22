<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Repositories;

use Cline\Toggl\Contracts\SnapshotRepository;
use Cline\Toggl\FeatureManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

use function array_key_exists;
use function array_keys;
use function array_values;
use function assert;
use function count;
use function is_array;
use function is_object;
use function now;
use function str_replace;
use function str_starts_with;
use function uniqid;

/**
 * Array-backed snapshot repository implementation.
 *
 * Stores snapshots in Laravel cache (array driver) using dedicated cache keys.
 * Octane-safe: uses instance-based cache storage, not static properties.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class ArraySnapshotRepository implements SnapshotRepository
{
    /**
     * Create a new array snapshot repository instance.
     *
     * @param FeatureManager $manager The feature manager for context serialization and driver access
     */
    public function __construct(
        private FeatureManager $manager,
    ) {}

    /**
     * Create a new snapshot for a context.
     *
     * Captures the current state of all specified features for the given context
     * and stores them in memory using Laravel's array cache driver. The snapshot
     * includes metadata and user attribution for audit purposes.
     *
     * @param  mixed                     $context   The context (user, team, etc.) to snapshot
     * @param  array<string, mixed>      $features  Feature names and their current values to capture
     * @param  null|string               $label     Optional human-readable label for the snapshot
     * @param  mixed                     $createdBy Optional user/model that created the snapshot
     * @param  null|array<string, mixed> $metadata  Optional additional metadata to store
     * @return string                    The unique snapshot ID for later restoration
     */
    public function create(
        mixed $context,
        array $features,
        ?string $label = null,
        mixed $createdBy = null,
        ?array $metadata = null,
    ): string {
        $contextKey = $this->manager->serializeContext($context);
        $snapshotId = $this->generateId();

        $timestamp = now()->toIso8601String();

        // Determine created_by details
        $createdByInfo = null;

        if ($createdBy !== null && is_object($createdBy)) {
            assert($createdBy instanceof Model);
            $createdByInfo = [
                'type' => $createdBy::class,
                'id' => $createdBy->getKey(),
            ];
        }

        $snapshot = [
            'id' => $snapshotId,
            'label' => $label,
            'context_key' => $contextKey,
            'timestamp' => $timestamp,
            'features' => $features,
            'metadata' => $metadata,
            'created_by' => $createdByInfo,
            'created_at' => $timestamp,
            'restored_at' => null,
            'restored_by' => null,
            'events' => [
                [
                    'id' => $this->generateId(),
                    'type' => 'created',
                    'performed_by' => $createdByInfo,
                    'metadata' => ['feature_count' => count($features)],
                    'created_at' => $timestamp,
                ],
            ],
        ];

        $this->saveSnapshot($context, $snapshotId, $snapshot);

        return $snapshotId;
    }

    /**
     * Restore a snapshot completely, replacing all current features.
     *
     * Deactivates all current features (except internal keys starting with __)
     * and restores the exact feature state from the snapshot. Records a restoration
     * event in the snapshot's history for audit purposes.
     *
     * @param string $snapshotId The snapshot ID to restore from
     * @param mixed  $context    The context to restore features for
     * @param mixed  $restoredBy Optional user/model performing the restoration
     */
    public function restore(
        string|int $snapshotId,
        mixed $context,
        mixed $restoredBy = null,
    ): void {
        $snapshot = $this->get($snapshotId, $context);

        if ($snapshot === null) {
            return;
        }

        $contextDriver = $this->manager->for($context);

        // Deactivate all current features (except internal keys)
        $storedFeatures = $contextDriver->stored();

        foreach (array_keys($storedFeatures) as $feature) {
            if (!str_starts_with((string) $feature, '__')) {
                $contextDriver->deactivate($feature);
            }
        }

        // Restore features from snapshot
        $restoredFeatures = [];

        assert(is_array($snapshot['features']));

        foreach ($snapshot['features'] as $featureName => $featureValue) {
            if ($featureValue !== false && $featureValue !== null) {
                $contextDriver->activate($featureName, $featureValue);
            } else {
                $contextDriver->deactivate($featureName);
            }

            $restoredFeatures[] = $featureName;
        }

        // Determine restored_by details
        $restoredByInfo = null;

        if ($restoredBy !== null && is_object($restoredBy)) {
            assert($restoredBy instanceof Model);
            $restoredByInfo = [
                'type' => $restoredBy::class,
                'id' => $restoredBy->getKey(),
            ];
        }

        // Update snapshot with restoration info
        $snapshot['restored_at'] = now()->toIso8601String();
        $snapshot['restored_by'] = $restoredByInfo;

        // Add restoration event
        assert(is_array($snapshot['events']));
        $snapshot['events'][] = [
            'id' => $this->generateId(),
            'type' => 'restored',
            'performed_by' => $restoredByInfo,
            'metadata' => ['features_restored' => $restoredFeatures],
            'created_at' => now()->toIso8601String(),
        ];

        $this->saveSnapshot($context, (string) $snapshotId, $snapshot);
    }

    /**
     * Restore specific features from a snapshot.
     *
     * Only restores the specified features from the snapshot, leaving other
     * features unchanged. Useful for selectively reverting specific changes
     * without affecting the entire feature set.
     *
     * @param string        $snapshotId The snapshot ID to restore from
     * @param mixed         $context    The context to restore features for
     * @param array<string> $features   Feature names to restore from the snapshot
     * @param mixed         $restoredBy Optional user/model performing the restoration
     */
    public function restorePartial(
        string|int $snapshotId,
        mixed $context,
        array $features,
        mixed $restoredBy = null,
    ): void {
        $snapshot = $this->get($snapshotId, $context);

        if ($snapshot === null) {
            return;
        }

        $contextDriver = $this->manager->for($context);
        $restoredFeatures = [];

        assert(is_array($snapshot['features']));

        foreach ($features as $featureName) {
            if (!array_key_exists($featureName, $snapshot['features'])) {
                continue;
            }

            $featureValue = $snapshot['features'][$featureName];

            if ($featureValue !== false && $featureValue !== null) {
                $contextDriver->activate($featureName, $featureValue);
            } else {
                $contextDriver->deactivate($featureName);
            }

            $restoredFeatures[] = $featureName;
        }

        // Determine restored_by details
        $restoredByInfo = null;

        if ($restoredBy !== null && is_object($restoredBy)) {
            assert($restoredBy instanceof Model);
            $restoredByInfo = [
                'type' => $restoredBy::class,
                'id' => $restoredBy->getKey(),
            ];
        }

        // Add partial restoration event
        assert(is_array($snapshot['events']));
        $snapshot['events'][] = [
            'id' => $this->generateId(),
            'type' => 'partial_restore',
            'performed_by' => $restoredByInfo,
            'metadata' => [
                'features_restored' => $restoredFeatures,
                'total_features' => count($features),
            ],
            'created_at' => now()->toIso8601String(),
        ];

        $this->saveSnapshot($context, (string) $snapshotId, $snapshot);
    }

    /**
     * Retrieve a specific snapshot by ID.
     *
     * @param  string                    $snapshotId The snapshot ID to retrieve
     * @param  mixed                     $context    The context the snapshot belongs to
     * @return null|array<string, mixed> The snapshot data, or null if not found
     */
    public function get(string|int $snapshotId, mixed $context): ?array
    {
        $snapshots = $this->getAllSnapshots($context);

        return $snapshots[$snapshotId] ?? null;
    }

    /**
     * List all snapshots for a context.
     *
     * @param  mixed                            $context The context to list snapshots for
     * @return array<int, array<string, mixed>> Array of snapshot data structures
     */
    public function list(mixed $context): array
    {
        return array_values($this->getAllSnapshots($context));
    }

    /**
     * Delete a specific snapshot.
     *
     * Records a deletion event in the snapshot's history before removing it
     * from storage. If this was the last snapshot for the context, removes
     * the cache key entirely.
     *
     * @param string $snapshotId The snapshot ID to delete
     * @param mixed  $context    The context the snapshot belongs to
     * @param mixed  $deletedBy  Optional user/model performing the deletion
     */
    public function delete(string|int $snapshotId, mixed $context, mixed $deletedBy = null): void
    {
        $snapshot = $this->get($snapshotId, $context);

        if ($snapshot === null) {
            return;
        }

        // Determine deleted_by details
        $deletedByInfo = null;

        if ($deletedBy !== null && is_object($deletedBy)) {
            assert($deletedBy instanceof Model);
            $deletedByInfo = [
                'type' => $deletedBy::class,
                'id' => $deletedBy->getKey(),
            ];
        }

        // Add deletion event before deleting
        assert(is_array($snapshot['events']));
        $snapshot['events'][] = [
            'id' => $this->generateId(),
            'type' => 'deleted',
            'performed_by' => $deletedByInfo,
            'metadata' => ['label' => $snapshot['label']],
            'created_at' => now()->toIso8601String(),
        ];

        $this->saveSnapshot($context, (string) $snapshotId, $snapshot);

        // Now remove it
        $contextKey = $this->manager->serializeContext($context);
        $cacheKey = 'toggl:snapshots:'.$contextKey;

        $snapshots = $this->getAllSnapshots($context);
        unset($snapshots[$snapshotId]);

        if ($snapshots === []) {
            Cache::driver('array')->forget($cacheKey);
        } else {
            Cache::driver('array')->put($cacheKey, $snapshots);
        }
    }

    /**
     * Delete all snapshots for a context.
     *
     * Iterates through all snapshots for the context and deletes each one,
     * recording individual deletion events for each snapshot.
     *
     * @param mixed $context   The context to clear snapshots for
     * @param mixed $deletedBy Optional user/model performing the deletion
     */
    public function clearAll(mixed $context, mixed $deletedBy = null): void
    {
        $snapshots = $this->getAllSnapshots($context);

        foreach (array_keys($snapshots) as $snapshotId) {
            $this->delete((string) $snapshotId, $context, $deletedBy);
        }
    }

    /**
     * Get the event history for a snapshot.
     *
     * Array driver limitation: cannot retrieve event history without context.
     * Use database driver for full event history support.
     *
     * @param  string                           $snapshotId The snapshot ID to get events for
     * @return array<int, array<string, mixed>> Empty array (not supported by array driver)
     */
    public function getEventHistory(string|int $snapshotId): array
    {
        // For array driver, we can't get event history without context
        // This is a limitation of the array driver
        return [];
    }

    /**
     * Prune old snapshots.
     *
     * Array driver limitation: snapshots only exist in memory for the current request
     * and don't persist, so pruning is not applicable.
     *
     * @param  int $days Number of days to retain snapshots
     * @return int Number of snapshots pruned (always 0 for array driver)
     */
    public function prune(int $days): int
    {
        // Array driver stores snapshots in memory per-request
        // Pruning doesn't apply since snapshots don't persist across requests
        return 0;
    }

    /**
     * Get all snapshots for a context from cache.
     *
     * Retrieves the snapshot collection from the array cache using the
     * serialized context key. Returns empty array if no snapshots exist.
     *
     * @param  mixed                               $context The context to get snapshots for
     * @return array<string, array<string, mixed>> Map of snapshot IDs to snapshot data
     */
    private function getAllSnapshots(mixed $context): array
    {
        $contextKey = $this->manager->serializeContext($context);
        $cacheKey = 'toggl:snapshots:'.$contextKey;

        $snapshots = Cache::driver('array')->get($cacheKey);

        if (!is_array($snapshots)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $snapshots */
        return $snapshots;
    }

    /**
     * Save a snapshot to array cache storage.
     *
     * Persists the snapshot data to the array cache, merging it with existing
     * snapshots for the context. Uses the snapshot ID as the array key.
     *
     * @param mixed                $context    The context the snapshot belongs to
     * @param string               $snapshotId The unique snapshot identifier
     * @param array<string, mixed> $snapshot   The complete snapshot data structure
     */
    private function saveSnapshot(mixed $context, string $snapshotId, array $snapshot): void
    {
        $contextKey = $this->manager->serializeContext($context);
        $cacheKey = 'toggl:snapshots:'.$contextKey;

        $snapshots = $this->getAllSnapshots($context);
        $snapshots[$snapshotId] = $snapshot;

        Cache::driver('array')->put($cacheKey, $snapshots);
    }

    /**
     * Generate a unique ID for snapshots and events.
     *
     * Creates a unique identifier using uniqid() with more entropy,
     * then removes dots for cleaner IDs.
     *
     * @return string The generated unique ID
     */
    private function generateId(): string
    {
        return str_replace('.', '', uniqid('snapshot_', true));
    }
}
