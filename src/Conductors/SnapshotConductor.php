<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use Cline\Toggl\Facades\Snapshot;

/**
 * Conductor for restoring point-in-time feature state snapshots.
 *
 * Snapshots are automatically created via events whenever features are activated or deactivated.
 * This conductor provides methods to restore, query, and manage these automatically-captured
 * snapshots for rollback, testing, or audit purposes.
 *
 * The conductor uses a repository pattern to support multiple storage backends:
 * - Database: Full historical tracking with audit trails and granular restore
 * - Array: In-memory snapshots for testing or temporary states
 * - Cache: Cached snapshots with TTL for ephemeral states
 *
 * ```php
 * // Snapshots are created automatically when features change
 * Toggl::activate('premium')->for($user); // Auto-snapshot created!
 *
 * // Restore previous state
 * Toggl::snapshot()->restore($snapshotId, $user, restoredBy: $admin);
 *
 * // Restore specific features only
 * Toggl::snapshot()->restorePartial($snapshotId, $user, ['feature-a', 'feature-b']);
 *
 * // List all snapshots
 * $snapshots = Toggl::snapshot()->list($user);
 *
 * // Get event history
 * $events = Toggl::snapshot()->getEventHistory($snapshotId);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class SnapshotConductor
{
    /**
     * Restore feature state from a previously captured snapshot.
     *
     * Deactivates all current features and reactivates only those stored in the snapshot
     * with their original values. This completely replaces the context's feature state.
     *
     * @param int|string $snapshotId the snapshot ID to restore from
     * @param mixed      $context    the context to restore feature state to
     * @param null|mixed $restoredBy who restored the snapshot (for audit trail)
     */
    public function restore(string|int $snapshotId, mixed $context, mixed $restoredBy = null): void
    {
        Snapshot::restore($snapshotId, $context, $restoredBy);
    }

    /**
     * Restore specific features from a snapshot.
     *
     * Unlike restore(), this only restores the specified features from the snapshot,
     * leaving other features unchanged. Useful for granular rollbacks.
     *
     * @param int|string         $snapshotId the snapshot ID to restore from
     * @param mixed              $context    the context to restore feature state to
     * @param array<int, string> $features   feature names to restore
     * @param null|mixed         $restoredBy who restored the snapshot (for audit trail)
     */
    public function restorePartial(
        string|int $snapshotId,
        mixed $context,
        array $features,
        mixed $restoredBy = null,
    ): void {
        Snapshot::restorePartial($snapshotId, $context, $features, $restoredBy);
    }

    /**
     * List all snapshots for context.
     *
     * @param  mixed                            $context the context to list snapshots for
     * @return array<int, array<string, mixed>> array of all snapshots
     */
    public function list(mixed $context): array
    {
        /** @var array<int, array<string, mixed>> */
        return Snapshot::list($context);
    }

    /**
     * Delete a snapshot.
     *
     * @param string     $snapshotId snapshot ID to delete
     * @param mixed      $context    the context to delete snapshot from
     * @param null|mixed $deletedBy  who deleted the snapshot (for audit trail)
     */
    public function delete(string $snapshotId, mixed $context, mixed $deletedBy = null): void
    {
        Snapshot::delete($snapshotId, $context, $deletedBy);
    }

    /**
     * Get a specific snapshot.
     *
     * @param  string                    $snapshotId snapshot ID to retrieve
     * @param  mixed                     $context    the context to get snapshot from
     * @return null|array<string, mixed> snapshot data or null if not found
     */
    public function get(string $snapshotId, mixed $context): ?array
    {
        /** @var null|array<string, mixed> */
        return Snapshot::get($snapshotId, $context);
    }

    /**
     * Clear all snapshots for context.
     *
     * @param mixed      $context   the context to clear snapshots for
     * @param null|mixed $deletedBy who deleted the snapshots (for audit trail)
     */
    public function clearAll(mixed $context, mixed $deletedBy = null): void
    {
        Snapshot::clearAll($context, $deletedBy);
    }

    /**
     * Get event history for a snapshot.
     *
     * Returns audit trail of all operations performed on the snapshot
     * (created, restored, deleted, partial_restore).
     *
     * @param  int|string                       $snapshotId snapshot ID
     * @return array<int, array<string, mixed>> array of event history entries
     */
    public function getEventHistory(string|int $snapshotId): array
    {
        /** @var array<int, array<string, mixed>> */
        return Snapshot::getEventHistory($snapshotId);
    }
}
