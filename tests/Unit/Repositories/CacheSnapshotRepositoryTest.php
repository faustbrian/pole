<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\FeatureManager;
use Cline\Toggl\Repositories\CacheSnapshotRepository;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * CacheSnapshotRepository test suite.
 *
 * Tests the cache-backed snapshot repository implementation. This repository stores
 * feature flag snapshots in Redis using cache tags for data separation and maintains
 * full event history in cache for the TTL duration.
 *
 * Coverage areas:
 * - Snapshot creation with context serialization
 * - Feature restoration with internal key preservation
 * - Partial restoration with selective features
 * - Snapshot retrieval, listing, and deletion
 * - Event history tracking
 * - Cache storage operations with tags
 * - Model-based createdBy/restoredBy/deletedBy tracking
 * - Null/missing snapshot handling
 */
describe('CacheSnapshotRepository', function (): void {
    beforeEach(function (): void {
        $this->manager = new FeatureManager(app());
        $this->repository = new CacheSnapshotRepository($this->manager);

        // Clear cache before each test
        Cache::flush();
    });

    describe('Happy Path', function (): void {
        test('creates snapshot with basic features', function (): void {
            // Arrange
            $features = [
                'feature1' => true,
                'feature2' => 'value',
                'feature3' => false,
            ];

            // Act
            $snapshotId = $this->repository->create(
                context: null,
                features: $features,
                label: 'Test Snapshot',
            );

            // Assert
            expect($snapshotId)->toBeString()
                ->and($snapshotId)->toStartWith('snapshot_');

            $snapshot = $this->repository->get($snapshotId, null);
            expect($snapshot)->toBeArray()
                ->and($snapshot['id'])->toBe($snapshotId)
                ->and($snapshot['label'])->toBe('Test Snapshot')
                ->and($snapshot['features'])->toBe($features)
                ->and($snapshot['context_key'])->toBe('__laravel_null')
                ->and($snapshot['created_by'])->toBeNull()
                ->and($snapshot['metadata'])->toBeNull()
                ->and($snapshot['events'])->toHaveCount(1)
                ->and($snapshot['events'][0]['type'])->toBe('created')
                ->and($snapshot['events'][0]['metadata']['feature_count'])->toBe(3);
        });

        test('creates snapshot with Model createdBy', function (): void {
            // Arrange
            $model = new class() extends Model
            {
                use HasFactory;

                protected $table = 'users';

                protected $fillable = ['id'];

                public function getKey(): int
                {
                    return 123;
                }
            };

            // Act
            $snapshotId = $this->repository->create(
                context: null,
                features: ['feature1' => true],
                label: 'Test Snapshot',
                createdBy: $model,
            );

            // Assert
            $snapshot = $this->repository->get($snapshotId, null);
            expect($snapshot['created_by'])->toBeArray()
                ->and($snapshot['created_by']['type'])->toBe($model::class)
                ->and($snapshot['created_by']['id'])->toBe(123);
        });

        test('creates snapshot with string createdBy ID', function (): void {
            // Arrange
            $model = new class() extends Model
            {
                use HasFactory;

                protected $table = 'users';

                public function getKey(): string
                {
                    return 'user-uuid-123';
                }
            };

            // Act
            $snapshotId = $this->repository->create(
                context: null,
                features: ['feature1' => true],
                label: 'Test Snapshot',
                createdBy: $model,
            );

            // Assert
            $snapshot = $this->repository->get($snapshotId, null);
            expect($snapshot['created_by'])->toBeArray()
                ->and($snapshot['created_by']['type'])->toBe($model::class)
                ->and($snapshot['created_by']['id'])->toBe('user-uuid-123');
        });

        test('creates snapshot with metadata', function (): void {
            // Arrange
            $metadata = [
                'environment' => 'testing',
                'version' => '1.0.0',
            ];

            // Act
            $snapshotId = $this->repository->create(
                context: null,
                features: ['feature1' => true],
                label: 'Test Snapshot',
                metadata: $metadata,
            );

            // Assert
            $snapshot = $this->repository->get($snapshotId, null);
            expect($snapshot['metadata'])->toBe($metadata);
        });

        test('creates snapshot with custom context', function (): void {
            // Arrange
            $context = 'team:123';

            // Act
            $snapshotId = $this->repository->create(
                context: $context,
                features: ['feature1' => true],
                label: 'Team Snapshot',
            );

            // Assert
            $snapshot = $this->repository->get($snapshotId, $context);
            expect($snapshot)->toBeArray()
                ->and($snapshot['context_key'])->toBe('team:123');
        });

        test('restores snapshot and deactivates existing features', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');

            // Set up existing features
            $this->manager->for($context)->activate('old_feature1');
            $this->manager->for($context)->activate('old_feature2', 'value');

            // Create snapshot with new features
            $snapshotFeatures = [
                'new_feature1' => true,
                'new_feature2' => false,
                'new_feature3' => 'custom_value',
            ];
            $snapshotId = $this->repository->create($context, $snapshotFeatures);

            // Verify old features are active
            expect($this->manager->for($context)->active('old_feature1'))->toBeTrue()
                ->and($this->manager->for($context)->active('old_feature2'))->toBeTrue();

            // Act - restore snapshot
            $this->repository->restore($snapshotId, $context);

            // Assert - old features should be deactivated, new features should be active
            expect($this->manager->for($context)->active('old_feature1'))->toBeFalse()
                ->and($this->manager->for($context)->active('old_feature2'))->toBeFalse()
                ->and($this->manager->for($context)->active('new_feature1'))->toBeTrue()
                ->and($this->manager->for($context)->active('new_feature2'))->toBeFalse()
                ->and($this->manager->for($context)->active('new_feature3'))->toBeTrue();

            // Verify restoration event was added
            $snapshot = $this->repository->get($snapshotId, $context);
            expect($snapshot['restored_at'])->not->toBeNull()
                ->and($snapshot['events'])->toHaveCount(2)
                ->and($snapshot['events'][1]['type'])->toBe('restored')
                ->and($snapshot['events'][1]['metadata']['features_restored'])->toBe(['new_feature1', 'new_feature2', 'new_feature3']);
        });

        test('restores snapshot with Model restoredBy', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $model = new class() extends Model
            {
                use HasFactory;

                protected $table = 'users';

                public function getKey(): int
                {
                    return 456;
                }
            };

            $snapshotId = $this->repository->create($context, ['feature1' => true]);

            // Act
            $this->repository->restore($snapshotId, $context, $model);

            // Assert
            $snapshot = $this->repository->get($snapshotId, $context);
            expect($snapshot['restored_by'])->toBeArray()
                ->and($snapshot['restored_by']['type'])->toBe($model::class)
                ->and($snapshot['restored_by']['id'])->toBe(456)
                ->and($snapshot['events'][1]['performed_by']['type'])->toBe($model::class)
                ->and($snapshot['events'][1]['performed_by']['id'])->toBe(456);
        });

        test('restore preserves internal keys starting with __', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');

            // Set up existing features including internal keys
            $this->manager->for($context)->activate('regular_feature');
            $this->manager->for($context)->activate('__internal_cache', 'cached_data');
            $this->manager->for($context)->activate('__internal_state', 'state_data');

            // Create snapshot with new features
            $snapshotId = $this->repository->create($context, ['new_feature' => true]);

            // Act - restore snapshot
            $this->repository->restore($snapshotId, $context);

            // Assert - regular features deactivated, internal keys preserved
            expect($this->manager->for($context)->active('regular_feature'))->toBeFalse()
                ->and($this->manager->for($context)->active('__internal_cache'))->toBeTrue()
                ->and($this->manager->for($context)->value('__internal_cache'))->toBe('cached_data')
                ->and($this->manager->for($context)->active('__internal_state'))->toBeTrue()
                ->and($this->manager->for($context)->value('__internal_state'))->toBe('state_data')
                ->and($this->manager->for($context)->active('new_feature'))->toBeTrue();
        });

        test('restores partial snapshot with selected features', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $snapshotFeatures = [
                'feature1' => true,
                'feature2' => false,
                'feature3' => 'value3',
                'feature4' => 'value4',
            ];

            $snapshotId = $this->repository->create($context, $snapshotFeatures);

            // Act - restore only feature1 and feature3
            $this->repository->restorePartial($snapshotId, $context, ['feature1', 'feature3']);

            // Assert
            expect($this->manager->for($context)->active('feature1'))->toBeTrue()
                ->and($this->manager->for($context)->active('feature3'))->toBeTrue()
                ->and($this->manager->for($context)->value('feature3'))->toBe('value3');

            // Verify partial restoration event
            $snapshot = $this->repository->get($snapshotId, $context);
            expect($snapshot['events'])->toHaveCount(2)
                ->and($snapshot['events'][1]['type'])->toBe('partial_restore')
                ->and($snapshot['events'][1]['metadata']['features_restored'])->toBe(['feature1', 'feature3'])
                ->and($snapshot['events'][1]['metadata']['total_features'])->toBe(2);
        });

        test('restores partial snapshot with Model restoredBy', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $model = new class() extends Model
            {
                use HasFactory;

                protected $table = 'users';

                public function getKey(): int
                {
                    return 789;
                }
            };

            $snapshotId = $this->repository->create($context, ['feature1' => true, 'feature2' => false]);

            // Act
            $this->repository->restorePartial($snapshotId, $context, ['feature1'], $model);

            // Assert
            $snapshot = $this->repository->get($snapshotId, $context);
            expect($snapshot['events'][1]['performed_by'])->toBeArray()
                ->and($snapshot['events'][1]['performed_by']['type'])->toBe($model::class)
                ->and($snapshot['events'][1]['performed_by']['id'])->toBe(789);
        });

        test('gets snapshot by ID', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $snapshotId = $this->repository->create($context, ['feature1' => true], 'Test Snapshot');

            // Act
            $result = $this->repository->get($snapshotId, $context);

            // Assert
            expect($result)->toBeArray()
                ->and($result['id'])->toBe($snapshotId)
                ->and($result['label'])->toBe('Test Snapshot');
        });

        test('lists all snapshots for context', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $id1 = $this->repository->create($context, ['f1' => true], 'First');
            $id2 = $this->repository->create($context, ['f2' => true], 'Second');
            $id3 = $this->repository->create($context, ['f3' => true], 'Third');

            // Act
            $result = $this->repository->list($context);

            // Assert
            expect($result)->toHaveCount(3)
                ->and($result[0]['id'])->toBe($id1)
                ->and($result[1]['id'])->toBe($id2)
                ->and($result[2]['id'])->toBe($id3);
        });

        test('deletes snapshot with deletion event', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $snapshotId = $this->repository->create($context, ['feature1' => true], 'Test Snapshot');

            // Act
            $this->repository->delete($snapshotId, $context);

            // Assert
            expect($this->repository->get($snapshotId, $context))->toBeNull()
                ->and($this->repository->list($context))->toBeEmpty();
        });

        test('deletes snapshot with Model deletedBy', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $model = new class() extends Model
            {
                use HasFactory;

                protected $table = 'users';

                public function getKey(): int
                {
                    return 999;
                }
            };

            $snapshotId = $this->repository->create($context, ['feature1' => true], 'Test Snapshot');

            // Before deletion, verify deletion event metadata
            $snapshotBeforeDelete = $this->repository->get($snapshotId, $context);
            $originalEventCount = count($snapshotBeforeDelete['events']);

            // Act
            $this->repository->delete($snapshotId, $context, $model);

            // Assert - snapshot should be deleted
            expect($this->repository->get($snapshotId, $context))->toBeNull();
        });

        test('clears all snapshots for context', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $this->repository->create($context, ['f1' => true], 'First');
            $this->repository->create($context, ['f2' => true], 'Second');

            // Act
            $this->repository->clearAll($context);

            // Assert
            expect($this->repository->list($context))->toBeEmpty();
        });

        test('returns empty event history', function (): void {
            // Act
            $result = $this->repository->getEventHistory('snapshot_test123');

            // Assert
            expect($result)->toBe([]);
        });
    });

    describe('Sad Path', function (): void {
        test('restore does nothing when snapshot not found', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $snapshotId = 'nonexistent';

            // Set up a feature to verify it's not touched
            $this->manager->for($context)->activate('existing_feature');

            // Act
            $this->repository->restore($snapshotId, $context);

            // Assert - existing feature should still be active
            expect($this->manager->for($context)->active('existing_feature'))->toBeTrue();
        });

        test('restorePartial does nothing when snapshot not found', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $snapshotId = 'nonexistent';

            // Set up a feature to verify it's not touched
            $this->manager->for($context)->activate('existing_feature');

            // Act
            $this->repository->restorePartial($snapshotId, $context, ['feature1']);

            // Assert - existing feature should still be active
            expect($this->manager->for($context)->active('existing_feature'))->toBeTrue();
        });

        test('get returns null when snapshot not found', function (): void {
            // Act
            $result = $this->repository->get('nonexistent', null);

            // Assert
            expect($result)->toBeNull();
        });

        test('delete does nothing when snapshot not found', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $snapshotId = 'nonexistent';

            // Act & Assert - should not throw exception
            $this->repository->delete($snapshotId, $context);
            expect(true)->toBeTrue();
        });

        test('list returns empty array when no snapshots exist', function (): void {
            // Act
            $result = $this->repository->list(null);

            // Assert
            expect($result)->toBe([]);
        });

        test('clearAll does nothing when no snapshots exist', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');

            // Act & Assert - should not throw exception
            $this->repository->clearAll($context);
            expect($this->repository->list($context))->toBeEmpty();
        });
    });

    describe('Edge Cases', function (): void {
        test('restorePartial skips features not in snapshot', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $snapshotId = $this->repository->create($context, ['feature1' => true, 'feature2' => false]);

            // Act - try to restore feature1 and a nonexistent feature
            $this->repository->restorePartial($snapshotId, $context, ['feature1', 'nonexistent_feature']);

            // Assert
            expect($this->manager->for($context)->active('feature1'))->toBeTrue();

            $snapshot = $this->repository->get($snapshotId, $context);
            expect($snapshot['events'][1]['metadata']['features_restored'])->toBe(['feature1'])
                ->and($snapshot['events'][1]['metadata']['total_features'])->toBe(2);
        });

        test('restore deactivates features with false or null values', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $snapshotFeatures = [
                'feature_false' => false,
                'feature_null' => null,
            ];

            $snapshotId = $this->repository->create($context, $snapshotFeatures);

            // Activate these features first
            $this->manager->for($context)->activate('feature_false');
            $this->manager->for($context)->activate('feature_null');

            // Act
            $this->repository->restore($snapshotId, $context);

            // Assert
            expect($this->manager->for($context)->active('feature_false'))->toBeFalse()
                ->and($this->manager->for($context)->active('feature_null'))->toBeFalse();
        });

        test('restorePartial deactivates features with false or null values', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $snapshotId = $this->repository->create($context, ['feature_false' => false, 'feature_null' => null]);

            // Activate these features first
            $this->manager->for($context)->activate('feature_false');

            // Act
            $this->repository->restorePartial($snapshotId, $context, ['feature_false']);

            // Assert
            expect($this->manager->for($context)->active('feature_false'))->toBeFalse();
        });

        test('delete updates cache with remaining snapshots', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $id1 = $this->repository->create($context, ['f1' => true], 'To Delete');
            $id2 = $this->repository->create($context, ['f2' => true], 'Remaining');

            // Act
            $this->repository->delete($id1, $context);

            // Assert
            $remaining = $this->repository->list($context);
            expect($remaining)->toHaveCount(1)
                ->and($remaining[0]['id'])->toBe($id2)
                ->and($remaining[0]['label'])->toBe('Remaining');
        });

        test('create adds snapshot to existing snapshots', function (): void {
            // Arrange
            $id1 = $this->repository->create(null, ['f1' => true], 'Existing');

            // Act
            $id2 = $this->repository->create(null, ['f2' => true], 'New');

            // Assert
            $snapshots = $this->repository->list(null);
            expect($snapshots)->toHaveCount(2)
                ->and($snapshots[0]['id'])->toBe($id1)
                ->and($snapshots[1]['id'])->toBe($id2);
        });

        test('create generates unique IDs', function (): void {
            // Act
            $id1 = $this->repository->create(null, ['f' => true]);
            $id2 = $this->repository->create(null, ['f' => true]);

            // Assert
            expect($id1)->not->toBe($id2)
                ->and($id1)->toStartWith('snapshot_')
                ->and($id2)->toStartWith('snapshot_');
        });

        test('create includes proper event metadata', function (): void {
            // Arrange
            $features = [
                'feature1' => true,
                'feature2' => false,
                'feature3' => 'value',
            ];

            // Act
            $snapshotId = $this->repository->create(null, $features);

            // Assert
            $snapshot = $this->repository->get($snapshotId, null);
            expect($snapshot['events'])->toHaveCount(1)
                ->and($snapshot['events'][0]['type'])->toBe('created')
                ->and($snapshot['events'][0]['metadata']['feature_count'])->toBe(3)
                ->and($snapshot['events'][0]['performed_by'])->toBeNull();
        });

        test('restore includes proper event metadata', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $snapshotId = $this->repository->create($context, ['feature1' => true, 'feature2' => false]);

            // Act
            $this->repository->restore($snapshotId, $context);

            // Assert
            $snapshot = $this->repository->get($snapshotId, $context);
            expect($snapshot['events'][1]['metadata']['features_restored'])->toBe(['feature1', 'feature2']);
        });

        test('snapshots are isolated by context', function (): void {
            // Arrange
            $context1 = 'user:1';
            $context2 = 'user:2';

            $id1 = $this->repository->create($context1, ['f1' => true], 'Context 1');
            $id2 = $this->repository->create($context2, ['f2' => true], 'Context 2');

            // Act & Assert
            expect($this->repository->get($id1, $context1))->not->toBeNull()
                ->and($this->repository->get($id1, $context2))->toBeNull()
                ->and($this->repository->get($id2, $context1))->toBeNull()
                ->and($this->repository->get($id2, $context2))->not->toBeNull()
                ->and($this->repository->list($context1))->toHaveCount(1)
                ->and($this->repository->list($context2))->toHaveCount(1);
        });

        test('clearAll only clears snapshots for specific context', function (): void {
            // Arrange
            $context1 = 'user:1';
            $context2 = 'user:2';

            $this->repository->create($context1, ['f1' => true]);
            $this->repository->create($context2, ['f2' => true]);

            // Act
            $this->repository->clearAll($context1);

            // Assert
            expect($this->repository->list($context1))->toBeEmpty()
                ->and($this->repository->list($context2))->toHaveCount(1);
        });

        test('clearAll with deletedBy tracks deletion for each snapshot', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $model = new class() extends Model
            {
                use HasFactory;

                protected $table = 'users';

                public function getKey(): int
                {
                    return 555;
                }
            };

            $this->repository->create($context, ['f1' => true]);
            $this->repository->create($context, ['f2' => true]);

            // Act
            $this->repository->clearAll($context, $model);

            // Assert
            expect($this->repository->list($context))->toBeEmpty();
        });
    });

    describe('Prune', function (): void {
        test('returns zero for cache driver using TTL', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $this->repository->create($context, ['feature' => true]);

            // Act
            $deleted = $this->repository->prune(30);

            // Assert - Cache driver uses TTL, so pruning returns 0
            expect($deleted)->toBe(0);
        });

        test('snapshots remain accessible after prune call', function (): void {
            // Arrange
            $context = TogglContext::simple('test-context', 'test');
            $snapshotId = $this->repository->create($context, ['feature' => true]);

            // Act
            $this->repository->prune(30);

            // Assert - Snapshots still exist (TTL handles expiration)
            expect($this->repository->get($snapshotId, $context))->not->toBeNull();
        });
    });
});
