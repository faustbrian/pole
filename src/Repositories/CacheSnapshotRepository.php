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
use Illuminate\Support\Facades\Config;

use function array_key_exists;
use function array_keys;
use function array_values;
use function assert;
use function count;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function now;
use function str_replace;
use function str_starts_with;
use function uniqid;

/**
 * Cache-backed snapshot repository implementation.
 *
 * Stores snapshots in Redis using cache tags for data separation.
 * Maintains full event history in cache for the TTL duration.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class CacheSnapshotRepository implements SnapshotRepository
{
    public function __construct(
        private FeatureManager $manager,
    ) {}

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

        $createdByType = null;
        $createdById = null;

        if ($createdBy !== null && is_object($createdBy)) {
            assert($createdBy instanceof Model);
            $createdByType = $createdBy::class;
            $createdById = $createdBy->getKey();
            assert(is_string($createdById) || is_int($createdById));
        }

        $snapshot = [
            'id' => $snapshotId,
            'label' => $label,
            'context_key' => $contextKey,
            'timestamp' => $timestamp,
            'features' => $features,
            'metadata' => $metadata,
            'created_by' => $createdBy !== null ? [
                'type' => $createdByType,
                'id' => $createdById,
            ] : null,
            'created_at' => $timestamp,
            'restored_at' => null,
            'restored_by' => null,
            'events' => [
                [
                    'id' => $this->generateId(),
                    'type' => 'created',
                    'performed_by' => $createdBy !== null ? [
                        'type' => $createdByType,
                        'id' => $createdById,
                    ] : null,
                    'metadata' => ['feature_count' => count($features)],
                    'created_at' => $timestamp,
                ],
            ],
        ];

        $this->saveSnapshot($context, $snapshotId, $snapshot);

        return $snapshotId;
    }

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
            if (!str_starts_with($feature, '__')) {
                $contextDriver->deactivate($feature);
            }
        }

        // Restore features from snapshot
        $restoredFeatures = [];

        $snapshotFeatures = $snapshot['features'];
        assert(is_array($snapshotFeatures));

        foreach ($snapshotFeatures as $featureName => $featureValue) {
            assert(is_string($featureName));

            if ($featureValue !== false && $featureValue !== null) {
                $contextDriver->activate($featureName, $featureValue);
            } else {
                $contextDriver->deactivate($featureName);
            }

            $restoredFeatures[] = $featureName;
        }

        $restoredByType = null;
        $restoredById = null;

        if ($restoredBy !== null && is_object($restoredBy)) {
            assert($restoredBy instanceof Model);
            $restoredByType = $restoredBy::class;
            $restoredById = $restoredBy->getKey();
            assert(is_string($restoredById) || is_int($restoredById));
        }

        // Update snapshot with restoration info
        $snapshot['restored_at'] = now()->toIso8601String();
        $snapshot['restored_by'] = $restoredBy !== null ? [
            'type' => $restoredByType,
            'id' => $restoredById,
        ] : null;

        // Add restoration event
        assert(is_array($snapshot['events']));

        $snapshot['events'][] = [
            'id' => $this->generateId(),
            'type' => 'restored',
            'performed_by' => $restoredBy !== null ? [
                'type' => $restoredByType,
                'id' => $restoredById,
            ] : null,
            'metadata' => ['features_restored' => $restoredFeatures],
            'created_at' => now()->toIso8601String(),
        ];

        $this->saveSnapshot($context, (string) $snapshotId, $snapshot);
    }

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

        $snapshotFeatures = $snapshot['features'];
        assert(is_array($snapshotFeatures));

        foreach ($features as $featureName) {
            if (!array_key_exists($featureName, $snapshotFeatures)) {
                continue;
            }

            $featureValue = $snapshotFeatures[$featureName];

            if ($featureValue !== false && $featureValue !== null) {
                $contextDriver->activate($featureName, $featureValue);
            } else {
                $contextDriver->deactivate($featureName);
            }

            $restoredFeatures[] = $featureName;
        }

        $restoredByType = null;
        $restoredById = null;

        if ($restoredBy !== null && is_object($restoredBy)) {
            assert($restoredBy instanceof Model);
            $restoredByType = $restoredBy::class;
            $restoredById = $restoredBy->getKey();
            assert(is_string($restoredById) || is_int($restoredById));
        }

        // Add partial restoration event
        assert(is_array($snapshot['events']));

        $snapshot['events'][] = [
            'id' => $this->generateId(),
            'type' => 'partial_restore',
            'performed_by' => $restoredBy !== null ? [
                'type' => $restoredByType,
                'id' => $restoredById,
            ] : null,
            'metadata' => [
                'features_restored' => $restoredFeatures,
                'total_features' => count($features),
            ],
            'created_at' => now()->toIso8601String(),
        ];

        $this->saveSnapshot($context, (string) $snapshotId, $snapshot);
    }

    public function get(string|int $snapshotId, mixed $context): ?array
    {
        $snapshots = $this->getAllSnapshots($context);

        return $snapshots[$snapshotId] ?? null;
    }

    public function list(mixed $context): array
    {
        return array_values($this->getAllSnapshots($context));
    }

    public function delete(string|int $snapshotId, mixed $context, mixed $deletedBy = null): void
    {
        $snapshot = $this->get($snapshotId, $context);

        if ($snapshot === null) {
            return;
        }

        $deletedByType = null;
        $deletedById = null;

        if ($deletedBy !== null && is_object($deletedBy)) {
            assert($deletedBy instanceof Model);
            $deletedByType = $deletedBy::class;
            $deletedById = $deletedBy->getKey();
            assert(is_string($deletedById) || is_int($deletedById));
        }

        // Add deletion event before deleting
        assert(is_array($snapshot['events']));

        $snapshot['events'][] = [
            'id' => $this->generateId(),
            'type' => 'deleted',
            'performed_by' => $deletedBy !== null ? [
                'type' => $deletedByType,
                'id' => $deletedById,
            ] : null,
            'metadata' => ['label' => $snapshot['label']],
            'created_at' => now()->toIso8601String(),
        ];

        $this->saveSnapshot($context, (string) $snapshotId, $snapshot);

        // Now remove it
        $contextKey = $this->manager->serializeContext($context);
        $cacheKey = 'toggl:snapshots:'.$contextKey;
        $tag = 'toggl:snapshots';

        $snapshots = $this->getAllSnapshots($context);
        unset($snapshots[$snapshotId]);

        if ($snapshots === []) {
            Cache::tags($tag)->forget($cacheKey);
        } else {
            // Preserve TTL based on pruning retention config
            /** @var int $retentionDays */
            $retentionDays = Config::integer('toggl.pruning.retention_days', 365);
            $ttlSeconds = $retentionDays > 0 ? $retentionDays * 86_400 : null;

            Cache::tags($tag)->put($cacheKey, $snapshots, $ttlSeconds);
        }
    }

    public function clearAll(mixed $context, mixed $deletedBy = null): void
    {
        $snapshots = $this->getAllSnapshots($context);

        foreach (array_keys($snapshots) as $snapshotId) {
            $this->delete($snapshotId, $context, $deletedBy);
        }
    }

    public function getEventHistory(string|int $snapshotId): array
    {
        // For cache driver, we can't get event history without context
        // This is a limitation of the cache driver
        return [];
    }

    public function prune(int $days): int
    {
        // Cache driver uses TTL based on pruning.retention_days config.
        // Snapshots auto-expire, so manual pruning is not needed.
        // Returns 0 since we cannot enumerate cached contexts.
        return 0;
    }

    /**
     * Get all snapshots for a context.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getAllSnapshots(mixed $context): array
    {
        $contextKey = $this->manager->serializeContext($context);
        $cacheKey = 'toggl:snapshots:'.$contextKey;
        $tag = 'toggl:snapshots';

        /** @var null|array<string, array<string, mixed>> $snapshots */
        $snapshots = Cache::tags($tag)->get($cacheKey);

        return $snapshots ?? [];
    }

    /**
     * Save a snapshot to storage.
     *
     * @param array<string, mixed> $snapshot
     */
    private function saveSnapshot(mixed $context, string $snapshotId, array $snapshot): void
    {
        $contextKey = $this->manager->serializeContext($context);
        $cacheKey = 'toggl:snapshots:'.$contextKey;
        $tag = 'toggl:snapshots';

        $snapshots = $this->getAllSnapshots($context);
        $snapshots[$snapshotId] = $snapshot;

        // Use pruning retention days as TTL (converted to seconds)
        /** @var int $retentionDays */
        $retentionDays = Config::integer('toggl.pruning.retention_days', 365);
        $ttlSeconds = $retentionDays > 0 ? $retentionDays * 86_400 : null;

        Cache::tags($tag)->put($cacheKey, $snapshots, $ttlSeconds);
    }

    /**
     * Generate a unique ID for snapshots and events.
     */
    private function generateId(): string
    {
        return str_replace('.', '', uniqid('snapshot_', true));
    }
}
