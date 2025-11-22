<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Repositories;

use Cline\Toggl\Contracts\SnapshotRepository;
use Cline\Toggl\Database\FeatureSnapshot;
use Cline\Toggl\FeatureManager;
use Cline\Toggl\QueryBuilder;
use Illuminate\Database\Eloquent\Model;

use function array_keys;
use function assert;
use function count;
use function explode;
use function is_int;
use function is_object;
use function is_string;
use function now;
use function str_starts_with;

/**
 * Database-backed snapshot repository implementation.
 *
 * Provides full historical tracking with dedicated tables for snapshots,
 * entries, and events. Supports granular restore and complete audit trails.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class DatabaseSnapshotRepository implements SnapshotRepository
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
        // Serialize context
        $contextKey = $this->manager->serializeContext($context);
        [$contextType, $contextId] = explode('|', $contextKey, 2);

        // Determine created_by details
        $createdByType = null;
        $createdById = null;

        if ($createdBy !== null && is_object($createdBy)) {
            assert($createdBy instanceof Model);
            $createdByType = $createdBy::class;
            $createdById = $createdBy->getKey();
        }

        // Create snapshot record
        $snapshot = QueryBuilder::featureSnapshot()->create([
            'label' => $label,
            'context_type' => $contextType,
            'context_id' => $contextId,
            'created_by_type' => $createdByType,
            'created_by_id' => $createdById,
            'created_at' => now(),
            'metadata' => $metadata,
        ]);

        // Create entries for each feature
        foreach ($features as $featureName => $featureValue) {
            QueryBuilder::snapshotEntry()->create([
                'snapshot_id' => $snapshot->id,
                'feature_name' => $featureName,
                'feature_value' => $featureValue,
                'is_active' => $featureValue !== false && $featureValue !== null,
                'created_at' => now(),
            ]);
        }

        // Record creation event
        QueryBuilder::snapshotEvent()->create([
            'snapshot_id' => $snapshot->id,
            'event_type' => 'created',
            'performed_by_type' => $createdByType,
            'performed_by_id' => $createdById,
            'metadata' => ['feature_count' => count($features)],
            'created_at' => now(),
        ]);

        $snapshotId = $snapshot->id;
        assert(is_int($snapshotId) || is_string($snapshotId));

        return (string) $snapshotId;
    }

    public function restore(
        string|int $snapshotId,
        mixed $context,
        mixed $restoredBy = null,
    ): void {
        $snapshot = $this->findSnapshot($snapshotId, $context);

        if (!$snapshot instanceof FeatureSnapshot) {
            return;
        }

        $contextDriver = $this->manager->for($context);

        // Forget all current features (except internal keys)
        $storedFeatures = $contextDriver->stored();

        foreach (array_keys($storedFeatures) as $feature) {
            if (!str_starts_with($feature, '__')) {
                $contextDriver->forget($feature);
            }
        }

        // Get all entries
        $entries = QueryBuilder::snapshotEntry()->where('snapshot_id', $snapshotId)->get();

        // Restore each feature
        $restoredFeatures = [];

        foreach ($entries as $entry) {
            if ($entry->is_active) {
                $contextDriver->activate($entry->feature_name, $entry->feature_value);
            } else {
                $contextDriver->deactivate($entry->feature_name);
            }

            $restoredFeatures[] = $entry->feature_name;
        }

        // Determine restored_by details
        $restoredByType = null;
        $restoredById = null;

        if ($restoredBy !== null && is_object($restoredBy)) {
            assert($restoredBy instanceof Model);
            $restoredByType = $restoredBy::class;
            $restoredById = $restoredBy->getKey();
        }

        // Update snapshot with restoration info
        $snapshot->update([
            'restored_at' => now(),
            'restored_by_type' => $restoredByType,
            'restored_by_id' => $restoredById,
        ]);

        // Record restoration event
        QueryBuilder::snapshotEvent()->create([
            'snapshot_id' => $snapshot->id,
            'event_type' => 'restored',
            'performed_by_type' => $restoredByType,
            'performed_by_id' => $restoredById,
            'metadata' => ['features_restored' => $restoredFeatures],
            'created_at' => now(),
        ]);
    }

    public function restorePartial(
        string|int $snapshotId,
        mixed $context,
        array $features,
        mixed $restoredBy = null,
    ): void {
        $snapshot = $this->findSnapshot($snapshotId, $context);

        if (!$snapshot instanceof FeatureSnapshot) {
            return;
        }

        // Get specific entries
        $entries = QueryBuilder::snapshotEntry()->where('snapshot_id', $snapshotId)
            ->whereIn('feature_name', $features)
            ->get();

        // Restore selected features
        $contextDriver = $this->manager->for($context);
        $restoredFeatures = [];

        foreach ($entries as $entry) {
            $featureName = $entry->feature_name;
            assert(is_string($featureName));

            if ($entry->is_active) {
                $contextDriver->activate($featureName, $entry->feature_value);
            } else {
                $contextDriver->deactivate($featureName);
            }

            $restoredFeatures[] = $featureName;
        }

        // Determine restored_by details
        $restoredByType = null;
        $restoredById = null;

        if ($restoredBy !== null && is_object($restoredBy)) {
            assert($restoredBy instanceof Model);
            $restoredByType = $restoredBy::class;
            $restoredById = $restoredBy->getKey();
        }

        // Record partial restoration event
        QueryBuilder::snapshotEvent()->create([
            'snapshot_id' => $snapshot->id,
            'event_type' => 'partial_restore',
            'performed_by_type' => $restoredByType,
            'performed_by_id' => $restoredById,
            'metadata' => [
                'features_restored' => $restoredFeatures,
                'total_features' => count($features),
            ],
            'created_at' => now(),
        ]);
    }

    public function get(string|int $snapshotId, mixed $context): ?array
    {
        $snapshot = $this->findSnapshot($snapshotId, $context);

        if (!$snapshot instanceof FeatureSnapshot) {
            return null;
        }

        $entries = QueryBuilder::snapshotEntry()->where('snapshot_id', $snapshotId)->get();

        $features = [];

        foreach ($entries as $entry) {
            $features[$entry->feature_name] = $entry->feature_value;
        }

        $snapshotId = $snapshot->id;
        assert(is_int($snapshotId) || is_string($snapshotId));

        return [
            'id' => (string) $snapshotId,
            'label' => $snapshot->label,
            'timestamp' => $snapshot->created_at->toIso8601String(),
            'features' => $features,
            'metadata' => $snapshot->metadata,
            'created_by' => $snapshot->created_by_type ? [
                'type' => $snapshot->created_by_type,
                'id' => $snapshot->created_by_id,
            ] : null,
            'restored_at' => $snapshot->restored_at?->toIso8601String(),
            'restored_by' => $snapshot->restored_by_type ? [
                'type' => $snapshot->restored_by_type,
                'id' => $snapshot->restored_by_id,
            ] : null,
        ];
    }

    public function list(mixed $context): array
    {
        $contextKey = $this->manager->serializeContext($context);
        [$contextType, $contextId] = explode('|', $contextKey, 2);

        $snapshots = QueryBuilder::featureSnapshot()->where('context_type', $contextType)
            ->where('context_id', $contextId)->latest()
            ->get();

        $result = [];

        foreach ($snapshots as $snapshot) {
            $entries = QueryBuilder::snapshotEntry()->where('snapshot_id', $snapshot->id)->get();

            $features = [];

            foreach ($entries as $entry) {
                $features[$entry->feature_name] = $entry->feature_value;
            }

            $snapshotId = $snapshot->id;
            assert(is_int($snapshotId) || is_string($snapshotId));

            $result[] = [
                'id' => (string) $snapshotId,
                'label' => $snapshot->label,
                'timestamp' => $snapshot->created_at->toIso8601String(),
                'features' => $features,
                'metadata' => $snapshot->metadata,
                'created_by' => $snapshot->created_by_type ? [
                    'type' => $snapshot->created_by_type,
                    'id' => $snapshot->created_by_id,
                ] : null,
                'restored_at' => $snapshot->restored_at?->toIso8601String(),
                'restored_by' => $snapshot->restored_by_type ? [
                    'type' => $snapshot->restored_by_type,
                    'id' => $snapshot->restored_by_id,
                ] : null,
            ];
        }

        return $result;
    }

    public function delete(string|int $snapshotId, mixed $context, mixed $deletedBy = null): void
    {
        $snapshot = $this->findSnapshot($snapshotId, $context);

        if (!$snapshot instanceof FeatureSnapshot) {
            return;
        }

        // Determine deleted_by details
        $deletedByType = null;
        $deletedById = null;

        if ($deletedBy !== null && is_object($deletedBy)) {
            assert($deletedBy instanceof Model);
            $deletedByType = $deletedBy::class;
            $deletedById = $deletedBy->getKey();
        }

        // Record deletion event before deleting
        QueryBuilder::snapshotEvent()->create([
            'snapshot_id' => $snapshot->id,
            'event_type' => 'deleted',
            'performed_by_type' => $deletedByType,
            'performed_by_id' => $deletedById,
            'metadata' => ['label' => $snapshot->label],
            'created_at' => now(),
        ]);

        // Delete snapshot (cascade will delete entries and events)
        $snapshot->delete();
    }

    public function clearAll(mixed $context, mixed $deletedBy = null): void
    {
        $contextKey = $this->manager->serializeContext($context);
        [$contextType, $contextId] = explode('|', $contextKey, 2);

        $snapshots = QueryBuilder::featureSnapshot()->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->get();

        foreach ($snapshots as $snapshot) {
            $snapshotId = $snapshot->id;
            assert(is_int($snapshotId) || is_string($snapshotId));
            $this->delete((string) $snapshotId, $context, $deletedBy);
        }
    }

    public function getEventHistory(string|int $snapshotId): array
    {
        $events = QueryBuilder::snapshotEvent()->where('snapshot_id', $snapshotId)->latest()
            ->get();

        $result = [];

        foreach ($events as $event) {
            $eventId = $event->id;
            assert(is_int($eventId) || is_string($eventId));

            $result[] = [
                'id' => (string) $eventId,
                'type' => $event->event_type,
                'performed_by' => $event->performed_by_type ? [
                    'type' => $event->performed_by_type,
                    'id' => $event->performed_by_id,
                ] : null,
                'metadata' => $event->metadata,
                'created_at' => $event->created_at->toIso8601String(),
            ];
        }

        return $result;
    }

    public function prune(int $days): int
    {
        $cutoff = now()->subDays($days);
        $deleted = 0;

        QueryBuilder::featureSnapshot()
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($snapshots) use (&$deleted): void {
                foreach ($snapshots as $snapshot) {
                    $snapshot->delete();
                    ++$deleted;
                }
            });

        return $deleted;
    }

    /**
     * Find snapshot by ID and verify it belongs to the context.
     */
    private function findSnapshot(string|int $snapshotId, mixed $context): ?FeatureSnapshot
    {
        $contextKey = $this->manager->serializeContext($context);
        [$contextType, $contextId] = explode('|', $contextKey, 2);

        $snapshot = QueryBuilder::featureSnapshot()->where('id', $snapshotId)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->first();

        return $snapshot instanceof FeatureSnapshot ? $snapshot : null;
    }
}
