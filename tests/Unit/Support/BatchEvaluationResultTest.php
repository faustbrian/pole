<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\BatchEvaluationResult;
use Cline\Toggl\Support\EvaluationEntry;
use Illuminate\Support\Collection;

describe('BatchEvaluationResult', function (): void {
    // Helper to create test entries
    function createEntry(string $feature, string $contextKey, mixed $context, mixed $value): EvaluationEntry
    {
        return new EvaluationEntry($feature, $contextKey, $context, $value);
    }

    function createSerializer(): Closure
    {
        return fn (mixed $ctx): string => is_object($ctx) ? 'obj|'.spl_object_id($ctx) : 'val|'.$ctx;
    }

    describe('Happy Path', function (): void {
        describe('Aggregate Checks', function (): void {
            test('all() returns true when all evaluations are truthy', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, true),
                    'premium|user|2' => createEntry('premium', 'user|2', null, true),
                    'analytics|user|1' => createEntry('analytics', 'user|1', null, 1),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act & Assert
                expect($result->all())->toBeTrue();
            });

            test('all() returns false when any evaluation is falsy', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, true),
                    'premium|user|2' => createEntry('premium', 'user|2', null, false),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act & Assert
                expect($result->all())->toBeFalse();
            });

            test('any() returns true when at least one evaluation is truthy', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, false),
                    'premium|user|2' => createEntry('premium', 'user|2', null, true),
                    'premium|user|3' => createEntry('premium', 'user|3', null, false),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act & Assert
                expect($result->any())->toBeTrue();
            });

            test('any() returns false when all evaluations are falsy', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, false),
                    'premium|user|2' => createEntry('premium', 'user|2', null, 0),
                    'premium|user|3' => createEntry('premium', 'user|3', null, null),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act & Assert
                expect($result->any())->toBeFalse();
            });

            test('none() returns true when all evaluations are falsy', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, false),
                    'premium|user|2' => createEntry('premium', 'user|2', null, null),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act & Assert
                expect($result->none())->toBeTrue();
            });

            test('none() returns false when any evaluation is truthy', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, false),
                    'premium|user|2' => createEntry('premium', 'user|2', null, true),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act & Assert
                expect($result->none())->toBeFalse();
            });
        });

        describe('Counting', function (): void {
            test('count() returns total number of evaluations', function (): void {
                // Arrange
                $entries = [
                    'f1|c1' => createEntry('f1', 'c1', null, true),
                    'f1|c2' => createEntry('f1', 'c2', null, false),
                    'f2|c1' => createEntry('f2', 'c1', null, true),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act & Assert
                expect($result->count())->toBe(3);
            });

            test('countActive() returns number of truthy evaluations', function (): void {
                // Arrange
                $entries = [
                    'f1|c1' => createEntry('f1', 'c1', null, true),
                    'f1|c2' => createEntry('f1', 'c2', null, false),
                    'f2|c1' => createEntry('f2', 'c1', null, true),
                    'f2|c2' => createEntry('f2', 'c2', null, null),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act & Assert
                expect($result->countActive())->toBe(2);
            });

            test('countInactive() returns number of falsy evaluations', function (): void {
                // Arrange
                $entries = [
                    'f1|c1' => createEntry('f1', 'c1', null, true),
                    'f1|c2' => createEntry('f1', 'c2', null, false),
                    'f2|c1' => createEntry('f2', 'c1', null, 0),
                    'f2|c2' => createEntry('f2', 'c2', null, ''),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act & Assert
                expect($result->countInactive())->toBe(3);
            });
        });

        describe('Filtering', function (): void {
            test('forFeature() filters to single feature', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, true),
                    'premium|user|2' => createEntry('premium', 'user|2', null, false),
                    'analytics|user|1' => createEntry('analytics', 'user|1', null, true),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act
                $filtered = $result->forFeature('premium');

                // Assert
                expect($filtered->count())->toBe(2);
                expect($filtered->features())->toBe(['premium']);
            });

            test('forContext() filters to single context', function (): void {
                // Arrange
                $context1 = (object) ['id' => 1];
                $context2 = (object) ['id' => 2];
                $serializer = fn ($ctx): string => 'user|'.$ctx->id;

                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', $context1, true),
                    'premium|user|2' => createEntry('premium', 'user|2', $context2, false),
                    'analytics|user|1' => createEntry('analytics', 'user|1', $context1, true),
                ];
                $result = new BatchEvaluationResult($entries, $serializer);

                // Act
                $filtered = $result->forContext($context1);

                // Assert
                expect($filtered->count())->toBe(2);
                expect($filtered->countActive())->toBe(2);
            });

            test('active() returns only truthy evaluations', function (): void {
                // Arrange
                $entries = [
                    'f1|c1' => createEntry('f1', 'c1', null, true),
                    'f1|c2' => createEntry('f1', 'c2', null, false),
                    'f2|c1' => createEntry('f2', 'c1', null, 'enabled'),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act
                $active = $result->active();

                // Assert
                expect($active->count())->toBe(2);
                expect($active->all())->toBeTrue();
            });

            test('inactive() returns only falsy evaluations', function (): void {
                // Arrange
                $entries = [
                    'f1|c1' => createEntry('f1', 'c1', null, true),
                    'f1|c2' => createEntry('f1', 'c2', null, false),
                    'f2|c1' => createEntry('f2', 'c1', null, null),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act
                $inactive = $result->inactive();

                // Assert
                expect($inactive->count())->toBe(2);
                expect($inactive->none())->toBeTrue();
            });

            test('filters can be chained', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, true),
                    'premium|user|2' => createEntry('premium', 'user|2', null, false),
                    'analytics|user|1' => createEntry('analytics', 'user|1', null, true),
                    'analytics|user|2' => createEntry('analytics', 'user|2', null, false),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act
                $filtered = $result->forFeature('premium')->active();

                // Assert
                expect($filtered->count())->toBe(1);
            });
        });

        describe('Data Access', function (): void {
            test('toArray() returns key-value map', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, true),
                    'analytics|user|1' => createEntry('analytics', 'user|1', null, false),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act
                $array = $result->toArray();

                // Assert
                expect($array)->toBe([
                    'premium|user|1' => true,
                    'analytics|user|1' => false,
                ]);
            });

            test('groupByFeature() groups by feature name', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, true),
                    'premium|user|2' => createEntry('premium', 'user|2', null, false),
                    'analytics|user|1' => createEntry('analytics', 'user|1', null, true),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act
                $grouped = $result->groupByFeature();

                // Assert
                expect($grouped)->toHaveKey('premium');
                expect($grouped)->toHaveKey('analytics');
                expect($grouped['premium'])->toBe(['user|1' => true, 'user|2' => false]);
                expect($grouped['analytics'])->toBe(['user|1' => true]);
            });

            test('groupByContext() groups by context key', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, true),
                    'analytics|user|1' => createEntry('analytics', 'user|1', null, false),
                    'premium|user|2' => createEntry('premium', 'user|2', null, true),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act
                $grouped = $result->groupByContext();

                // Assert
                expect($grouped)->toHaveKey('user|1');
                expect($grouped)->toHaveKey('user|2');
                expect($grouped['user|1'])->toBe(['premium' => true, 'analytics' => false]);
                expect($grouped['user|2'])->toBe(['premium' => true]);
            });

            test('features() returns unique feature names', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, true),
                    'premium|user|2' => createEntry('premium', 'user|2', null, false),
                    'analytics|user|1' => createEntry('analytics', 'user|1', null, true),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act
                $features = $result->features();

                // Assert
                expect($features)->toContain('premium');
                expect($features)->toContain('analytics');
                expect(count($features))->toBe(2);
            });

            test('entries() returns all EvaluationEntry instances', function (): void {
                // Arrange
                $entry1 = createEntry('premium', 'user|1', null, true);
                $entry2 = createEntry('analytics', 'user|1', null, false);
                $entries = [
                    'premium|user|1' => $entry1,
                    'analytics|user|1' => $entry2,
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act
                $returned = $result->entries();

                // Assert
                expect($returned)->toHaveKey('premium|user|1');
                expect($returned)->toHaveKey('analytics|user|1');
                expect($returned['premium|user|1'])->toBe($entry1);
            });

            test('collect() returns Laravel Collection', function (): void {
                // Arrange
                $entries = [
                    'premium|user|1' => createEntry('premium', 'user|1', null, true),
                ];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act
                $collection = $result->collect();

                // Assert
                expect($collection)->toBeInstanceOf(Collection::class);
                expect($collection->count())->toBe(1);
            });
        });

        describe('Empty State', function (): void {
            test('isEmpty() returns true for empty results', function (): void {
                // Arrange
                $result = new BatchEvaluationResult([], createSerializer());

                // Act & Assert
                expect($result->isEmpty())->toBeTrue();
                expect($result->isNotEmpty())->toBeFalse();
            });

            test('isNotEmpty() returns true when results exist', function (): void {
                // Arrange
                $entries = ['f|c' => createEntry('f', 'c', null, true)];
                $result = new BatchEvaluationResult($entries, createSerializer());

                // Act & Assert
                expect($result->isNotEmpty())->toBeTrue();
                expect($result->isEmpty())->toBeFalse();
            });
        });
    });

    describe('Edge Cases', function (): void {
        test('all() returns false for empty results', function (): void {
            // Arrange
            $result = new BatchEvaluationResult([], createSerializer());

            // Act & Assert
            expect($result->all())->toBeFalse();
        });

        test('any() returns false for empty results', function (): void {
            // Arrange
            $result = new BatchEvaluationResult([], createSerializer());

            // Act & Assert
            expect($result->any())->toBeFalse();
        });

        test('none() returns true for empty results', function (): void {
            // Arrange
            $result = new BatchEvaluationResult([], createSerializer());

            // Act & Assert
            expect($result->none())->toBeTrue();
        });

        test('forFeature() returns empty result for non-existent feature', function (): void {
            // Arrange
            $entries = ['premium|user|1' => createEntry('premium', 'user|1', null, true)];
            $result = new BatchEvaluationResult($entries, createSerializer());

            // Act
            $filtered = $result->forFeature('non-existent');

            // Assert
            expect($filtered->isEmpty())->toBeTrue();
        });

        test('handles non-boolean values in aggregate checks', function (): void {
            // Arrange
            $entries = [
                'theme|user|1' => createEntry('theme', 'user|1', null, 'dark'),
                'limit|user|1' => createEntry('limit', 'user|1', null, 100),
                'config|user|1' => createEntry('config', 'user|1', null, ['key' => 'value']),
            ];
            $result = new BatchEvaluationResult($entries, createSerializer());

            // Act & Assert
            expect($result->all())->toBeTrue(); // all truthy
            expect($result->any())->toBeTrue();
            expect($result->none())->toBeFalse();
        });

        test('handles mixed truthy and falsy non-boolean values', function (): void {
            // Arrange
            $entries = [
                'enabled|u1' => createEntry('enabled', 'u1', null, 'yes'),
                'count|u1' => createEntry('count', 'u1', null, 0), // falsy
                'items|u1' => createEntry('items', 'u1', null, []), // falsy
            ];
            $result = new BatchEvaluationResult($entries, createSerializer());

            // Act & Assert
            expect($result->countActive())->toBe(1);
            expect($result->countInactive())->toBe(2);
        });

        test('filtering preserves serializer for further operations', function (): void {
            // Arrange
            $context = (object) ['id' => 1];
            $serializer = fn ($ctx): string => is_object($ctx) ? 'user|'.$ctx->id : 'other';

            $entries = [
                'premium|user|1' => createEntry('premium', 'user|1', $context, true),
                'analytics|user|1' => createEntry('analytics', 'user|1', $context, true),
            ];
            $result = new BatchEvaluationResult($entries, $serializer);

            // Act - filter should preserve serializer for forContext to work
            $filtered = $result->forFeature('premium');
            $byContext = $filtered->forContext($context);

            // Assert
            expect($byContext->count())->toBe(1);
        });
    });

    describe('Immutability', function (): void {
        test('is readonly and immutable', function (): void {
            // Arrange
            $result = new BatchEvaluationResult([], createSerializer());

            // Assert
            $reflection = new ReflectionClass($result);
            expect($reflection->isReadOnly())->toBeTrue();
        });

        test('filtering returns new instance', function (): void {
            // Arrange
            $entries = [
                'premium|user|1' => createEntry('premium', 'user|1', null, true),
                'analytics|user|1' => createEntry('analytics', 'user|1', null, true),
            ];
            $result = new BatchEvaluationResult($entries, createSerializer());

            // Act
            $filtered = $result->forFeature('premium');

            // Assert
            expect($result->count())->toBe(2);
            expect($filtered->count())->toBe(1);
            expect(spl_object_id($result))->not->toBe(spl_object_id($filtered));
        });
    });
});
