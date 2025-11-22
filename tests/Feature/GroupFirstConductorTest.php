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
 * Group-First Conductor Test Suite
 *
 * Tests the group-first conductor pattern: Toggl::activateGroupConductor('premium')->for($user)
 * This pattern enables bulk activation/deactivation of all features in a group.
 */
describe('Group-First Conductor', function (): void {
    describe('Happy Path', function (): void {
        test('can activate a group for a single context using conductor', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineGroup('premium', ['dashboard', 'analytics', 'reports']);

            // Act
            Toggl::activateGroupConductor('premium')->for($user);

            // Assert
            expect(Toggl::for($user)->active('dashboard'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('reports'))->toBeTrue();
        });

        test('can activate a group for multiple contexts using conductor', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();
            Toggl::defineGroup('premium', ['dashboard', 'analytics']);

            // Act
            Toggl::activateGroupConductor('premium')->for([$user1, $user2, $user3]);

            // Assert
            expect(Toggl::for($user1)->active('dashboard'))->toBeTrue();
            expect(Toggl::for($user1)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user2)->active('dashboard'))->toBeTrue();
            expect(Toggl::for($user2)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user3)->active('dashboard'))->toBeTrue();
            expect(Toggl::for($user3)->active('analytics'))->toBeTrue();
        });

        test('can deactivate a group for a single context using conductor', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineGroup('beta', ['new-ui', 'experimental-api']);
            Toggl::for($user)->activateGroup('beta');

            // Act
            Toggl::deactivateGroupConductor('beta')->for($user);

            // Assert
            expect(Toggl::for($user)->active('new-ui'))->toBeFalse();
            expect(Toggl::for($user)->active('experimental-api'))->toBeFalse();
        });

        test('can deactivate a group for multiple contexts using conductor', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            Toggl::defineGroup('beta', ['new-ui', 'experimental-api']);
            Toggl::for($user1)->activateGroup('beta');
            Toggl::for($user2)->activateGroup('beta');

            // Act
            Toggl::deactivateGroupConductor('beta')->for([$user1, $user2]);

            // Assert
            expect(Toggl::for($user1)->active('new-ui'))->toBeFalse();
            expect(Toggl::for($user1)->active('experimental-api'))->toBeFalse();
            expect(Toggl::for($user2)->active('new-ui'))->toBeFalse();
            expect(Toggl::for($user2)->active('experimental-api'))->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('activating already active group is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineGroup('premium', ['dashboard', 'analytics']);
            Toggl::activateGroupConductor('premium')->for($user);

            // Act
            Toggl::activateGroupConductor('premium')->for($user);

            // Assert
            expect(Toggl::for($user)->active('dashboard'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
        });

        test('deactivating already inactive group is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineGroup('beta', ['new-ui']);

            // Act
            Toggl::deactivateGroupConductor('beta')->for($user);

            // Assert
            expect(Toggl::for($user)->active('new-ui'))->toBeFalse();
        });

        test('can activate group for empty array of contexts', function (): void {
            // Arrange
            Toggl::defineGroup('premium', ['dashboard']);

            // Act & Assert - should not throw
            Toggl::activateGroupConductor('premium')->for([]);
            expect(true)->toBeTrue();
        });

        test('can activate empty group without errors', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineGroup('empty-group', []);

            // Act & Assert - should not throw
            Toggl::activateGroupConductor('empty-group')->for($user);
            expect(true)->toBeTrue();
        });
    });

    describe('Integration with existing API', function (): void {
        test('conductor pattern works alongside traditional for()->activateGroup() pattern', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            Toggl::defineGroup('premium', ['dashboard', 'analytics']);

            // Act - mix both patterns
            Toggl::activateGroupConductor('premium')->for($user1); // New conductor pattern
            Toggl::for($user2)->activateGroup('premium');  // Traditional pattern

            // Assert - both work
            expect(Toggl::for($user1)->active('dashboard'))->toBeTrue();
            expect(Toggl::for($user1)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user2)->active('dashboard'))->toBeTrue();
            expect(Toggl::for($user2)->active('analytics'))->toBeTrue();
        });

        test('group activation activates all features in group', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineGroup('premium', ['feat-1', 'feat-2', 'feat-3', 'feat-4']);

            // Act
            Toggl::activateGroupConductor('premium')->for($user);

            // Assert - all features in group are active
            expect(Toggl::for($user)->activeInGroup('premium'))->toBeTrue();
        });
    });
});
