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
 * Reverse-Flow Conductor Test Suite
 *
 * Tests the feature-first conductor pattern: Toggl::activate()->for($user)
 * This pattern mirrors Warden's conductor style but uses `for()` since features
 * are enabled FOR users, not assigned TO them.
 */
describe('Reverse-Flow Conductor', function (): void {
    describe('Happy Path', function (): void {
        test('can activate a feature for a single context using conductor', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('premium');

            // Act
            Toggl::activate('premium')->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('can activate a feature for multiple contexts using conductor', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();
            Toggl::define('premium');

            // Act
            Toggl::activate('premium')->for([$user1, $user2, $user3]);

            // Assert
            expect(Toggl::for($user1)->active('premium'))->toBeTrue();
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
            expect(Toggl::for($user3)->active('premium'))->toBeTrue();
        });

        test('can activate multiple features for a context using conductor', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('feat-1');
            Toggl::define('feat-2');
            Toggl::define('feat-3');

            // Act
            Toggl::activate(['feat-1', 'feat-2', 'feat-3'])->for($user);

            // Assert
            expect(Toggl::for($user)->active('feat-1'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-2'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-3'))->toBeTrue();
        });

        test('can activate a feature with a value using conductor', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('theme');

            // Act
            Toggl::activate('theme')->withValue('dark')->for($user);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
        });

        test('can deactivate a feature for a single context using conductor', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('beta');
            Toggl::for($user)->activate('beta');

            // Act
            Toggl::deactivate('beta')->for($user);

            // Assert
            expect(Toggl::for($user)->active('beta'))->toBeFalse();
        });

        test('can deactivate a feature for multiple contexts using conductor', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            Toggl::define('beta');
            Toggl::for($user1)->activate('beta');
            Toggl::for($user2)->activate('beta');

            // Act
            Toggl::deactivate('beta')->for([$user1, $user2]);

            // Assert
            expect(Toggl::for($user1)->active('beta'))->toBeFalse();
            expect(Toggl::for($user2)->active('beta'))->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('activating already active feature is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('premium');
            Toggl::activate('premium')->for($user);

            // Act
            Toggl::activate('premium')->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('deactivating already inactive feature is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('beta');

            // Act
            Toggl::deactivate('beta')->for($user);

            // Assert
            expect(Toggl::for($user)->active('beta'))->toBeFalse();
        });

        test('can activate feature for empty array of contexts', function (): void {
            // Arrange
            Toggl::define('premium');

            // Act & Assert - should not throw
            Toggl::activate('premium')->for([]);
            expect(true)->toBeTrue();
        });
    });

    describe('Integration with existing API', function (): void {
        test('conductor pattern works alongside traditional for()->activate() pattern', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            Toggl::define('premium');

            // Act - mix both patterns
            Toggl::activate('premium')->for($user1); // New conductor pattern
            Toggl::for($user2)->activate('premium');  // Traditional pattern

            // Assert - both work
            expect(Toggl::for($user1)->active('premium'))->toBeTrue();
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
        });

        test('conductor pattern respects feature definitions', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('admin-only', fn ($user): bool => $user->email === 'admin@example.com');

            // Act
            Toggl::activate('admin-only')->for($user);

            // Assert - manual activation overrides resolver
            expect(Toggl::for($user)->active('admin-only'))->toBeTrue();
        });
    });
});
