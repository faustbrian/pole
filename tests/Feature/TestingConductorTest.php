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
 * Testing Conductor Test Suite
 *
 * Tests feature flag fakes and test doubles for testing scenarios.
 */
describe('Testing Conductor', function (): void {
    describe('Single Feature Faking', function (): void {
        test('fakes boolean feature as enabled', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::testing('premium')
                ->fake(true)
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('fakes boolean feature as disabled', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::testing('premium')
                ->fake(false)
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
        });

        test('fakes feature with integer value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::testing('api-limit')
                ->fake(100)
                ->for($user);

            // Assert
            expect(Toggl::for($user)->value('api-limit'))->toBe(100);
        });

        test('fakes feature with string value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::testing('theme')
                ->fake('dark')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
        });

        test('fakes feature with array value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::testing('permissions')
                ->fake(['read', 'write', 'delete'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->value('permissions'))->toBe(['read', 'write', 'delete']);
        });
    });

    describe('Multiple Feature Faking', function (): void {
        test('fakes multiple features at once', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::testing()
                ->fakeMany([
                    'premium' => true,
                    'analytics' => true,
                    'export' => false,
                ])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeFalse();
        });

        test('fakes multiple features with mixed values', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::testing()
                ->fakeMany([
                    'premium' => true,
                    'api-limit' => 100,
                    'theme' => 'dark',
                    'disabled-feature' => false,
                ])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->value('api-limit'))->toBe(100);
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
            expect(Toggl::for($user)->active('disabled-feature'))->toBeFalse();
        });

        test('fakes empty array of features', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::testing()
                ->fakeMany([])
                ->for($user);

            // Assert - No features active
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
        });
    });

    describe('Global Faking', function (): void {
        test('fakes feature globally for all contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act - Fake globally
            Toggl::testing('premium')
                ->fake(true)
                ->globally();

            // Assert - Both users have feature
            expect(Toggl::for($user1)->active('premium'))->toBeTrue();
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
        });

        test('fakes multiple features globally', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act
            Toggl::testing()
                ->fakeMany([
                    'premium' => true,
                    'api-limit' => 100,
                ])
                ->globally();

            // Assert
            expect(Toggl::for($user1)->active('premium'))->toBeTrue();
            expect(Toggl::for($user1)->value('api-limit'))->toBe(100);
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
            expect(Toggl::for($user2)->value('api-limit'))->toBe(100);
        });

        test('global fake with string value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::testing('theme')
                ->fake('light')
                ->globally();

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('light');
        });
    });

    describe('Real-World Testing Scenarios', function (): void {
        test('fakes premium subscription in test', function (): void {
            // Arrange - Test setup
            $user = User::factory()->create();

            // Act - Fake premium for testing premium features
            Toggl::testing('premium')
                ->fake(true)
                ->for($user);

            // Assert - Can test premium-only code paths
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('fakes API rate limits for testing', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Set specific limit for testing edge cases
            Toggl::testing('api-rate-limit')
                ->fake(10)
                ->for($user);

            // Assert
            expect(Toggl::for($user)->value('api-rate-limit'))->toBe(10);
        });

        test('fakes disabled feature to test fallback paths', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Disable feature to test fallback behavior
            Toggl::testing('new-dashboard')
                ->fake(false)
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('new-dashboard'))->toBeFalse();
        });

        test('fakes multiple features for integration test', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Set up complete test environment
            Toggl::testing()
                ->fakeMany([
                    'premium' => true,
                    'analytics' => true,
                    'export-limit' => 1_000,
                    'theme' => 'dark',
                    'beta-features' => false,
                ])
                ->for($user);

            // Assert - All fakes applied
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->value('export-limit'))->toBe(1_000);
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
            expect(Toggl::for($user)->active('beta-features'))->toBeFalse();
        });

        test('fakes feature for specific user in multi-user test', function (): void {
            // Arrange
            $premiumUser = User::factory()->create();
            $freeUser = User::factory()->create();

            // Act - Only premium user gets feature
            Toggl::testing('advanced-analytics')
                ->fake(true)
                ->for($premiumUser);

            // Assert
            expect(Toggl::for($premiumUser)->active('advanced-analytics'))->toBeTrue();
            expect(Toggl::for($freeUser)->active('advanced-analytics'))->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('conductor exposes metadata', function (): void {
            // Arrange & Act
            $conductor = Toggl::testing('premium')
                ->fake(true);

            // Assert
            expect($conductor->feature())->toBe('premium');
            expect($conductor->fakeValue())->toBeTrue();
            expect($conductor->fakeManyArray())->toBeNull();
        });

        test('batch conductor exposes metadata', function (): void {
            // Arrange & Act
            $conductor = Toggl::testing()
                ->fakeMany(['premium' => true, 'api' => 100]);

            // Assert
            expect($conductor->feature())->toBeNull();
            expect($conductor->fakeManyArray())->toBe(['premium' => true, 'api' => 100]);
        });

        test('faking same feature twice overwrites first fake', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Fake twice
            Toggl::testing('limit')
                ->fake(10)
                ->for($user);

            Toggl::testing('limit')
                ->fake(100)
                ->for($user);

            // Assert - Second fake wins
            expect(Toggl::for($user)->value('limit'))->toBe(100);
        });

        test('fake with null value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::testing('setting')
                ->fake(null)
                ->for($user);

            // Assert - Null is treated as inactive
            expect(Toggl::for($user)->active('setting'))->toBeFalse();
        });

        test('can fake same feature for multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act - Fake for both users separately
            Toggl::testing('premium')
                ->fake(true)
                ->for($user1);

            Toggl::testing('premium')
                ->fake(true)
                ->for($user2);

            // Assert
            expect(Toggl::for($user1)->active('premium'))->toBeTrue();
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
        });
    });
});
