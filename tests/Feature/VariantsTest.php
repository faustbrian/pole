<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Feature;

describe('Value Variants', function (): void {
    describe('Happy Path', function (): void {
        test('can define a variant with distribution', function (): void {
            // Arrange & Act
            Feature::defineVariant('checkout-flow', [
                'control' => 40,
                'v1' => 30,
                'v2' => 30,
            ]);

            // Assert
            $variants = Feature::getVariants('checkout-flow');
            expect($variants)->toBe(['control' => 40, 'v1' => 30, 'v2' => 30]);
        });

        test('variant returns one of the defined values', function (): void {
            // Arrange
            Feature::defineVariant('button-color', [
                'red' => 50,
                'blue' => 50,
            ]);

            // Act
            $variant = Feature::variant('button-color');

            // Assert
            expect($variant)->toBeIn(['red', 'blue']);
        });

        test('variant is sticky per scope', function (): void {
            // Arrange
            Feature::defineVariant('experiment', [
                'a' => 50,
                'b' => 50,
            ]);

            // Act - get variant multiple times for same scope
            $variant1 = Feature::for('user-123')->variant('experiment');
            $variant2 = Feature::for('user-123')->variant('experiment');
            $variant3 = Feature::for('user-123')->variant('experiment');

            // Assert - should always be same
            expect($variant1)->toBe($variant2);
            expect($variant2)->toBe($variant3);
        });

        test('different scopes get different variants', function (): void {
            // Arrange
            Feature::defineVariant('layout', [
                'old' => 50,
                'new' => 50,
            ]);

            // Act
            $variants = [];

            for ($i = 0; $i < 100; ++$i) {
                $variant = Feature::for('user-'.$i)->variant('layout');
                $variants[] = $variant;
            }

            // Assert - should have both variants represented
            $uniqueVariants = array_unique($variants);
            expect(count($uniqueVariants))->toBeGreaterThan(1);
        });

        test('variant distribution approximately matches weights', function (): void {
            // Arrange
            Feature::defineVariant('pricing', [
                'low' => 25,
                'medium' => 50,
                'high' => 25,
            ]);

            // Act - sample 1000 users
            $counts = ['low' => 0, 'medium' => 0, 'high' => 0];

            for ($i = 0; $i < 1_000; ++$i) {
                $variant = Feature::for('user-'.$i)->variant('pricing');
                ++$counts[$variant];
            }

            // Assert - distribution should be roughly 250/500/250 (+/- 10%)
            expect($counts['low'])->toBeGreaterThan(200);
            expect($counts['low'])->toBeLessThan(300);
            expect($counts['medium'])->toBeGreaterThan(450);
            expect($counts['medium'])->toBeLessThan(550);
            expect($counts['high'])->toBeGreaterThan(200);
            expect($counts['high'])->toBeLessThan(300);
        });

        test('can get all variant names', function (): void {
            // Arrange
            Feature::defineVariant('theme', [
                'light' => 50,
                'dark' => 50,
            ]);

            // Act
            $names = Feature::variantNames('theme');

            // Assert
            expect($names)->toBe(['light', 'dark']);
        });

        test('variant works with null scope', function (): void {
            // Arrange
            Feature::defineVariant('global-variant', [
                'a' => 50,
                'b' => 50,
            ]);

            // Act
            $variant1 = Feature::variant('global-variant');
            $variant2 = Feature::variant('global-variant');

            // Assert - should be consistent
            expect($variant1)->toBe($variant2);
        });
    });

    describe('Sad Path', function (): void {
        test('returns null for undefined variant', function (): void {
            // Act & Assert
            expect(Feature::variant('undefined'))->toBeNull();
        });

        test('returns empty array for variants of undefined feature', function (): void {
            // Act & Assert
            expect(Feature::getVariants('undefined'))->toBeEmpty();
        });

        test('returns empty array for variant names of undefined feature', function (): void {
            // Act & Assert
            expect(Feature::variantNames('undefined'))->toBeEmpty();
        });

        test('throws exception for invalid weights', function (): void {
            // Act & Assert
            expect(fn () => Feature::defineVariant('invalid', [
                'a' => 50,
                'b' => 60, // Total > 100
            ]))->toThrow(InvalidArgumentException::class, 'Variant weights must sum to 100');
        });

        test('throws exception for zero weights', function (): void {
            // Act & Assert
            expect(fn () => Feature::defineVariant('invalid', [
                'a' => 0,
                'b' => 0,
            ]))->toThrow(InvalidArgumentException::class, 'Variant weights must sum to 100');
        });
    });

    describe('Edge Cases', function (): void {
        test('single variant always returns that variant', function (): void {
            // Arrange
            Feature::defineVariant('only-one', [
                'single' => 100,
            ]);

            // Act & Assert
            for ($i = 0; $i < 10; ++$i) {
                expect(Feature::for('user-'.$i)->variant('only-one'))->toBe('single');
            }
        });

        test('variant with very small weight still gets users', function (): void {
            // Arrange
            Feature::defineVariant('rare-variant', [
                'common' => 99,
                'rare' => 1,
            ]);

            // Act - sample many users
            $sawRare = false;

            for ($i = 0; $i < 1_000; ++$i) {
                if (Feature::for('user-'.$i)->variant('rare-variant') === 'rare') {
                    $sawRare = true;

                    break;
                }
            }

            // Assert
            expect($sawRare)->toBeTrue();
        });

        test('can redefine variant', function (): void {
            // Arrange
            Feature::defineVariant('changeable', [
                'old' => 100,
            ]);

            // Act
            Feature::defineVariant('changeable', [
                'new' => 100,
            ]);

            // Assert
            expect(Feature::variant('changeable'))->toBe('new');
        });

        test('variant is stored and persists', function (): void {
            // Arrange
            Feature::defineVariant('persistent', [
                'a' => 50,
                'b' => 50,
            ]);

            // Act - get variant and store it
            $firstVariant = Feature::for('user-persistent')->variant('persistent');

            // Clear cache
            Feature::flushCache();

            // Get again
            $secondVariant = Feature::for('user-persistent')->variant('persistent');

            // Assert - should still be the same
            expect($firstVariant)->toBe($secondVariant);
        });

        test('variant handles string scopes', function (): void {
            // Arrange
            Feature::defineVariant('string-scope', [
                'x' => 50,
                'y' => 50,
            ]);

            // Act
            $variant1 = Feature::for('user@example.com')->variant('string-scope');
            $variant2 = Feature::for('user@example.com')->variant('string-scope');

            // Assert
            expect($variant1)->toBe($variant2);
        });

        test('variant handles numeric scopes', function (): void {
            // Arrange
            Feature::defineVariant('numeric-scope', [
                'p' => 50,
                'q' => 50,
            ]);

            // Act
            $variant1 = Feature::for(12_345)->variant('numeric-scope');
            $variant2 = Feature::for(12_345)->variant('numeric-scope');

            // Assert
            expect($variant1)->toBe($variant2);
        });
    });
});
