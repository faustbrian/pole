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
 * Bulk Value Conductor Test Suite
 *
 * Tests the bulk() pattern: Toggl::bulk(['theme' => 'dark', 'lang' => 'es'])->for($user)
 * This enables setting multiple feature/value pairs in one operation,
 * different from batch() which does Cartesian products (features × contexts).
 */
describe('Bulk Value Conductor', function (): void {
    describe('Basic Operations', function (): void {
        test('can set multiple feature/value pairs for single context', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::bulk([
                'theme' => 'dark',
                'language' => 'es',
                'timezone' => 'UTC',
            ])->for($user);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
            expect(Toggl::for($user)->value('language'))->toBe('es');
            expect(Toggl::for($user)->value('timezone'))->toBe('UTC');
        });

        test('can set bulk values for multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();

            // Act
            Toggl::bulk([
                'theme' => 'dark',
                'language' => 'es',
            ])->for([$user1, $user2, $user3]);

            // Assert - All users have same values
            expect(Toggl::for($user1)->value('theme'))->toBe('dark');
            expect(Toggl::for($user1)->value('language'))->toBe('es');
            expect(Toggl::for($user2)->value('theme'))->toBe('dark');
            expect(Toggl::for($user2)->value('language'))->toBe('es');
            expect(Toggl::for($user3)->value('theme'))->toBe('dark');
            expect(Toggl::for($user3)->value('language'))->toBe('es');
        });

        test('can set empty bulk values', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::bulk([])->for($user);

            // Assert - No values set (undefined features return false)
            expect(Toggl::for($user)->active('any-feature'))->toBeFalse();
        });
    });

    describe('Value Types', function (): void {
        test('can set string values', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::bulk([
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'role' => 'admin',
            ])->for($user);

            // Assert
            expect(Toggl::for($user)->value('name'))->toBe('John Doe');
            expect(Toggl::for($user)->value('email'))->toBe('john@example.com');
            expect(Toggl::for($user)->value('role'))->toBe('admin');
        });

        test('can set numeric values', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::bulk([
                'rate-limit' => 1_000,
                'max-uploads' => 50,
                'api-version' => 2,
            ])->for($user);

            // Assert
            expect(Toggl::for($user)->value('rate-limit'))->toBe(1_000);
            expect(Toggl::for($user)->value('max-uploads'))->toBe(50);
            expect(Toggl::for($user)->value('api-version'))->toBe(2);
        });

        test('can set boolean values', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::bulk([
                'premium' => true,
                'beta-access' => false,
                'notifications-enabled' => true,
            ])->for($user);

            // Assert
            expect(Toggl::for($user)->value('premium'))->toBe(true);
            expect(Toggl::for($user)->value('beta-access'))->toBe(false);
            expect(Toggl::for($user)->value('notifications-enabled'))->toBe(true);
        });

        test('can set array values', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::bulk([
                'notifications' => ['email' => true, 'sms' => false, 'push' => true],
                'permissions' => ['read', 'write', 'delete'],
                'settings' => ['dark_mode' => true, 'compact_view' => false],
            ])->for($user);

            // Assert
            expect(Toggl::for($user)->value('notifications'))->toBe(['email' => true, 'sms' => false, 'push' => true]);
            expect(Toggl::for($user)->value('permissions'))->toBe(['read', 'write', 'delete']);
            expect(Toggl::for($user)->value('settings'))->toBe(['dark_mode' => true, 'compact_view' => false]);
        });

        test('can set null values', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::bulk([
                'optional-1' => null,
                'optional-2' => null,
            ])->for($user);

            // Assert
            expect(Toggl::for($user)->value('optional-1'))->toBeNull();
            expect(Toggl::for($user)->value('optional-2'))->toBeNull();
        });

        test('can set mixed value types', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::bulk([
                'string-val' => 'text',
                'int-val' => 42,
                'bool-val' => true,
                'array-val' => ['a', 'b', 'c'],
                'null-val' => null,
            ])->for($user);

            // Assert
            expect(Toggl::for($user)->value('string-val'))->toBe('text');
            expect(Toggl::for($user)->value('int-val'))->toBe(42);
            expect(Toggl::for($user)->value('bool-val'))->toBe(true);
            expect(Toggl::for($user)->value('array-val'))->toBe(['a', 'b', 'c']);
            expect(Toggl::for($user)->value('null-val'))->toBeNull();
        });
    });

    describe('Context Isolation', function (): void {
        test('bulk values only affect specified context', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act
            Toggl::bulk([
                'theme' => 'dark',
                'language' => 'es',
            ])->for($user1);

            // Assert
            expect(Toggl::for($user1)->value('theme'))->toBe('dark');
            expect(Toggl::for($user1)->value('language'))->toBe('es');
            expect(Toggl::for($user2)->active('theme'))->toBeFalse();
            expect(Toggl::for($user2)->active('language'))->toBeFalse();
        });

        test('different contexts can have different bulk values', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act
            Toggl::bulk(['theme' => 'dark', 'language' => 'es'])->for($user1);
            Toggl::bulk(['theme' => 'light', 'language' => 'en'])->for($user2);

            // Assert
            expect(Toggl::for($user1)->value('theme'))->toBe('dark');
            expect(Toggl::for($user1)->value('language'))->toBe('es');
            expect(Toggl::for($user2)->value('theme'))->toBe('light');
            expect(Toggl::for($user2)->value('language'))->toBe('en');
        });
    });

    describe('Overwriting Values', function (): void {
        test('bulk values overwrite existing values', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('theme', 'light');
            Toggl::for($user)->activate('language', 'en');

            // Act
            Toggl::bulk([
                'theme' => 'dark',
                'language' => 'es',
            ])->for($user);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
            expect(Toggl::for($user)->value('language'))->toBe('es');
        });

        test('subsequent bulk calls overwrite previous bulk values', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::bulk(['setting-1' => 'value-1', 'setting-2' => 'value-2'])->for($user);
            Toggl::bulk(['setting-1' => 'new-value-1', 'setting-2' => 'new-value-2'])->for($user);

            // Assert
            expect(Toggl::for($user)->value('setting-1'))->toBe('new-value-1');
            expect(Toggl::for($user)->value('setting-2'))->toBe('new-value-2');
        });
    });

    describe('Edge Cases', function (): void {
        test('bulk with single value works', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::bulk(['theme' => 'dark'])->for($user);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
        });

        test('bulk with many values works', function (): void {
            // Arrange
            $user = User::factory()->create();
            $manyValues = [];

            for ($i = 1; $i <= 50; ++$i) {
                $manyValues['feature-'.$i] = 'value-'.$i;
            }

            // Act
            Toggl::bulk($manyValues)->for($user);

            // Assert
            expect(Toggl::for($user)->value('feature-1'))->toBe('value-1');
            expect(Toggl::for($user)->value('feature-25'))->toBe('value-25');
            expect(Toggl::for($user)->value('feature-50'))->toBe('value-50');
        });

        test('bulk values can be checked with active()', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::bulk([
                'feature-true' => true,
                'feature-false' => false,
                'feature-string' => 'value',
            ])->for($user);

            // Assert - Truthy values are active
            expect(Toggl::for($user)->active('feature-true'))->toBeTrue();
            expect(Toggl::for($user)->active('feature-false'))->toBeFalse();
            expect(Toggl::for($user)->active('feature-string'))->toBeTrue();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('user preferences scenario', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Set all user preferences at once
            Toggl::bulk([
                'theme' => 'dark',
                'language' => 'es',
                'timezone' => 'America/New_York',
                'notifications' => [
                    'email' => true,
                    'sms' => false,
                    'push' => true,
                ],
                'privacy' => [
                    'profile_visible' => true,
                    'show_activity' => false,
                ],
            ])->for($user);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
            expect(Toggl::for($user)->value('language'))->toBe('es');
            expect(Toggl::for($user)->value('timezone'))->toBe('America/New_York');
            expect(Toggl::for($user)->value('notifications')['email'])->toBeTrue();
            expect(Toggl::for($user)->value('notifications')['sms'])->toBeFalse();
            expect(Toggl::for($user)->value('privacy')['profile_visible'])->toBeTrue();
        });

        test('team configuration scenario', function (): void {
            // Arrange
            $team = User::factory()->create(); // Representing a team

            // Act - Configure team settings
            Toggl::bulk([
                'plan' => 'enterprise',
                'max-members' => 100,
                'features-enabled' => ['analytics', 'reporting', 'api-access'],
                'billing-cycle' => 'annual',
                'support-tier' => 'priority',
            ])->for($team);

            // Assert
            expect(Toggl::for($team)->value('plan'))->toBe('enterprise');
            expect(Toggl::for($team)->value('max-members'))->toBe(100);
            expect(Toggl::for($team)->value('features-enabled'))->toBe(['analytics', 'reporting', 'api-access']);
            expect(Toggl::for($team)->value('billing-cycle'))->toBe('annual');
            expect(Toggl::for($team)->value('support-tier'))->toBe('priority');
        });

        test('onboarding scenario', function (): void {
            // Arrange
            $newUser = User::factory()->create();

            // Act - Set default preferences for new user
            Toggl::bulk([
                'theme' => 'light',
                'language' => 'en',
                'timezone' => 'UTC',
                'email-verified' => false,
                'onboarding-completed' => false,
                'tour-shown' => false,
                'default-view' => 'grid',
            ])->for($newUser);

            // Assert
            expect(Toggl::for($newUser)->value('theme'))->toBe('light');
            expect(Toggl::for($newUser)->value('language'))->toBe('en');
            expect(Toggl::for($newUser)->value('email-verified'))->toBe(false);
            expect(Toggl::for($newUser)->value('onboarding-completed'))->toBe(false);
            expect(Toggl::for($newUser)->value('tour-shown'))->toBe(false);
        });

        test('api configuration scenario', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Configure API access
            Toggl::bulk([
                'api-enabled' => true,
                'api-version' => 'v2',
                'rate-limit' => 1_000,
                'rate-window' => 3_600,
                'allowed-endpoints' => ['users', 'posts', 'comments'],
                'api-key-expires' => '2025-12-31',
            ])->for($user);

            // Assert
            expect(Toggl::for($user)->active('api-enabled'))->toBeTrue();
            expect(Toggl::for($user)->value('api-version'))->toBe('v2');
            expect(Toggl::for($user)->value('rate-limit'))->toBe(1_000);
            expect(Toggl::for($user)->value('allowed-endpoints'))->toBe(['users', 'posts', 'comments']);
        });
    });

    describe('Comparison with Individual Activation', function (): void {
        test('bulk is equivalent to individual activations', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act - Individual activations
            Toggl::for($user1)->activate('feat-1', 'val-1');
            Toggl::for($user1)->activate('feat-2', 'val-2');
            Toggl::for($user1)->activate('feat-3', 'val-3');

            // Act - Bulk activation
            Toggl::bulk([
                'feat-1' => 'val-1',
                'feat-2' => 'val-2',
                'feat-3' => 'val-3',
            ])->for($user2);

            // Assert - Both approaches produce same result
            expect(Toggl::for($user1)->value('feat-1'))->toBe(Toggl::for($user2)->value('feat-1'));
            expect(Toggl::for($user1)->value('feat-2'))->toBe(Toggl::for($user2)->value('feat-2'));
            expect(Toggl::for($user1)->value('feat-3'))->toBe(Toggl::for($user2)->value('feat-3'));
        });

        test('bulk is more concise for many values', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Bulk approach (single call)
            Toggl::bulk([
                'a' => 1,
                'b' => 2,
                'c' => 3,
                'd' => 4,
                'e' => 5,
            ])->for($user);

            // Assert - All values set
            expect(Toggl::for($user)->value('a'))->toBe(1);
            expect(Toggl::for($user)->value('b'))->toBe(2);
            expect(Toggl::for($user)->value('c'))->toBe(3);
            expect(Toggl::for($user)->value('d'))->toBe(4);
            expect(Toggl::for($user)->value('e'))->toBe(5);
        });
    });
});
