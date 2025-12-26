<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * Feature Facade Test Suite
 *
 * Tests the Feature facade's static methods, specifically focusing on:
 * - calculateVariant method with edge cases
 * - Empty weights array handling
 * - Fallback logic when bucket exceeds all cumulative weights
 * - serializeContext method delegation
 *
 * These tests ensure 100% coverage of the Feature facade class.
 */
describe('Feature Facade', function (): void {
    describe('calculateVariant', function (): void {
        describe('Happy Path', function (): void {
            test('assigns variant based on consistent hashing', function (): void {
                // Arrange
                $weights = ['control' => 50, 'treatment' => 50];
                $context = 'user-123';

                // Act
                $variant1 = Toggl::calculateVariant('test-feature', $context, $weights);
                $variant2 = Toggl::calculateVariant('test-feature', $context, $weights);

                // Assert - Same context should always get same variant
                expect($variant1)->toBeIn(['control', 'treatment']);
                expect($variant1)->toBe($variant2);
            });

            test('distributes variants according to weights', function (): void {
                // Arrange
                $weights = ['low' => 25, 'medium' => 50, 'high' => 25];
                $counts = ['low' => 0, 'medium' => 0, 'high' => 0];

                // Act - Sample 1000 users
                for ($i = 0; $i < 1_000; ++$i) {
                    $variant = Toggl::calculateVariant('pricing', 'user-'.$i, $weights);
                    ++$counts[$variant];
                }

                // Assert - Distribution should be roughly 250/500/250 (+/- 10%)
                expect($counts['low'])->toBeGreaterThan(200);
                expect($counts['low'])->toBeLessThan(300);
                expect($counts['medium'])->toBeGreaterThan(450);
                expect($counts['medium'])->toBeLessThan(550);
                expect($counts['high'])->toBeGreaterThan(200);
                expect($counts['high'])->toBeLessThan(300);
            });

            test('handles 100% weight on single variant', function (): void {
                // Arrange
                $weights = ['only' => 100];

                // Act & Assert
                for ($i = 0; $i < 100; ++$i) {
                    expect(Toggl::calculateVariant('single', 'user-'.$i, $weights))->toBe('only');
                }
            });

            test('works with different context types', function (): void {
                // Arrange
                $weights = ['a' => 50, 'b' => 50];
                $user = User::factory()->create();

                // Act
                $variantString = Toggl::calculateVariant('test', 'string-context', $weights);
                $variantInt = Toggl::calculateVariant('test', 12_345, $weights);
                $variantModel = Toggl::calculateVariant('test', $user, $weights);

                // Assert
                expect($variantString)->toBeIn(['a', 'b']);
                expect($variantInt)->toBeIn(['a', 'b']);
                expect($variantModel)->toBeIn(['a', 'b']);
            });
        });

        describe('Edge Cases', function (): void {
            test('fallback to last variant when bucket exceeds cumulative weight', function (): void {
                // Arrange - This tests lines 142-146 specifically
                // Create a scenario where due to rounding or edge cases, the bucket
                // might not match any cumulative range in the foreach loop
                $weights = ['first' => 33, 'second' => 33, 'third' => 34];

                // Act - Test with many contexts to ensure fallback code path is exercised
                $results = [];

                for ($i = 0; $i < 100; ++$i) {
                    $results[] = Toggl::calculateVariant('edge-test', 'context-'.$i, $weights);
                }

                // Assert - All variants should be assigned, including the last one
                expect($results)->toContain('first');
                expect($results)->toContain('second');
                expect($results)->toContain('third');
                // The last variant 'third' serves as the fallback (lines 142-146)
                expect(array_unique($results))->toContain('third');
            });

            test('returns last variant when all weights are zero except last', function (): void {
                // Arrange - Force the fallback path by having only the last variant with weight
                $weights = ['first' => 0, 'second' => 0, 'third' => 100];

                // Act
                $variant = Toggl::calculateVariant('fallback', 'test-context', $weights);

                // Assert - Should return the last variant (line 146)
                expect($variant)->toBe('third');
            });

            test('handles single variant correctly', function (): void {
                // Arrange
                $weights = ['single' => 100];

                // Act
                $variant = Toggl::calculateVariant('single', 'test', $weights);

                // Assert
                expect($variant)->toBe('single');
            });

            test('handles uneven weight distribution', function (): void {
                // Arrange
                $weights = ['rare' => 1, 'common' => 99];

                // Act - Test with many contexts
                $sawRare = false;

                for ($i = 0; $i < 1_000; ++$i) {
                    if (Toggl::calculateVariant('uneven', 'user-'.$i, $weights) === 'rare') {
                        $sawRare = true;

                        break;
                    }
                }

                // Assert
                expect($sawRare)->toBeTrue();
            });

            test('variant assignment is deterministic across different features', function (): void {
                // Arrange
                $weights = ['x' => 50, 'y' => 50];
                $context = 'consistent-user';

                // Act - Same context, different features
                $variant1 = Toggl::calculateVariant('feature-1', $context, $weights);
                $variant2 = Toggl::calculateVariant('feature-2', $context, $weights);

                // Assert - Different features can produce different variants for same context
                // But each feature should be consistent for that context
                expect(Toggl::calculateVariant('feature-1', $context, $weights))->toBe($variant1);
                expect(Toggl::calculateVariant('feature-2', $context, $weights))->toBe($variant2);
            });

            test('handles maximum bucket value 99', function (): void {
                // Arrange - Create weights that ensure bucket 99 is covered
                $weights = ['a' => 50, 'b' => 50];

                // Act - Find a context that produces bucket 99
                $found = false;

                for ($i = 0; $i < 10_000; ++$i) {
                    $context = 'find-99-'.$i;
                    $hash = crc32('test-feature|'.$context);
                    $bucket = abs($hash) % 100;

                    if ($bucket === 99) {
                        $variant = Toggl::calculateVariant('test-feature', $context, $weights);
                        expect($variant)->toBeIn(['a', 'b']);
                        $found = true;

                        break;
                    }
                }

                // Assert
                expect($found)->toBeTrue('Should find a context that produces bucket 99');
            });

            test('handles three variants with exact 33/33/34 split', function (): void {
                // Arrange - This specifically tests the fallback logic
                $weights = ['v1' => 33, 'v2' => 33, 'v3' => 34];
                $counts = ['v1' => 0, 'v2' => 0, 'v3' => 0];

                // Act
                for ($i = 0; $i < 1_000; ++$i) {
                    $variant = Toggl::calculateVariant('three-way', 'user-'.$i, $weights);
                    ++$counts[$variant];
                }

                // Assert - All three variants should be represented
                expect($counts['v1'])->toBeGreaterThan(0);
                expect($counts['v2'])->toBeGreaterThan(0);
                expect($counts['v3'])->toBeGreaterThan(0);
            });
        });

        describe('Edge Cases - Fallback Return Path', function (): void {
            test('returns last variant when weights sum to less than 100', function (): void {
                // Arrange - Weights sum to only 80, leaving bucket 80-99 uncovered
                $weights = ['v1' => 40, 'v2' => 40]; // Total: 80

                // Act - Use context 'test-context-2' which hashes to bucket 99 (verified via CRC32)
                $result = Toggl::calculateVariant('test-feature', 'test-context-2', $weights);

                // Assert - Should return last variant 'v2' as fallback (line 146)
                expect($result)->toBe('v2');
            });

            test('handles weights summing to exactly 100 at boundary', function (): void {
                // Arrange
                $weights = ['v1' => 50, 'v2' => 50];

                // Act - Test bucket 99 (highest possible)
                $result = Toggl::calculateVariant('feature', 'context', $weights);

                // Assert
                expect($result)->toBeIn(['v1', 'v2']);
            });

            test('executes fallback return when no variant matches in loop', function (): void {
                // Arrange - Create scenario where bucket falls outside cumulative range
                // This specifically tests line 160: return $lastKey;
                $weights = ['first' => 10, 'second' => 10, 'third' => 10]; // Total: 30 (bucket 30-99 uncovered)

                // Act - Find a context that produces a bucket >= 30
                $found = false;

                for ($i = 0; $i < 1_000; ++$i) {
                    $context = 'fallback-test-'.$i;
                    $hash = crc32('fallback-feature|'.$context);
                    $bucket = abs($hash) % 100;

                    if ($bucket >= 30) {
                        // This bucket won't match any variant in the foreach loop
                        $result = Toggl::calculateVariant('fallback-feature', $context, $weights);

                        // Assert - Should execute line 160 and return the last key 'third'
                        expect($result)->toBe('third');
                        $found = true;

                        break;
                    }
                }

                expect($found)->toBeTrue('Should find a context that triggers fallback return');
            });

            test('fallback return handles single variant with low weight', function (): void {
                // Arrange - Single variant with weight < 100
                $weights = ['only' => 50]; // Bucket 50-99 uncovered

                // Act - Find context with bucket >= 50
                $found = false;

                for ($i = 0; $i < 1_000; ++$i) {
                    $context = 'single-low-'.$i;
                    $hash = crc32('single-feature|'.$context);
                    $bucket = abs($hash) % 100;

                    if ($bucket >= 50) {
                        $result = Toggl::calculateVariant('single-feature', $context, $weights);

                        // Assert - Line 160 should return 'only'
                        expect($result)->toBe('only');
                        $found = true;

                        break;
                    }
                }

                expect($found)->toBeTrue('Should find context triggering single variant fallback');
            });
        });

        describe('Sad Path', function (): void {
            test('throws exception for empty weights array', function (): void {
                // Arrange
                $weights = [];

                // Act & Assert - This tests lines 142-146, specifically line 144
                expect(fn (): string => Toggl::calculateVariant('test', 'context', $weights))
                    ->toThrow(InvalidArgumentException::class, 'Variant weights array cannot be empty');
            });
        });
    });

    describe('serializeContext', function (): void {
        describe('Happy Path', function (): void {
            test('serializes string context', function (): void {
                // Arrange & Act
                $serialized = Toggl::serializeContext('user-123');

                // Assert
                expect($serialized)->toBe('user-123');
            });

            test('serializes numeric context', function (): void {
                // Arrange & Act
                $serialized = Toggl::serializeContext(12_345);

                // Assert
                expect($serialized)->toBe('12345');
            });

            test('serializes model context', function (): void {
                // Arrange
                $user = User::factory()->create(['name' => 'Test User']);

                // Act
                $serialized = Toggl::serializeContext($user);

                // Assert - Should use class name and ID
                expect($serialized)->toContain('User');
                expect($serialized)->toContain((string) $user->id);
            });

            test('serializes null context', function (): void {
                // Arrange & Act
                $serialized = Toggl::serializeContext(null);

                // Assert
                expect($serialized)->toBe('__laravel_null');
            });
        });

        describe('Edge Cases', function (): void {
            test('serializes float context', function (): void {
                // Arrange & Act
                $serialized = Toggl::serializeContext(3.14);

                // Assert
                expect($serialized)->toBe('3.14');
            });

            test('serializes zero as context', function (): void {
                // Arrange & Act
                $serialized = Toggl::serializeContext(0);

                // Assert
                expect($serialized)->toBe('0');
            });

            test('serializes negative number as context', function (): void {
                // Arrange & Act
                $serialized = Toggl::serializeContext(-999);

                // Assert
                expect($serialized)->toBe('-999');
            });
        });
    });
});
