<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Contracts\GroupRepository;
use Cline\Toggl\GroupBuilder;

/**
 * GroupBuilder test suite.
 *
 * Tests the fluent builder interface for creating feature groups. The GroupBuilder
 * provides a chainable API for defining groups with features and metadata, following
 * the builder pattern. Tests verify feature accumulation, metadata merging across
 * multiple calls, and proper repository interactions during save operations.
 */
describe('GroupBuilder', function (): void {
    /**
     * Initialize a mock repository and builder before each test.
     */
    beforeEach(function (): void {
        $this->repository = Mockery::mock(GroupRepository::class);
        $this->builder = new GroupBuilder($this->repository, 'test-group');
    });

    afterEach(function (): void {
        Mockery::close();
    });

    describe('Happy Path', function (): void {
        test('can add features to group', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', ['feature1', 'feature2'], []);

            // Act
            $result = $this->builder->with('feature1', 'feature2');

            // Assert
            expect($result)->toBe($this->builder);
            $this->builder->save();
        });

        test('can add features incrementally', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', ['feature1', 'feature2', 'feature3'], []);

            // Act
            $result = $this->builder
                ->with('feature1')
                ->with('feature2', 'feature3');

            // Assert
            expect($result)->toBe($this->builder);
            $this->builder->save();
        });

        test('can set metadata on group', function (): void {
            // Arrange
            $metadata = [
                'description' => 'Premium features',
                'tier' => 'premium',
            ];

            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', [], $metadata);

            // Act
            $result = $this->builder->meta($metadata);

            // Assert
            expect($result)->toBe($this->builder);
            $this->builder->save();
        });

        test('can merge metadata across multiple calls', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', [], [
                    'description' => 'Premium features',
                    'tier' => 'premium',
                    'owner' => 'product-team',
                    'created_at' => '2025-01-01',
                ]);

            // Act
            $result = $this->builder
                ->meta(['description' => 'Premium features', 'tier' => 'premium'])
                ->meta(['owner' => 'product-team', 'created_at' => '2025-01-01']);

            // Assert
            expect($result)->toBe($this->builder);
            $this->builder->save();
        });

        test('can combine features and metadata', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', ['feature1', 'feature2'], [
                    'description' => 'Test group',
                    'tier' => 'basic',
                ]);

            // Act
            $result = $this->builder
                ->with('feature1', 'feature2')
                ->meta(['description' => 'Test group', 'tier' => 'basic']);

            // Assert
            expect($result)->toBe($this->builder);
            $this->builder->save();
        });

        test('saves group with all accumulated data', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', ['feat1', 'feat2', 'feat3'], [
                    'description' => 'Experimental features',
                    'owner' => 'engineering',
                    'stage' => 'beta',
                ]);

            // Act
            $this->builder
                ->with('feat1')
                ->meta(['description' => 'Experimental features'])
                ->with('feat2', 'feat3')
                ->meta(['owner' => 'engineering', 'stage' => 'beta'])
                ->save();

            // Assert - verified through Mockery expectations
        });
    });

    describe('Edge Cases', function (): void {
        test('handles empty features array', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', [], []);

            // Act & Assert
            $this->builder->save();
        });

        test('handles empty metadata array', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', ['feature1'], []);

            // Act
            $this->builder
                ->with('feature1')
                ->meta([])
                ->save();

            // Assert - verified through Mockery expectations
        });

        test('preserves feature order', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', ['alpha', 'beta', 'gamma', 'delta'], []);

            // Act
            $this->builder
                ->with('alpha', 'beta')
                ->with('gamma')
                ->with('delta')
                ->save();

            // Assert - verified through Mockery expectations
        });

        test('later metadata values override earlier ones for same key', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', [], [
                    'description' => 'Updated description',
                    'tier' => 'premium',
                ]);

            // Act
            $this->builder
                ->meta(['description' => 'Initial description', 'tier' => 'basic'])
                ->meta(['description' => 'Updated description', 'tier' => 'premium'])
                ->save();

            // Assert - verified through Mockery expectations
        });

        test('handles complex metadata values', function (): void {
            // Arrange
            $complexMetadata = [
                'features' => ['advanced', 'beta'],
                'permissions' => ['read', 'write', 'delete'],
                'config' => [
                    'timeout' => 300,
                    'retries' => 3,
                ],
                'enabled' => true,
                'weight' => 99.5,
            ];

            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', [], $complexMetadata);

            // Act
            $this->builder
                ->meta($complexMetadata)
                ->save();

            // Assert - verified through Mockery expectations
        });

        test('handles single feature addition', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', ['solo-feature'], []);

            // Act
            $this->builder
                ->with('solo-feature')
                ->save();

            // Assert - verified through Mockery expectations
        });

        test('handles multiple meta calls with partial overlaps', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', [], [
                    'key1' => 'value1',
                    'key2' => 'value2-updated',
                    'key3' => 'value3',
                    'key4' => 'value4',
                ]);

            // Act
            $this->builder
                ->meta(['key1' => 'value1', 'key2' => 'value2'])
                ->meta(['key2' => 'value2-updated', 'key3' => 'value3'])
                ->meta(['key4' => 'value4'])
                ->save();

            // Assert - verified through Mockery expectations
        });

        test('maintains builder state across chained operations', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', ['f1', 'f2', 'f3', 'f4'], [
                    'm1' => 'v1',
                    'm2' => 'v2',
                    'm3' => 'v3',
                ]);

            // Act
            $builder = $this->builder
                ->with('f1')
                ->meta(['m1' => 'v1'])
                ->with('f2', 'f3')
                ->meta(['m2' => 'v2'])
                ->with('f4')
                ->meta(['m3' => 'v3']);

            // Assert
            expect($builder)->toBe($this->builder);
            $builder->save();
        });
    });

    describe('Metadata Merging Behavior', function (): void {
        test('merges metadata using spread operator correctly', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', [], [
                    'a' => 1,
                    'b' => 2,
                    'c' => 3,
                    'd' => 4,
                ]);

            // Act - Test the exact behavior of lines 104-106
            $this->builder
                ->meta(['a' => 1, 'b' => 2])
                ->meta(['c' => 3, 'd' => 4])
                ->save();

            // Assert - verified through Mockery expectations
        });

        test('metadata merging preserves numeric keys', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', [], [
                    0 => 'first',
                    1 => 'second',
                    2 => 'third',
                ]);

            // Act
            $this->builder
                ->meta([0 => 'first', 1 => 'second'])
                ->meta([2 => 'third'])
                ->save();

            // Assert - verified through Mockery expectations
        });

        test('metadata merging with nested arrays', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', [], [
                    'settings' => ['theme' => 'dark'],
                    'permissions' => ['read', 'write'],
                ]);

            // Act
            $this->builder
                ->meta(['settings' => ['theme' => 'dark']])
                ->meta(['permissions' => ['read', 'write']])
                ->save();

            // Assert - verified through Mockery expectations
        });

        test('metadata key collision replaces with later value', function (): void {
            // Arrange
            $this->repository->shouldReceive('define')
                ->once()
                ->with('test-group', [], [
                    'status' => 'final',
                ]);

            // Act
            $this->builder
                ->meta(['status' => 'draft'])
                ->meta(['status' => 'review'])
                ->meta(['status' => 'final'])
                ->save();

            // Assert - verified through Mockery expectations
        });
    });
});
