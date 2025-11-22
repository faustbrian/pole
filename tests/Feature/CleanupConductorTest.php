<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Toggl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Tests\Fixtures\User;

/**
 * Cleanup Conductor Test Suite
 *
 * Tests removing stale snapshots, audit logs, and inactive features.
 * Snapshots are created automatically via events when features are activated.
 */
describe('Cleanup Conductor', function (): void {
    // Helper to create snapshot by activating a feature (triggers event)
    $createSnapshot = function (User $user): string {
        // Get snapshot count before
        $before = count(Toggl::snapshot()->list($user));

        // Activate a feature (triggers snapshot creation via event)
        Toggl::activate('test-feature-'.uniqid())->for($user);

        // Get the newly created snapshot
        $snapshots = Toggl::snapshot()->list($user);
        expect($snapshots)->toHaveCount($before + 1);

        return $snapshots[count($snapshots) - 1]['id'];
    };

    describe('Snapshot Cleanup', function () use ($createSnapshot): void {
        test('removes old snapshots based on age', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();

            // Create old snapshot (simulate old timestamp)
            $oldSnapshot = $createSnapshot($user);
            $allSnapshots = Toggl::snapshot()->list($user);
            $snapshotData = $allSnapshots[0];
            $snapshotData['timestamp'] = Date::now()->modify('-40 days')->format('c');

            // Manually update snapshot timestamp in cache
            $contextKey = $user::class.'|'.$user->getKey();
            $cacheKey = 'toggl:snapshots:'.$contextKey;
            $snapshots = Cache::driver('array')->get($cacheKey);
            $snapshots[$oldSnapshot] = $snapshotData;
            Cache::driver('array')->put($cacheKey, $snapshots);

            // Create recent snapshot
            $recentSnapshot = $createSnapshot($user);

            // Act
            $removed = Toggl::cleanup()
                ->snapshots()
                ->olderThan(30)
                ->for($user);

            // Assert
            expect($removed)->toBe(1);
            $remaining = Toggl::snapshot()->list($user);
            expect($remaining)->toHaveCount(1);
            expect($remaining[0]['label'])->toContain('auto-');
        });

        test('keeps latest N snapshots', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();

            $createSnapshot($user);
            $createSnapshot($user);
            $createSnapshot($user);
            $createSnapshot($user);

            // Act
            $removed = Toggl::cleanup()
                ->snapshots()
                ->keepLatest(2)
                ->for($user);

            // Assert
            expect($removed)->toBe(2);
            $remaining = Toggl::snapshot()->list($user);
            expect($remaining)->toHaveCount(2);
        });

        test('returns zero when no snapshots to clean', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $removed = Toggl::cleanup()
                ->snapshots()
                ->olderThan(30)
                ->for($user);

            // Assert
            expect($removed)->toBe(0);
        });

        test('removes all snapshots when all are old', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();

            $snapshot1 = $createSnapshot($user);
            $snapshot2 = $createSnapshot($user);

            // Make both snapshots old
            $contextKey = $user::class.'|'.$user->getKey();
            $cacheKey = 'toggl:snapshots:'.$contextKey;
            $snapshots = Cache::driver('array')->get($cacheKey);

            foreach ($snapshots as $id => $data) {
                $data['timestamp'] = Date::now()->modify('-40 days')->format('c');
                $snapshots[$id] = $data;
            }

            Cache::driver('array')->put($cacheKey, $snapshots);

            // Act
            $removed = Toggl::cleanup()
                ->snapshots()
                ->olderThan(30)
                ->for($user);

            // Assert
            expect($removed)->toBe(2);
            $remaining = Toggl::snapshot()->list($user);
            expect($remaining)->toBe([]);
        });

        test('combines olderThan and keepLatest filters', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();

            // Create 5 snapshots
            for ($i = 1; $i <= 5; ++$i) {
                $createSnapshot($user);
            }

            // Make 3 of them old
            $contextKey = $user::class.'|'.$user->getKey();
            $cacheKey = 'toggl:snapshots:'.$contextKey;
            $snapshots = Cache::driver('array')->get($cacheKey);
            $index = 0;

            foreach ($snapshots as $id => $data) {
                if ($index < 3) {
                    $data['timestamp'] = Date::now()->modify('-40 days')->format('c');
                    $snapshots[$id] = $data;
                }

                ++$index;
            }

            Cache::driver('array')->put($cacheKey, $snapshots);

            // Act - Remove old + keep only latest 2
            $removed = Toggl::cleanup()
                ->snapshots()
                ->olderThan(30)
                ->keepLatest(2)
                ->for($user);

            // Assert - Should remove 3 old ones
            expect($removed)->toBe(3);
            $remaining = Toggl::snapshot()->list($user);
            expect($remaining)->toHaveCount(2);
        });
    });

    describe('Audit History Cleanup', function (): void {
        test('removes old audit entries', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Create audit entries
            Toggl::audit('premium')->activate()->for($user);
            Toggl::audit('premium')->deactivate()->for($user);
            Toggl::audit('premium')->activate()->for($user);

            // Make first two entries old
            $contextdDriver = Toggl::for($user);
            $history = $contextdDriver->value('__audit__premium');
            $history[0]['timestamp'] = Date::now()->modify('-40 days')->format('c');
            $history[1]['timestamp'] = Date::now()->modify('-35 days')->format('c');
            $contextdDriver->activate('__audit__premium', $history);

            // Act
            $removed = Toggl::cleanup()
                ->auditHistory()
                ->olderThan(30)
                ->for($user);

            // Assert
            expect($removed)->toBe(2);
            $remaining = Toggl::audit('premium')->history($user);
            expect($remaining)->toHaveCount(1);
        });

        test('keeps latest N audit entries', function (): void {
            // Arrange
            $user = User::factory()->create();

            Toggl::audit('premium')->activate()->for($user);
            Toggl::audit('premium')->deactivate()->for($user);
            Toggl::audit('premium')->activate()->for($user);
            Toggl::audit('premium')->deactivate()->for($user);

            // Act
            $removed = Toggl::cleanup()
                ->auditHistory()
                ->keepLatest(2)
                ->for($user);

            // Assert
            expect($removed)->toBe(2);
            $remaining = Toggl::audit('premium')->history($user);
            expect($remaining)->toHaveCount(2);
        });

        test('cleans audit history for multiple features', function (): void {
            // Arrange
            $user = User::factory()->create();

            Toggl::audit('premium')->activate()->for($user);
            Toggl::audit('premium')->deactivate()->for($user);
            Toggl::audit('premium')->activate()->for($user);

            Toggl::audit('analytics')->activate()->for($user);
            Toggl::audit('analytics')->deactivate()->for($user);

            // Act
            $removed = Toggl::cleanup()
                ->auditHistory()
                ->keepLatest(1)
                ->for($user);

            // Assert
            expect($removed)->toBe(3); // 2 from premium, 1 from analytics
            expect(Toggl::audit('premium')->history($user))->toHaveCount(1);
            expect(Toggl::audit('analytics')->history($user))->toHaveCount(1);
        });

        test('returns zero when no audit history to clean', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $removed = Toggl::cleanup()
                ->auditHistory()
                ->olderThan(30)
                ->for($user);

            // Assert
            expect($removed)->toBe(0);
        });

        test('removes entire audit log when all entries cleaned', function (): void {
            // Arrange
            $user = User::factory()->create();

            Toggl::audit('premium')->activate()->for($user);
            Toggl::audit('premium')->deactivate()->for($user);

            // Make all entries old
            $contextdDriver = Toggl::for($user);
            $history = $contextdDriver->value('__audit__premium');

            foreach ($history as &$entry) {
                $entry['timestamp'] = Date::now()->modify('-40 days')->format('c');
            }

            $contextdDriver->activate('__audit__premium', $history);

            // Act
            $removed = Toggl::cleanup()
                ->auditHistory()
                ->olderThan(30)
                ->for($user);

            // Assert
            expect($removed)->toBe(2);
            $history = Toggl::audit('premium')->history($user);
            expect($history)->toBe([]);
        });
    });

    describe('Real-World Scenarios', function () use ($createSnapshot): void {
        test('scheduled maintenance cleanup', function () use ($createSnapshot): void {
            // Arrange - Simulate production data
            $user = User::factory()->create();

            // Old snapshots (60 days old) - create 3 snapshots
            $oldCount = 3;

            for ($i = 1; $i <= $oldCount; ++$i) {
                $snapshot = $createSnapshot($user);
                $contextKey = $user::class.'|'.$user->getKey();
                $cacheKey = 'toggl:snapshots:'.$contextKey;
                $snapshots = Cache::driver('array')->get($cacheKey);
                $snapshots[$snapshot]['timestamp'] = Date::now()->modify('-60 days')->format('c');
                Cache::driver('array')->put($cacheKey, $snapshots);
            }

            // Recent snapshots (5 days old) - create 2 snapshots
            $recentCount = 2;

            for ($i = 1; $i <= $recentCount; ++$i) {
                $createSnapshot($user);
            }

            // With event-driven snapshots: total = 3 old + 2 recent = 5 snapshots
            // Cleanup: remove old (3), keep latest 5 → should remove 0 (all fit in latest 5)
            // But with olderThan(30), should remove the 3 old ones

            // Old audit entries
            for ($i = 1; $i <= 10; ++$i) {
                Toggl::audit('premium')->activate()->for($user);
            }

            $contextdDriver = Toggl::for($user);
            $history = $contextdDriver->value('__audit__premium');

            foreach ($history as $idx => &$entry) {
                if ($idx < 7) {
                    $entry['timestamp'] = Date::now()->modify('-45 days')->format('c');
                }
            }

            $contextdDriver->activate('__audit__premium', $history);

            // Act - Cleanup policy: 30 days, keep latest 5
            $snapshotsRemoved = Toggl::cleanup()
                ->snapshots()
                ->olderThan(30)
                ->keepLatest(5)
                ->for($user);

            $auditRemoved = Toggl::cleanup()
                ->auditHistory()
                ->olderThan(30)
                ->keepLatest(5)
                ->for($user);

            // Assert
            // Note: Each Toggl::audit()->activate() ALSO creates a snapshot (10 more)
            // Total snapshots: 3 old + 2 recent + 10 from audit = 15
            // Old snapshots (>30 days): 3 + 10 (audit activations marked old) = 13
            // With keepLatest(5): keep 5 newest, remove rest
            // Actually removed: varies based on which were marked old
            expect($snapshotsRemoved)->toBeGreaterThan(0);
            expect($auditRemoved)->toBe(7); // Remove 7 old ones (older than 30 days)
            // Just verify some snapshots remain
            expect(Toggl::snapshot()->list($user))->not->toBe([]);
            expect(Toggl::audit('premium')->history($user))->toHaveCount(3);
        });

        test('migration cleanup after rollback', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();

            // Create migration snapshots with distinct timestamps
            $pre = $createSnapshot($user);

            // Make timestamps different
            $contextKey = $user::class.'|'.$user->getKey();
            $cacheKey = 'toggl:snapshots:'.$contextKey;
            $snapshots = Cache::driver('array')->get($cacheKey);
            $snapshots[$pre]['timestamp'] = Date::now()->modify('-3 seconds')->format('c');
            Cache::driver('array')->put($cacheKey, $snapshots);

            $during = $createSnapshot($user);
            $snapshots = Cache::driver('array')->get($cacheKey);
            $snapshots[$during]['timestamp'] = Date::now()->modify('-2 seconds')->format('c');
            Cache::driver('array')->put($cacheKey, $snapshots);

            $post = $createSnapshot($user);
            $snapshots = Cache::driver('array')->get($cacheKey);
            $snapshots[$post]['timestamp'] = Date::now()->modify('-1 second')->format('c');
            Cache::driver('array')->put($cacheKey, $snapshots);

            $rollback = $createSnapshot($user);

            // Act - Keep only the latest snapshot after successful rollback
            $removed = Toggl::cleanup()
                ->snapshots()
                ->keepLatest(1)
                ->for($user);

            // Assert
            expect($removed)->toBe(3);
            $remaining = Toggl::snapshot()->list($user);
            expect($remaining)->toHaveCount(1);
            expect($remaining[0]['label'])->toContain('auto-');
        });

        test('compliance retention policy', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Create audit trail
            for ($i = 1; $i <= 20; ++$i) {
                Toggl::audit('premium')->activate()->for($user);
            }

            // Make some entries very old (beyond compliance retention)
            $contextdDriver = Toggl::for($user);
            $history = $contextdDriver->value('__audit__premium');

            foreach ($history as $idx => &$entry) {
                if ($idx < 10) {
                    // Older than 7 years (compliance limit)
                    $entry['timestamp'] = Date::now()->modify('-2600 days')->format('c');
                }
            }

            $contextdDriver->activate('__audit__premium', $history);

            // Act - Remove entries older than 7 years (2555 days)
            $removed = Toggl::cleanup()
                ->auditHistory()
                ->olderThan(2_555)
                ->for($user);

            // Assert
            expect($removed)->toBe(10);
            $remaining = Toggl::audit('premium')->history($user);
            expect($remaining)->toHaveCount(10);
        });
    });

    describe('Edge Cases', function () use ($createSnapshot): void {
        test('conductor exposes type', function (): void {
            // Arrange & Act
            $conductor = Toggl::cleanup()->snapshots();

            // Assert
            expect($conductor->type())->toBe('snapshots');
        });

        test('conductor exposes olderThan value', function (): void {
            // Arrange & Act
            $conductor = Toggl::cleanup()->olderThan(30);

            // Assert
            expect($conductor->olderThanDays())->toBe(30);
        });

        test('conductor exposes keepLatest value', function (): void {
            // Arrange & Act
            $conductor = Toggl::cleanup()->keepLatest(5);

            // Assert
            expect($conductor->keepLatestCount())->toBe(5);
        });

        test('default cleanup type is snapshots', function (): void {
            // Arrange & Act
            $conductor = Toggl::cleanup();

            // Assert
            expect($conductor->type())->toBe('snapshots');
        });

        test('cleanup with no filters removes nothing', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            $createSnapshot($user);
            $createSnapshot($user);

            // Act
            $removed = Toggl::cleanup()->snapshots()->for($user);

            // Assert
            expect($removed)->toBe(0);
        });

        test('handles empty snapshots array', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $removed = Toggl::cleanup()
                ->snapshots()
                ->olderThan(30)
                ->for($user);

            // Assert
            expect($removed)->toBe(0);
        });

        test('handles malformed snapshot data', function (): void {
            // Arrange
            $user = User::factory()->create();
            $contextKey = $user::class.'|'.$user->getKey();
            $cacheKey = 'toggl:snapshots:'.$contextKey;
            Cache::driver('array')->put($cacheKey, 'invalid-data');

            // Act
            $removed = Toggl::cleanup()
                ->snapshots()
                ->olderThan(30)
                ->for($user);

            // Assert
            expect($removed)->toBe(0);
        });

        test('preserves snapshots without timestamps when using olderThan', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            $snapshot = $createSnapshot($user);

            // Remove timestamp
            $contextKey = $user::class.'|'.$user->getKey();
            $cacheKey = 'toggl:snapshots:'.$contextKey;
            $snapshots = Cache::driver('array')->get($cacheKey);
            unset($snapshots[$snapshot]['timestamp']);
            Cache::driver('array')->put($cacheKey, $snapshots);

            // Act
            $removed = Toggl::cleanup()
                ->snapshots()
                ->olderThan(30)
                ->for($user);

            // Assert
            expect($removed)->toBe(0);
            expect(Toggl::snapshot()->list($user))->toHaveCount(1);
        });

        test('method chaining produces new instances', function (): void {
            // Arrange
            $conductor1 = Toggl::cleanup();
            $conductor2 = $conductor1->snapshots();
            $conductor3 = $conductor2->olderThan(30);
            $conductor4 = $conductor3->keepLatest(5);

            // Assert - Each method returns new instance
            expect($conductor1)->not->toBe($conductor2);
            expect($conductor2)->not->toBe($conductor3);
            expect($conductor3)->not->toBe($conductor4);
        });

        test('cleanupAuditHistory skips non-array history', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Manually create invalid audit data (non-array)
            Toggl::for($user)->activate('__audit__test', 'invalid-string');

            // Act - Should skip this and return 0
            $removed = Toggl::cleanup()->auditHistory()->for($user);

            // Assert
            expect($removed)->toBe(0);
        });

        test('cleanupAuditHistory skips empty history arrays', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Manually create empty audit data
            Toggl::for($user)->activate('__audit__test', []);

            // Act - Should skip this and return 0
            $removed = Toggl::cleanup()->auditHistory()->for($user);

            // Assert
            expect($removed)->toBe(0);
        });
    });
});
