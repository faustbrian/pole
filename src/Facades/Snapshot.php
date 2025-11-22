<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Facades;

use Cline\Toggl\Contracts\SnapshotRepository;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for snapshot repository operations.
 *
 * Provides a convenient static interface for capturing, restoring, and managing
 * feature flag state snapshots. Snapshots allow you to preserve and restore
 * feature configurations at specific points in time for backup, rollback, or
 * testing scenarios.
 *
 * @method static string                           create(mixed $context, array<string, mixed> $features, ?string $label = null, mixed $createdBy = null, ?array<string, mixed> $metadata = null) Create a new snapshot of feature states for a context. Returns the snapshot ID.
 * @method static void                             restore(string|int $snapshotId, mixed $context, mixed $restoredBy = null)                                                                      Restore all features from a snapshot to the specified context.
 * @method static void                             restorePartial(string|int $snapshotId, mixed $context, array<int, string> $features, mixed $restoredBy = null)                                 Restore only specific features from a snapshot to the context.
 * @method static array<string, mixed>|null        get(string|int $snapshotId, mixed $context)                                                                                                    Retrieve the feature data stored in a snapshot. Returns null if not found.
 * @method static array<int, array<string, mixed>> list(mixed $context)                                                                                                                           List all snapshots for a given context.
 * @method static void                             delete(string|int $snapshotId, mixed $context, mixed $deletedBy = null)                                                                        Delete a specific snapshot.
 * @method static void                             clearAll(mixed $context, mixed $deletedBy = null)                                                                                              Delete all snapshots for a context.
 * @method static array<int, array<string, mixed>> getEventHistory(string|int $snapshotId)                                                                                                        Retrieve the event history for a snapshot.
 * @method static int                              prune(int $days)                                                                                                                               Delete snapshots older than the specified number of days. Returns count of deleted snapshots.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see SnapshotRepository
 */
final class Snapshot extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string The service container binding key
     */
    protected static function getFacadeAccessor(): string
    {
        return SnapshotRepository::class;
    }
}
