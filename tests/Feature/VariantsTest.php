<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Exceptions\InvalidContextTypeException;
use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * Value Variants Test Suite
 *
 * Tests A/B testing and multivariate feature functionality where features
 * return different values based on weighted distribution. Covers variant
 * definition with percentage weights, sticky variant assignment per context,
 * statistical distribution validation, variant persistence, and edge cases
 * like single variants and very small weight percentages.
 */
describe('Value Variants', function (): void {
    describe('Happy Path', function (): void {
        test('can define a variant with distribution', function (): void {
            // Arrange & Act
            Toggl::defineVariant('checkout-flow', [
                'control' => 40,
                'v1' => 30,
                'v2' => 30,
            ]);

            // Assert
            $variants = Toggl::getVariants('checkout-flow');
            expect($variants)->toBe(['control' => 40, 'v1' => 30, 'v2' => 30]);
        });

        test('variant returns one of the defined values', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineVariant('button-color', [
                'red' => 50,
                'blue' => 50,
            ]);

            // Act
            $variant = Toggl::variant('button-color')->for($user)->get();

            // Assert
            expect($variant)->toBeIn(['red', 'blue']);
        });

        test('variant is sticky per context', function (): void {
            // Arrange
            Toggl::defineVariant('experiment', [
                'a' => 50,
                'b' => 50,
            ]);

            // Act - get variant multiple times for same context
            $context = TogglContext::simple('user-123', 'user');
            $variant1 = Toggl::for($context)->variant('experiment');
            $variant2 = Toggl::for($context)->variant('experiment');
            $variant3 = Toggl::for($context)->variant('experiment');

            // Assert - should always be same
            expect($variant1)->toBe($variant2);
            expect($variant2)->toBe($variant3);
        });

        test('different contexts get different variants', function (): void {
            // Arrange
            Toggl::defineVariant('layout', [
                'old' => 50,
                'new' => 50,
            ]);

            // Act
            $variants = [];

            for ($i = 0; $i < 100; ++$i) {
                $variant = Toggl::for(TogglContext::simple('user-'.$i, 'user'))->variant('layout');
                $variants[] = $variant;
            }

            // Assert - should have both variants represented
            $uniqueVariants = array_unique($variants);
            expect(count($uniqueVariants))->toBeGreaterThan(1);
        });

        test('variant distribution approximately matches weights', function (): void {
            // Arrange
            Toggl::defineVariant('pricing', [
                'low' => 25,
                'medium' => 50,
                'high' => 25,
            ]);

            // Act - sample 1000 users
            $counts = ['low' => 0, 'medium' => 0, 'high' => 0];

            for ($i = 0; $i < 1_000; ++$i) {
                $variant = Toggl::for(TogglContext::simple('user-'.$i, 'user'))->variant('pricing');
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
            Toggl::defineVariant('theme', [
                'light' => 50,
                'dark' => 50,
            ]);

            // Act
            $names = Toggl::variantNames('theme');

            // Assert
            expect($names)->toBe(['light', 'dark']);
        });

        test('variant works with null context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineVariant('global-variant', [
                'a' => 50,
                'b' => 50,
            ]);

            // Act
            $variant1 = Toggl::variant('global-variant')->for($user)->get();
            $variant2 = Toggl::variant('global-variant')->for($user)->get();

            // Assert - should be consistent
            expect($variant1)->toBe($variant2);
        });
    });

    describe('Sad Path', function (): void {
        test('returns null for undefined variant', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act & Assert
            expect(Toggl::variant('undefined')->for($user)->get())->toBeNull();
        });

        test('returns empty array for variants of undefined feature', function (): void {
            // Act & Assert
            expect(Toggl::getVariants('undefined'))->toBeEmpty();
        });

        test('returns empty array for variant names of undefined feature', function (): void {
            // Act & Assert
            expect(Toggl::variantNames('undefined'))->toBeEmpty();
        });

        test('throws exception for invalid weights', function (): void {
            // Act & Assert
            expect(fn () => Toggl::defineVariant('invalid', [
                'a' => 50,
                'b' => 60, // Total > 100
            ]))->toThrow(InvalidArgumentException::class, 'Variant weights must sum to 100');
        });

        test('throws exception for zero weights', function (): void {
            // Act & Assert
            expect(fn () => Toggl::defineVariant('invalid', [
                'a' => 0,
                'b' => 0,
            ]))->toThrow(InvalidArgumentException::class, 'Variant weights must sum to 100');
        });
    });

    describe('Edge Cases', function (): void {
        test('single variant always returns that variant', function (): void {
            // Arrange
            Toggl::defineVariant('only-one', [
                'single' => 100,
            ]);

            // Act & Assert
            for ($i = 0; $i < 10; ++$i) {
                expect(Toggl::for(TogglContext::simple('user-'.$i, 'user'))->variant('only-one'))->toBe('single');
            }
        });

        test('variant with very small weight still gets users', function (): void {
            // Arrange
            Toggl::defineVariant('rare-variant', [
                'common' => 99,
                'rare' => 1,
            ]);

            // Act - sample many users
            $sawRare = false;

            for ($i = 0; $i < 1_000; ++$i) {
                if (Toggl::for(TogglContext::simple('user-'.$i, 'user'))->variant('rare-variant') === 'rare') {
                    $sawRare = true;

                    break;
                }
            }

            // Assert
            expect($sawRare)->toBeTrue();
        });

        test('can redefine variant', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineVariant('changeable', [
                'old' => 100,
            ]);

            // Act
            Toggl::defineVariant('changeable', [
                'new' => 100,
            ]);

            // Assert
            expect(Toggl::variant('changeable')->for($user)->get())->toBe('new');
        });

        test('variant is stored and persists', function (): void {
            // Arrange
            Toggl::defineVariant('persistent', [
                'a' => 50,
                'b' => 50,
            ]);

            // Act - get variant and store it
            $context = TogglContext::simple('user-persistent', 'user');
            $firstVariant = Toggl::for($context)->variant('persistent');

            // Clear cache
            Toggl::flushCache();

            // Get again
            $secondVariant = Toggl::for($context)->variant('persistent');

            // Assert - should still be the same
            expect($firstVariant)->toBe($secondVariant);
        });

        test('variant handles TogglContext with string id', function (): void {
            // Arrange
            Toggl::defineVariant('string-context', [
                'x' => 50,
                'y' => 50,
            ]);

            // Act
            $variant1 = Toggl::for(TogglContext::simple('user@example.com', 'email'))->variant('string-context');
            $variant2 = Toggl::for(TogglContext::simple('user@example.com', 'email'))->variant('string-context');

            // Assert
            expect($variant1)->toBe($variant2);
        });

        test('variant handles TogglContext with numeric id', function (): void {
            // Arrange
            Toggl::defineVariant('numeric-context', [
                'p' => 50,
                'q' => 50,
            ]);

            // Act
            $variant1 = Toggl::for(TogglContext::simple(12_345, 'user'))->variant('numeric-context');
            $variant2 = Toggl::for(TogglContext::simple(12_345, 'user'))->variant('numeric-context');

            // Assert
            expect($variant1)->toBe($variant2);
        });

        test('throws for raw string context', function (): void {
            // Arrange
            Toggl::defineVariant('string-context', [
                'x' => 50,
                'y' => 50,
            ]);

            // Act & Assert - raw strings are no longer valid
            expect(fn () => Toggl::for('user@example.com')->variant('string-context'))
                ->toThrow(InvalidContextTypeException::class);
        });

        test('throws for raw numeric context', function (): void {
            // Arrange
            Toggl::defineVariant('numeric-context', [
                'p' => 50,
                'q' => 50,
            ]);

            // Act & Assert - raw numbers are no longer valid
            expect(fn () => Toggl::for(12_345)->variant('numeric-context'))
                ->toThrow(InvalidContextTypeException::class);
        });
    });
});
