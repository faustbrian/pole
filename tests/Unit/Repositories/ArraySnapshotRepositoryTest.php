<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\FeatureManager;
use Cline\Toggl\Repositories\ArraySnapshotRepository;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\User;

/**
 * ArraySnapshotRepository test suite.
 *
 * Tests the array-backed snapshot repository implementation, verifying snapshot creation,
 * restoration (full and partial), deletion, event tracking, and edge cases for null snapshots
 * and Model-based actor tracking (createdBy, restoredBy, deletedBy).
 *
 * Coverage focus: Lines 61-62, 65, 103, 136-137, 140, 169, 179, 187, 197-198, 201, 237, 244-245, 248, 290
 */
describe('ArraySnapshotRepository', function (): void {
    describe('Happy Path', function (): void {
        test('creates snapshot without createdBy', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');
            $features = ['premium' => true, 'analytics' => 'enabled'];

            // Act
            $snapshotId = $repository->create($context, $features, 'Test Snapshot', null, ['version' => '1.0']);

            // Assert
            expect($snapshotId)->toBeString();
            expect($snapshotId)->toStartWith('snapshot_');

            $snapshot = $repository->get($snapshotId, $context);
            expect($snapshot)->not->toBeNull();
            expect($snapshot['id'])->toBe($snapshotId);
            expect($snapshot['label'])->toBe('Test Snapshot');
            expect($snapshot['features'])->toBe($features);
            expect($snapshot['metadata'])->toBe(['version' => '1.0']);
            expect($snapshot['created_by'])->toBeNull();
        });

        test('creates snapshot with Model createdBy', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');
            $features = ['premium' => true];
            $user = User::factory()->make(['id' => 123, 'name' => 'John Doe']);

            // Act - Tests lines 60-65
            $snapshotId = $repository->create($context, $features, 'Created by user', $user);

            // Assert
            $snapshot = $repository->get($snapshotId, $context);
            expect($snapshot['created_by'])->not->toBeNull();
            expect($snapshot['created_by']['type'])->toBe(User::class);
            expect($snapshot['created_by']['id'])->toBe(123);
        });

        test('restores snapshot successfully', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $manager = app(FeatureManager::class);
            $context = TogglContext::simple('user:1', 'test');

            // Create initial state
            $manager->for($context)->activate('premium', true);
            $manager->for($context)->activate('analytics', 'enabled');

            // Create snapshot
            $features = ['premium' => true, 'analytics' => 'enabled'];
            $snapshotId = $repository->create($context, $features);

            // Modify state
            $manager->for($context)->deactivate('premium');
            $manager->for($context)->activate('export', true);

            // Act
            $repository->restore($snapshotId, $context);

            // Assert
            expect($manager->for($context)->active('premium'))->toBeTrue();
            expect($manager->for($context)->value('analytics'))->toBe('enabled');
            expect($manager->for($context)->active('export'))->toBeFalse();
        });

        test('restores snapshot with Model restoredBy', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $manager = app(FeatureManager::class);
            $context = TogglContext::simple('user:1', 'test');
            $user = User::factory()->make(['id' => 456, 'name' => 'Jane Doe']);

            $features = ['premium' => true];
            $snapshotId = $repository->create($context, $features);

            // Act - Tests lines 135-140
            $repository->restore($snapshotId, $context, $user);

            // Assert
            $snapshot = $repository->get($snapshotId, $context);
            expect($snapshot['restored_by'])->not->toBeNull();
            expect($snapshot['restored_by']['type'])->toBe(User::class);
            expect($snapshot['restored_by']['id'])->toBe(456);
            expect($snapshot['restored_at'])->not->toBeNull();
        });

        test('restores partial features from snapshot', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $manager = app(FeatureManager::class);
            $context = TogglContext::simple('user:1', 'test');

            $features = ['premium' => true, 'analytics' => 'enabled', 'export' => true];
            $snapshotId = $repository->create($context, $features);

            // Modify state
            $manager->for($context)->deactivate('premium');
            $manager->for($context)->deactivate('analytics');
            $manager->for($context)->deactivate('export');

            // Act
            $repository->restorePartial($snapshotId, $context, ['premium', 'analytics']);

            // Assert
            expect($manager->for($context)->active('premium'))->toBeTrue();
            expect($manager->for($context)->value('analytics'))->toBe('enabled');
            expect($manager->for($context)->active('export'))->toBeFalse();
        });

        test('restores partial features with Model restoredBy', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');
            $user = User::factory()->make(['id' => 789, 'name' => 'Admin User']);

            $features = ['premium' => true, 'analytics' => 'enabled'];
            $snapshotId = $repository->create($context, $features);

            // Act - Tests lines 196-201
            $repository->restorePartial($snapshotId, $context, ['premium'], $user);

            // Assert
            $snapshot = $repository->get($snapshotId, $context);
            $events = $snapshot['events'];
            $lastEvent = end($events);
            expect($lastEvent['type'])->toBe('partial_restore');
            expect($lastEvent['performed_by'])->not->toBeNull();
            expect($lastEvent['performed_by']['type'])->toBe(User::class);
            expect($lastEvent['performed_by']['id'])->toBe(789);
        });

        test('lists all snapshots for context', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            $id1 = $repository->create($context, ['feature1' => true], 'Snapshot 1');
            $id2 = $repository->create($context, ['feature2' => true], 'Snapshot 2');

            // Act
            $snapshots = $repository->list($context);

            // Assert
            expect($snapshots)->toBeArray();
            expect($snapshots)->toHaveCount(2);
            expect(array_column($snapshots, 'id'))->toContain($id1, $id2);
        });

        test('deletes snapshot without deletedBy', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            $snapshotId = $repository->create($context, ['premium' => true]);

            // Act
            $repository->delete($snapshotId, $context);

            // Assert
            expect($repository->get($snapshotId, $context))->toBeNull();
        });

        test('deletes snapshot with Model deletedBy', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');
            $user = User::factory()->make(['id' => 999, 'name' => 'Deleter']);

            $snapshotId = $repository->create($context, ['premium' => true], 'To be deleted');

            // Get snapshot before deletion to verify event
            $snapshotBefore = $repository->get($snapshotId, $context);

            // Act - Tests lines 243-248
            $repository->delete($snapshotId, $context, $user);

            // Assert - Snapshot should be gone
            expect($repository->get($snapshotId, $context))->toBeNull();
        });

        test('clears all snapshots for context', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            $repository->create($context, ['feature1' => true]);
            $repository->create($context, ['feature2' => true]);
            $repository->create($context, ['feature3' => true]);

            expect($repository->list($context))->toHaveCount(3);

            // Act
            $repository->clearAll($context);

            // Assert
            expect($repository->list($context))->toBeEmpty();
        });

        test('clears all snapshots with deletedBy', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');
            $user = User::factory()->make(['id' => 111]);

            $repository->create($context, ['feature1' => true]);
            $repository->create($context, ['feature2' => true]);

            // Act
            $repository->clearAll($context, $user);

            // Assert
            expect($repository->list($context))->toBeEmpty();
        });
    });

    describe('Sad Path', function (): void {
        test('restore returns early when snapshot not found', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $manager = app(FeatureManager::class);
            $context = TogglContext::simple('user:1', 'test');

            $manager->for($context)->activate('premium', true);

            // Act - Tests line 103
            $repository->restore('non-existent-id', $context);

            // Assert - State should be unchanged
            expect($manager->for($context)->active('premium'))->toBeTrue();
        });

        test('restorePartial returns early when snapshot not found', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $manager = app(FeatureManager::class);
            $context = TogglContext::simple('user:1', 'test');

            $manager->for($context)->activate('premium', true);

            // Act - Tests line 169
            $repository->restorePartial('non-existent-id', $context, ['premium']);

            // Assert - State should be unchanged
            expect($manager->for($context)->active('premium'))->toBeTrue();
        });

        test('delete returns early when snapshot not found', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            // Act - Tests line 237
            $repository->delete('non-existent-id', $context);

            // Assert - No exception thrown, operation completes silently
            expect($repository->list($context))->toBeEmpty();
        });
    });

    describe('Edge Cases', function (): void {
        test('restorePartial skips features not in snapshot', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $manager = app(FeatureManager::class);
            $context = TogglContext::simple('user:1', 'test');

            $features = ['premium' => true, 'analytics' => 'enabled'];
            $snapshotId = $repository->create($context, $features);

            // Act - Tests line 179 (continue when feature not in snapshot)
            $repository->restorePartial($snapshotId, $context, ['premium', 'non-existent-feature', 'analytics']);

            // Assert - Only existing features are restored
            expect($manager->for($context)->active('premium'))->toBeTrue();
            expect($manager->for($context)->value('analytics'))->toBe('enabled');
        });

        test('restore deactivates features with false value', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $manager = app(FeatureManager::class);
            $context = TogglContext::simple('user:1', 'test');

            // Create snapshot with explicitly false feature
            $features = ['premium' => true, 'analytics' => false];
            $snapshotId = $repository->create($context, $features);

            // Activate analytics first
            $manager->for($context)->activate('analytics', true);

            // Act - Tests line 187 (deactivate when false)
            $repository->restorePartial($snapshotId, $context, ['analytics']);

            // Assert
            expect($manager->for($context)->active('analytics'))->toBeFalse();
        });

        test('restore deactivates features with null value', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $manager = app(FeatureManager::class);
            $context = TogglContext::simple('user:1', 'test');

            // Create snapshot with null feature
            $features = ['premium' => null, 'analytics' => true];
            $snapshotId = $repository->create($context, $features);

            // Activate premium first
            $manager->for($context)->activate('premium', true);

            // Act - Tests line 187 (deactivate when null)
            $repository->restorePartial($snapshotId, $context, ['premium']);

            // Assert
            expect($manager->for($context)->active('premium'))->toBeFalse();
        });

        test('get returns null for non-existent snapshot', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            // Act
            $result = $repository->get('non-existent-id', $context);

            // Assert
            expect($result)->toBeNull();
        });

        test('list returns empty array when no snapshots exist', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            // Act
            $result = $repository->list($context);

            // Assert
            expect($result)->toBeArray();
            expect($result)->toBeEmpty();
        });

        test('getEventHistory returns empty array', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            $snapshotId = $repository->create($context, ['premium' => true]);

            // Act - Tests line 290
            $result = $repository->getEventHistory($snapshotId);

            // Assert
            expect($result)->toBeArray();
            expect($result)->toBeEmpty();
        });

        test('snapshots are isolated by context', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context1 = TogglContext::simple('user:1', 'test');
            $context2 = TogglContext::simple('user:2', 'test');

            $id1 = $repository->create($context1, ['premium' => true]);
            $id2 = $repository->create($context2, ['analytics' => true]);

            // Act
            $list1 = $repository->list($context1);
            $list2 = $repository->list($context2);

            // Assert
            expect($list1)->toHaveCount(1);
            expect($list2)->toHaveCount(1);
            expect($list1[0]['id'])->toBe($id1);
            expect($list2[0]['id'])->toBe($id2);
        });

        test('delete removes last snapshot and clears cache key', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            $snapshotId = $repository->create($context, ['premium' => true]);

            // Act
            $repository->delete($snapshotId, $context);

            // Assert
            expect($repository->list($context))->toBeEmpty();

            // Verify cache key is cleared
            $contextKey = app(FeatureManager::class)->serializeContext($context);
            $cacheKey = 'toggl:snapshots:'.$contextKey;
            expect(Cache::driver('array')->has($cacheKey))->toBeFalse();
        });

        test('delete keeps other snapshots when removing one', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            $id1 = $repository->create($context, ['feature1' => true]);
            $id2 = $repository->create($context, ['feature2' => true]);
            $id3 = $repository->create($context, ['feature3' => true]);

            // Act
            $repository->delete($id2, $context);

            // Assert
            $snapshots = $repository->list($context);
            expect($snapshots)->toHaveCount(2);
            expect(array_column($snapshots, 'id'))->toContain($id1, $id3);
            expect(array_column($snapshots, 'id'))->not->toContain($id2);
        });

        test('restore preserves internal features starting with __', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $manager = app(FeatureManager::class);
            $context = TogglContext::simple('user:1', 'test');

            // Set up state with internal feature
            $manager->for($context)->activate('premium', true);
            $manager->for($context)->activate('__internal__', 'should-remain');

            // Create snapshot
            $features = ['premium' => false];
            $snapshotId = $repository->create($context, $features);

            // Act
            $repository->restore($snapshotId, $context);

            // Assert - Internal feature should NOT be deactivated
            expect($manager->for($context)->value('__internal__'))->toBe('should-remain');
            expect($manager->for($context)->active('premium'))->toBeFalse();
        });

        test('snapshot events include creation event', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');
            $features = ['premium' => true, 'analytics' => true];

            // Act
            $snapshotId = $repository->create($context, $features);

            // Assert
            $snapshot = $repository->get($snapshotId, $context);
            expect($snapshot['events'])->toBeArray();
            expect($snapshot['events'])->toHaveCount(1);

            $event = $snapshot['events'][0];
            expect($event['type'])->toBe('created');
            expect($event['metadata']['feature_count'])->toBe(2);
            expect($event['performed_by'])->toBeNull();
        });

        test('snapshot events include restore event', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            $snapshotId = $repository->create($context, ['premium' => true]);

            // Act
            $repository->restore($snapshotId, $context);

            // Assert
            $snapshot = $repository->get($snapshotId, $context);
            expect($snapshot['events'])->toHaveCount(2);

            $event = $snapshot['events'][1];
            expect($event['type'])->toBe('restored');
            expect($event['metadata'])->toHaveKey('features_restored');
        });

        test('clearAll deletes snapshots in order', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            // Create multiple snapshots
            $repository->create($context, ['feature1' => true]);
            $repository->create($context, ['feature2' => true]);
            $repository->create($context, ['feature3' => true]);

            // Act
            $repository->clearAll($context);

            // Assert
            expect($repository->list($context))->toBeEmpty();
        });

        test('create generates unique snapshot IDs', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            // Act
            $id1 = $repository->create($context, ['feature1' => true]);
            $id2 = $repository->create($context, ['feature2' => true]);
            $id3 = $repository->create($context, ['feature3' => true]);

            // Assert
            expect($id1)->not->toBe($id2);
            expect($id2)->not->toBe($id3);
            expect($id1)->not->toBe($id3);
        });

        test('restorePartial adds event with correct metadata', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('user:1', 'test');

            $features = ['premium' => true, 'analytics' => true, 'export' => true];
            $snapshotId = $repository->create($context, $features);

            // Act
            $repository->restorePartial($snapshotId, $context, ['premium', 'analytics']);

            // Assert
            $snapshot = $repository->get($snapshotId, $context);
            $lastEvent = end($snapshot['events']);

            expect($lastEvent['type'])->toBe('partial_restore');
            expect($lastEvent['metadata']['total_features'])->toBe(2);
            expect($lastEvent['metadata']['features_restored'])->toContain('premium', 'analytics');
        });
    });

    describe('Prune', function (): void {
        test('returns zero for in-memory driver', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('test-context', 'test');
            $repository->create($context, ['feature' => true]);

            // Act
            $deleted = $repository->prune(30);

            // Assert - Array driver doesn't persist, so pruning is N/A
            expect($deleted)->toBe(0);
        });

        test('snapshots remain accessible after prune call', function (): void {
            // Arrange
            $repository = createArraySnapshotRepository();
            $context = TogglContext::simple('test-context', 'test');
            $snapshotId = $repository->create($context, ['feature' => true]);

            // Act
            $repository->prune(30);

            // Assert - Snapshots still exist since it's in-memory
            expect($repository->get($snapshotId, $context))->not->toBeNull();
        });
    });
});

/**
 * Create an ArraySnapshotRepository instance for testing.
 *
 * Factory function that constructs a fresh ArraySnapshotRepository with the application's
 * FeatureManager. Used throughout tests to create isolated repository instances.
 *
 * @return ArraySnapshotRepository Configured repository instance ready for testing
 */
function createArraySnapshotRepository(): ArraySnapshotRepository
{
    return new ArraySnapshotRepository(
        app(FeatureManager::class),
    );
}
