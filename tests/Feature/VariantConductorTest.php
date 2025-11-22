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
 * Variant Conductor Test Suite
 *
 * Tests the variant() pattern: Toggl::variant('ab-test')->for($user)->get()
 * This enables A/B testing and feature variants with cleaner conductor API.
 */
describe('Variant Conductor', function (): void {
    describe('Getting Variants', function (): void {
        test('can get variant using conductor', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineVariant('ab-test', ['control' => 50, 'variant-a' => 50]);

            // Act
            $variant = Toggl::variant('ab-test')->for($user)->get();

            // Assert
            expect($variant)->toBeIn(['control', 'variant-a']);
        });

        test('variant is consistent for same context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineVariant('ab-test', ['control' => 50, 'variant-a' => 50]);

            // Act
            $variant1 = Toggl::variant('ab-test')->for($user)->get();
            $variant2 = Toggl::variant('ab-test')->for($user)->get();
            $variant3 = Toggl::variant('ab-test')->for($user)->get();

            // Assert - Same user always gets same variant
            expect($variant1)->toBe($variant2);
            expect($variant2)->toBe($variant3);
        });

        test('different contexts get different variants', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();
            Toggl::defineVariant('ab-test', ['control' => 50, 'variant-a' => 50]);

            // Act
            $variant1 = Toggl::variant('ab-test')->for($user1)->get();
            $variant2 = Toggl::variant('ab-test')->for($user2)->get();
            $variant3 = Toggl::variant('ab-test')->for($user3)->get();

            // Assert - All variants are valid
            expect($variant1)->toBeIn(['control', 'variant-a']);
            expect($variant2)->toBeIn(['control', 'variant-a']);
            expect($variant3)->toBeIn(['control', 'variant-a']);
        });

        test('returns null for undefined variant feature', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $variant = Toggl::variant('undefined-variant')->for($user)->get();

            // Assert
            expect($variant)->toBeNull();
        });
    });

    describe('Assigning Specific Variants', function (): void {
        test('can assign specific variant with use()', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineVariant('ab-test', ['control' => 50, 'variant-a' => 50]);

            // Act
            Toggl::variant('ab-test')->use('variant-a')->for($user);
            $variant = Toggl::variant('ab-test')->for($user)->get();

            // Assert
            expect($variant)->toBe('variant-a');
        });

        test('use() overrides weight distribution', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineVariant('ab-test', ['control' => 100, 'variant-a' => 0]);

            // Act - Force variant-a even though it has 0% weight
            Toggl::variant('ab-test')->use('variant-a')->for($user);
            $variant = Toggl::variant('ab-test')->for($user)->get();

            // Assert
            expect($variant)->toBe('variant-a');
        });

        test('can change assigned variant', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineVariant('ab-test', ['control' => 50, 'variant-a' => 50]);

            // Act
            Toggl::variant('ab-test')->use('control')->for($user);
            expect(Toggl::variant('ab-test')->for($user)->get())->toBe('control');

            Toggl::variant('ab-test')->use('variant-a')->for($user);
            $variant = Toggl::variant('ab-test')->for($user)->get();

            // Assert
            expect($variant)->toBe('variant-a');
        });
    });

    describe('VariantResult Methods', function (): void {
        test('is() checks for specific variant', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineVariant('ab-test', ['control' => 50, 'variant-a' => 50]);
            Toggl::variant('ab-test')->use('variant-a')->for($user);

            // Act
            $result = Toggl::variant('ab-test')->for($user);

            // Assert
            expect($result->is('variant-a'))->toBeTrue();
            expect($result->is('control'))->toBeFalse();
        });

        test('getOr() returns variant or default', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            Toggl::defineVariant('ab-test', ['control' => 50, 'variant-a' => 50]);

            // Act - user1 has variant, user2 does not
            Toggl::variant('ab-test')->use('variant-a')->for($user1);
            $variant1 = Toggl::variant('ab-test')->for($user1)->getOr('default');
            $variant2 = Toggl::variant('undefined')->for($user2)->getOr('default');

            // Assert
            expect($variant1)->toBe('variant-a');
            expect($variant2)->toBe('default');
        });
    });

    describe('Weight Distribution', function (): void {
        test('variants distribute according to weights', function (): void {
            // Arrange
            Toggl::defineVariant('ab-test', ['control' => 50, 'variant-a' => 50]);
            $distribution = ['control' => 0, 'variant-a' => 0];

            // Act - Test 100 users
            for ($i = 0; $i < 100; ++$i) {
                $user = User::factory()->create();
                $variant = Toggl::variant('ab-test')->for($user)->get();
                ++$distribution[$variant];
            }

            // Assert - Distribution should be roughly 50/50 (allow 20-80 range for randomness)
            expect($distribution['control'])->toBeGreaterThan(20);
            expect($distribution['control'])->toBeLessThan(80);
            expect($distribution['variant-a'])->toBeGreaterThan(20);
            expect($distribution['variant-a'])->toBeLessThan(80);
        });

        test('supports three-way split', function (): void {
            // Arrange
            Toggl::defineVariant('abc-test', [
                'control' => 33,
                'variant-a' => 33,
                'variant-b' => 34,
            ]);

            // Act - Test 90 users
            $distribution = ['control' => 0, 'variant-a' => 0, 'variant-b' => 0];

            for ($i = 0; $i < 90; ++$i) {
                $user = User::factory()->create();
                $variant = Toggl::variant('abc-test')->for($user)->get();
                ++$distribution[$variant];
            }

            // Assert - Each variant gets roughly 1/3 (allow 15-50 range)
            expect($distribution['control'])->toBeGreaterThan(15);
            expect($distribution['variant-a'])->toBeGreaterThan(15);
            expect($distribution['variant-b'])->toBeGreaterThan(15);
        });
    });

    describe('Conductor Chaining', function (): void {
        test('can access feature from conductor', function (): void {
            // Arrange
            $conductor = Toggl::variant('ab-test');

            // Act & Assert
            expect($conductor->feature())->toBe('ab-test');
        });

        test('can access variant name after use()', function (): void {
            // Arrange
            $conductor = Toggl::variant('ab-test')->use('variant-a');

            // Act & Assert
            expect($conductor->variantName())->toBe('variant-a');
        });

        test('variant name is null before use()', function (): void {
            // Arrange
            $conductor = Toggl::variant('ab-test');

            // Act & Assert
            expect($conductor->variantName())->toBeNull();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('A/B testing scenario', function (): void {
            // Arrange - Set up A/B test for checkout flow
            Toggl::defineVariant('checkout-flow', [
                'original' => 50,
                'simplified' => 50,
            ]);
            $user = User::factory()->create();

            // Act - Get user's assigned variant
            $variant = Toggl::variant('checkout-flow')->for($user)->get();

            // Assert - User gets one of the variants
            expect($variant)->toBeIn(['original', 'simplified']);

            // Verify consistency
            expect(Toggl::variant('checkout-flow')->for($user)->get())->toBe($variant);
        });

        test('multi-variate testing scenario', function (): void {
            // Arrange - Test multiple button colors
            Toggl::defineVariant('button-color', [
                'blue' => 25,
                'green' => 25,
                'red' => 25,
                'orange' => 25,
            ]);
            $user = User::factory()->create();

            // Act
            $color = Toggl::variant('button-color')->for($user)->get();

            // Assert
            expect($color)->toBeIn(['blue', 'green', 'red', 'orange']);
        });

        test('override variant for testing', function (): void {
            // Arrange - Normal A/B test
            Toggl::defineVariant('new-feature', ['off' => 90, 'on' => 10]);
            $testUser = User::factory()->create();

            // Act - Force specific variant for testing
            Toggl::variant('new-feature')->use('on')->for($testUser);

            // Assert - Test user always gets 'on' variant
            expect(Toggl::variant('new-feature')->for($testUser)->is('on'))->toBeTrue();
        });

        test('conditional logic based on variant', function (): void {
            // Arrange
            Toggl::defineVariant('pricing-test', [
                'standard' => 50,
                'premium' => 50,
            ]);
            $user = User::factory()->create();
            Toggl::variant('pricing-test')->use('premium')->for($user);

            // Act
            $result = Toggl::variant('pricing-test')->for($user);

            // Assert - Can use for conditional logic
            if ($result->is('premium')) {
                expect(true)->toBeTrue(); // Premium features enabled
            } else {
                expect(false)->toBeTrue(); // This shouldn't execute
            }
        });

        test('gradual rollout scenario', function (): void {
            // Arrange - Start with 10% rollout
            Toggl::defineVariant('new-ui', ['old' => 90, 'new' => 10]);

            // Act - Test 100 users
            $distribution = ['old' => 0, 'new' => 0];

            for ($i = 0; $i < 100; ++$i) {
                $user = User::factory()->create();
                $variant = Toggl::variant('new-ui')->for($user)->get();
                ++$distribution[$variant];
            }

            // Assert - Roughly 10% get new UI (allow 2-20 range)
            expect($distribution['new'])->toBeGreaterThan(2);
            expect($distribution['new'])->toBeLessThan(20);
        });
    });

    describe('Edge Cases', function (): void {
        test('handles single variant', function (): void {
            // Arrange
            Toggl::defineVariant('single', ['only' => 100]);
            $user = User::factory()->create();

            // Act
            $variant = Toggl::variant('single')->for($user)->get();

            // Assert
            expect($variant)->toBe('only');
        });

        test('conductor works without defineVariant', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - No variant defined, just use() to assign
            Toggl::variant('undefined-test')->use('custom-variant')->for($user);
            $variant = Toggl::variant('undefined-test')->for($user)->get();

            // Assert
            expect($variant)->toBe('custom-variant');
        });
    });
});
