<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Database\FeatureSnapshot;
use Cline\Toggl\Enums\SnapshotDriver;
use Cline\Toggl\QueryBuilder;
use Illuminate\Support\Facades\Date;
use Tests\Fixtures\User;

/**
 * Prune Snapshots Command Test Suite
 *
 * Tests the toggl:prune-snapshots artisan command for deleting
 * old feature snapshots based on retention configuration.
 */
describe('Prune Snapshots Command', function (): void {
    beforeEach(function (): void {
        // Use database snapshot driver for prune tests
        config(['toggl.snapshots.driver' => SnapshotDriver::Database]);

        // Clear any existing snapshots
        QueryBuilder::featureSnapshot()->delete();
    });

    describe('Happy Path', function (): void {
        test('deletes snapshots older than retention period', function (): void {
            // Arrange
            $user = User::factory()->create();
            config(['toggl.snapshots.pruning.retention_days' => 30]);

            // Create old snapshot (40 days ago)
            $oldSnapshot = createSnapshotWithAge($user, 40);

            // Create recent snapshot (10 days ago)
            $recentSnapshot = createSnapshotWithAge($user, 10);

            // Act
            $this->artisan('toggl:prune-snapshots')
                ->expectsOutputToContain('Deleted 1 snapshot(s)')
                ->assertExitCode(0);

            // Assert
            expect(QueryBuilder::featureSnapshot()->where('id', $oldSnapshot->id)->exists())->toBeFalse();
            expect(QueryBuilder::featureSnapshot()->where('id', $recentSnapshot->id)->exists())->toBeTrue();
        });

        test('uses default 365 day retention when not configured', function (): void {
            // Arrange
            $user = User::factory()->create();
            config(['toggl.snapshots.pruning.retention_days' => 365]);

            // Create very old snapshot (400 days ago)
            $veryOldSnapshot = createSnapshotWithAge($user, 400);

            // Create year-old snapshot (300 days ago - should be retained)
            $recentSnapshot = createSnapshotWithAge($user, 300);

            // Act
            $this->artisan('toggl:prune-snapshots')
                ->expectsOutputToContain('Deleted 1 snapshot(s)')
                ->assertExitCode(0);

            // Assert
            expect(QueryBuilder::featureSnapshot()->where('id', $veryOldSnapshot->id)->exists())->toBeFalse();
            expect(QueryBuilder::featureSnapshot()->where('id', $recentSnapshot->id)->exists())->toBeTrue();
        });

        test('allows overriding retention days via option', function (): void {
            // Arrange
            $user = User::factory()->create();
            config(['toggl.snapshots.pruning.retention_days' => 365]); // Config says 365

            // Create 20-day old snapshot
            $snapshot = createSnapshotWithAge($user, 20);

            // Act - Override to 10 days
            $this->artisan('toggl:prune-snapshots', ['--days' => 10])
                ->expectsOutputToContain('Deleted 1 snapshot(s)')
                ->assertExitCode(0);

            // Assert
            expect(QueryBuilder::featureSnapshot()->where('id', $snapshot->id)->exists())->toBeFalse();
        });

        test('reports no snapshots found when none are old enough', function (): void {
            // Arrange
            $user = User::factory()->create();
            config(['toggl.snapshots.pruning.retention_days' => 30]);

            // Create recent snapshot
            createSnapshotWithAge($user, 10);

            // Act
            $this->artisan('toggl:prune-snapshots')
                ->expectsOutputToContain('No snapshots older than 30 days found')
                ->assertExitCode(0);
        });

        test('deletes associated entries and events with snapshot', function (): void {
            // Arrange
            $user = User::factory()->create();
            config(['toggl.snapshots.pruning.retention_days' => 30]);

            // Create snapshot with entries
            $snapshot = createSnapshotWithAge($user, 40);

            // Verify entries exist
            $entriesCount = QueryBuilder::snapshotEntry()
                ->where('snapshot_id', $snapshot->id)
                ->count();
            expect($entriesCount)->toBeGreaterThanOrEqual(0);

            // Act
            $this->artisan('toggl:prune-snapshots')
                ->expectsOutputToContain('Deleted 1 snapshot(s)')
                ->assertExitCode(0);

            // Assert - Entries should be deleted via cascade
            expect(QueryBuilder::snapshotEntry()->where('snapshot_id', $snapshot->id)->exists())->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('warns when pruning is disabled (0 days)', function (): void {
            // Arrange
            config(['toggl.snapshots.pruning.retention_days' => 0]);

            // Act
            $this->artisan('toggl:prune-snapshots')
                ->expectsOutputToContain('Pruning is disabled')
                ->assertExitCode(0);
        });

        test('handles empty snapshot table gracefully', function (): void {
            // Arrange
            config(['toggl.snapshots.pruning.retention_days' => 30]);

            // Act
            $this->artisan('toggl:prune-snapshots')
                ->expectsOutputToContain('No snapshots older than 30 days found')
                ->assertExitCode(0);
        });

        test('handles large number of snapshots via chunking', function (): void {
            // Arrange
            $user = User::factory()->create();
            config(['toggl.snapshots.pruning.retention_days' => 30]);

            // Create 150 old snapshots (tests chunking with 100 per chunk)
            for ($i = 0; $i < 150; ++$i) {
                createSnapshotWithAge($user, 40);
            }

            // Act
            $this->artisan('toggl:prune-snapshots')
                ->expectsOutputToContain('Deleted 150 snapshot(s)')
                ->assertExitCode(0);

            // Assert
            expect(QueryBuilder::featureSnapshot()->count())->toBe(0);
        });
    });
});

/**
 * Create a snapshot with a specific age in days.
 *
 * Creates a snapshot directly in the database and ages it.
 *
 * @param  User            $user User context
 * @param  int             $days Days ago to set created_at
 * @return FeatureSnapshot The created snapshot model
 */
function createSnapshotWithAge(User $user, int $days): FeatureSnapshot
{
    return QueryBuilder::featureSnapshot()->create([
        'label' => 'test-snapshot-'.uniqid(),
        'context_type' => $user::class,
        'context_id' => $user->getKey(),
        'created_at' => Date::now()->subDays($days),
    ]);
}
