<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

/**
 * Contract for snapshot repository implementations.
 *
 * Defines the interface for storing and retrieving feature flag snapshots with full
 * historical tracking and audit capabilities. Snapshots capture the complete state of
 * feature flags at a specific point in time, enabling rollback, comparison, and audit
 * trail functionality.
 *
 * Snapshots are particularly useful for:
 * - Creating restore points before major feature changes
 * - A/B test baseline capture and restoration
 * - Compliance and audit requirements
 * - Debugging feature flag state issues
 * - Temporary feature state experiments with guaranteed rollback
 *
 * Each snapshot maintains:
 * - The complete feature flag state for a context
 * - Metadata about who created it and when
 * - Optional labels for easy identification
 * - Full event history of create/restore/delete operations
 *
 * ```php
 * // Create snapshot before major changes
 * $snapshotId = $repository->create(
 *     context: $team,
 *     features: ['new-ui' => true, 'beta-features' => false],
 *     label: 'Before Q4 rollout',
 *     createdBy: $admin
 * );
 *
 * // Restore if something goes wrong
 * $repository->restore($snapshotId, $team, $admin);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface SnapshotRepository
{
    /**
     * Create a new snapshot for the given context.
     *
     * Captures the current state of specified features for the given context,
     * creating a point-in-time record that can be restored later. The snapshot
     * includes the feature values, creation timestamp, and optional metadata.
     *
     * @param  mixed                     $context   The context to snapshot (user, team, organization, etc.)
     * @param  array<string, mixed>      $features  Feature name-value pairs to capture in the snapshot
     * @param  null|string               $label     Optional human-readable label for easy identification
     * @param  null|mixed                $createdBy Who created the snapshot (user ID, admin object, etc.)
     * @param  null|array<string, mixed> $metadata  Optional additional metadata (reason, ticket number, etc.)
     * @return string                    Unique snapshot identifier for future restore/retrieve operations
     */
    public function create(
        mixed $context,
        array $features,
        ?string $label = null,
        mixed $createdBy = null,
        ?array $metadata = null,
    ): string;

    /**
     * Restore a snapshot for the given context.
     *
     * Restores all features from the specified snapshot to the given context,
     * effectively rolling back to the state captured when the snapshot was created.
     * This operation overwrites current feature values with snapshot values.
     *
     * The restore operation is logged in the snapshot's event history for audit purposes.
     *
     * @param int|string $snapshotId Unique identifier of the snapshot to restore
     * @param mixed      $context    Context to restore the snapshot to (must match snapshot's original context)
     * @param null|mixed $restoredBy Who performed the restore operation (for audit trail)
     */
    public function restore(
        string|int $snapshotId,
        mixed $context,
        mixed $restoredBy = null,
    ): void;

    /**
     * Restore specific features from a snapshot.
     *
     * Performs a partial restore, applying only the specified features from the
     * snapshot while leaving other features unchanged. This is useful when you
     * want to selectively rollback specific features without affecting others.
     *
     * @param int|string         $snapshotId Unique identifier of the snapshot
     * @param mixed              $context    Context to restore to
     * @param array<int, string> $features   Array of feature names to restore from the snapshot
     * @param null|mixed         $restoredBy Who performed the partial restore (for audit trail)
     */
    public function restorePartial(
        string|int $snapshotId,
        mixed $context,
        array $features,
        mixed $restoredBy = null,
    ): void;

    /**
     * Get a snapshot by ID.
     *
     * Retrieves the complete snapshot data including features, metadata, and
     * creation information. Returns null if the snapshot doesn't exist or doesn't
     * belong to the specified context.
     *
     * @param  int|string                $snapshotId Unique snapshot identifier
     * @param  mixed                     $context    Context that owns the snapshot
     * @return null|array<string, mixed> Snapshot data including features, label, metadata, timestamps, or null if not found
     */
    public function get(string|int $snapshotId, mixed $context): ?array;

    /**
     * List all snapshots for a context.
     *
     * Returns all snapshots belonging to the specified context, ordered by creation
     * time (most recent first). Each entry includes snapshot metadata but not the
     * full feature state (use get() to retrieve complete snapshot details).
     *
     * @param  mixed                            $context Context to list snapshots for
     * @return array<int, array<string, mixed>> array of snapshot summaries with ID, label, created_at, created_by, etc
     */
    public function list(mixed $context): array;

    /**
     * Delete a snapshot.
     *
     * Permanently removes a snapshot from storage. The snapshot cannot be restored
     * after deletion. This operation is logged in the event history before removal.
     *
     * Use with caution in production environments; consider soft-delete strategies
     * if snapshots are subject to compliance or audit requirements.
     *
     * @param int|string $snapshotId Unique snapshot identifier to delete
     * @param mixed      $context    Context that owns the snapshot
     * @param null|mixed $deletedBy  Who performed the deletion (for audit trail)
     */
    public function delete(string|int $snapshotId, mixed $context, mixed $deletedBy = null): void;

    /**
     * Clear all snapshots for a context.
     *
     * Removes all snapshots belonging to the specified context. This is a bulk
     * deletion operation, useful for cleanup when removing contexts (users, teams)
     * from the system entirely.
     *
     * Warning: This operation is destructive and cannot be undone. All restore
     * points for the context will be permanently lost.
     *
     * @param mixed      $context   Context whose snapshots should be deleted
     * @param null|mixed $deletedBy Who performed the bulk deletion (for audit trail)
     */
    public function clearAll(mixed $context, mixed $deletedBy = null): void;

    /**
     * Get event history for a snapshot.
     *
     * Retrieves the complete audit trail for a snapshot, including create, restore,
     * partial restore, and delete events. Each event includes timestamp, actor, and
     * operation details.
     *
     * This is essential for compliance, debugging, and understanding the snapshot's
     * lifecycle and usage patterns.
     *
     * @param  int|string                       $snapshotId Unique snapshot identifier
     * @return array<int, array<string, mixed>> Event history array with timestamp, event_type, actor, details for each event
     */
    public function getEventHistory(string|int $snapshotId): array;

    /**
     * Prune snapshots older than the specified number of days.
     *
     * Removes snapshots created before the retention threshold, helping manage
     * storage costs and comply with data retention policies. This operation is
     * typically run via scheduled cleanup jobs.
     *
     * The pruning operation respects any implementation-specific retention rules,
     * such as keeping labeled snapshots longer or preserving recent snapshots.
     *
     * @param  int $days Number of days to retain snapshots (snapshots older than this are deleted)
     * @return int Number of snapshots deleted during the pruning operation
     */
    public function prune(int $days): int;
}
