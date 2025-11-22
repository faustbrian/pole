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
 * Permission-Style Conductor Test Suite
 *
 * Tests Warden-inspired permission API for granting/revoking feature access.
 * Validates patterns: Toggl::allow($user)->to('feature') and bulk operations.
 */
describe('Permission-Style Conductor', function (): void {
    describe('Basic Allow Operations', function (): void {
        test('allows single feature for single context', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::allow($user)->to('premium-dashboard');

            // Assert
            expect(Toggl::for($user)->active('premium-dashboard'))->toBeTrue();
        });

        test('allows multiple features for single context', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::allow($user)->to(['premium', 'analytics', 'api-access']);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('api-access'))->toBeTrue();
        });

        test('allows single feature for multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();

            // Act
            Toggl::allow([$user1, $user2, $user3])->to('beta-access');

            // Assert
            expect(Toggl::for($user1)->active('beta-access'))->toBeTrue();
            expect(Toggl::for($user2)->active('beta-access'))->toBeTrue();
            expect(Toggl::for($user3)->active('beta-access'))->toBeTrue();
        });

        test('allows multiple features for multiple contexts (Cartesian product)', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act - 3 features × 2 users = 6 operations
            Toggl::allow([$user1, $user2])->to(['f1', 'f2', 'f3']);

            // Assert - Every user gets every feature
            foreach ([$user1, $user2] as $user) {
                expect(Toggl::for($user)->active('f1'))->toBeTrue();
                expect(Toggl::for($user)->active('f2'))->toBeTrue();
                expect(Toggl::for($user)->active('f3'))->toBeTrue();
            }
        });
    });

    describe('Basic Deny Operations', function (): void {
        test('denies single feature for single context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('beta-features');

            // Act
            Toggl::deny($user)->to('beta-features');

            // Assert
            expect(Toggl::for($user)->active('beta-features'))->toBeFalse();
        });

        test('denies multiple features for single context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['f1', 'f2', 'f3']);

            // Act
            Toggl::deny($user)->to(['f1', 'f2', 'f3']);

            // Assert
            expect(Toggl::for($user)->active('f1'))->toBeFalse();
            expect(Toggl::for($user)->active('f2'))->toBeFalse();
            expect(Toggl::for($user)->active('f3'))->toBeFalse();
        });

        test('denies single feature for multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            foreach ([$user1, $user2] as $user) {
                Toggl::for($user)->activate('experimental');
            }

            // Act
            Toggl::deny([$user1, $user2])->to('experimental');

            // Assert
            expect(Toggl::for($user1)->active('experimental'))->toBeFalse();
            expect(Toggl::for($user2)->active('experimental'))->toBeFalse();
        });

        test('denies multiple features for multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            foreach ([$user1, $user2] as $user) {
                Toggl::for($user)->activate(['a', 'b']);
            }

            // Act
            Toggl::deny([$user1, $user2])->to(['a', 'b']);

            // Assert
            foreach ([$user1, $user2] as $user) {
                expect(Toggl::for($user)->active('a'))->toBeFalse();
                expect(Toggl::for($user)->active('b'))->toBeFalse();
            }
        });
    });

    describe('Group Operations', function (): void {
        test('allows group for single context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineGroup('premium', ['dashboard', 'analytics', 'export']);

            // Act
            Toggl::allow($user)->toGroup('premium');

            // Assert
            expect(Toggl::for($user)->active('dashboard'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeTrue();
        });

        test('denies group for single context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::defineGroup('beta', ['beta-ui', 'beta-api']);
            Toggl::for($user)->activateGroup('beta');

            // Act
            Toggl::deny($user)->toGroup('beta');

            // Assert
            expect(Toggl::for($user)->active('beta-ui'))->toBeFalse();
            expect(Toggl::for($user)->active('beta-api'))->toBeFalse();
        });

        test('allows group for multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            Toggl::defineGroup('enterprise', ['advanced-search', 'custom-reports']);

            // Act
            Toggl::allow([$user1, $user2])->toGroup('enterprise');

            // Assert
            foreach ([$user1, $user2] as $user) {
                expect(Toggl::for($user)->active('advanced-search'))->toBeTrue();
                expect(Toggl::for($user)->active('custom-reports'))->toBeTrue();
            }
        });

        test('denies group for multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            Toggl::defineGroup('trial', ['trial-feature-1', 'trial-feature-2']);

            foreach ([$user1, $user2] as $user) {
                Toggl::for($user)->activateGroup('trial');
            }

            // Act
            Toggl::deny([$user1, $user2])->toGroup('trial');

            // Assert
            foreach ([$user1, $user2] as $user) {
                expect(Toggl::for($user)->active('trial-feature-1'))->toBeFalse();
                expect(Toggl::for($user)->active('trial-feature-2'))->toBeFalse();
            }
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('grant premium access to new subscribers', function (): void {
            // Arrange - New premium subscribers
            $newSubscribers = [
                User::factory()->create(),
                User::factory()->create(),
                User::factory()->create(),
            ];

            // Act - Grant all premium features
            Toggl::allow($newSubscribers)->to([
                'premium-ui',
                'advanced-search',
                'export',
                'analytics',
                'priority-support',
            ]);

            // Assert
            foreach ($newSubscribers as $user) {
                expect(Toggl::for($user)->active('premium-ui'))->toBeTrue();
                expect(Toggl::for($user)->active('advanced-search'))->toBeTrue();
                expect(Toggl::for($user)->active('export'))->toBeTrue();
                expect(Toggl::for($user)->active('analytics'))->toBeTrue();
                expect(Toggl::for($user)->active('priority-support'))->toBeTrue();
            }
        });

        test('revoke trial access on expiration', function (): void {
            // Arrange - Expired trial users
            $expiredUsers = [
                User::factory()->create(),
                User::factory()->create(),
            ];

            // Activate trial features
            foreach ($expiredUsers as $user) {
                Toggl::for($user)->activate(['trial-1', 'trial-2', 'trial-3']);
            }

            // Act - Revoke all trial features
            Toggl::deny($expiredUsers)->to(['trial-1', 'trial-2', 'trial-3']);

            // Assert
            foreach ($expiredUsers as $user) {
                expect(Toggl::for($user)->active('trial-1'))->toBeFalse();
                expect(Toggl::for($user)->active('trial-2'))->toBeFalse();
                expect(Toggl::for($user)->active('trial-3'))->toBeFalse();
            }
        });

        test('rollout beta program to test cohort', function (): void {
            // Arrange
            $betaTesters = [
                User::factory()->create(),
                User::factory()->create(),
                User::factory()->create(),
                User::factory()->create(),
            ];

            Toggl::defineGroup('beta-program', [
                'beta-ui',
                'beta-api',
                'beta-workflow',
                'beta-integrations',
            ]);

            // Act
            Toggl::allow($betaTesters)->toGroup('beta-program');

            // Assert
            foreach ($betaTesters as $user) {
                expect(Toggl::for($user)->active('beta-ui'))->toBeTrue();
                expect(Toggl::for($user)->active('beta-api'))->toBeTrue();
                expect(Toggl::for($user)->active('beta-workflow'))->toBeTrue();
                expect(Toggl::for($user)->active('beta-integrations'))->toBeTrue();
            }
        });

        test('revoke compromised account access', function (): void {
            // Arrange - Compromised accounts
            $compromisedUser = User::factory()->create();
            Toggl::for($compromisedUser)->activate([
                'admin-panel',
                'user-management',
                'billing',
                'api-keys',
            ]);

            // Act - Immediately revoke all sensitive access
            Toggl::deny($compromisedUser)->to([
                'admin-panel',
                'user-management',
                'billing',
                'api-keys',
            ]);

            // Assert
            expect(Toggl::for($compromisedUser)->active('admin-panel'))->toBeFalse();
            expect(Toggl::for($compromisedUser)->active('user-management'))->toBeFalse();
            expect(Toggl::for($compromisedUser)->active('billing'))->toBeFalse();
            expect(Toggl::for($compromisedUser)->active('api-keys'))->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('allow is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Allow multiple times
            Toggl::allow($user)->to('feature');
            Toggl::allow($user)->to('feature');
            Toggl::allow($user)->to('feature');

            // Assert - Still just activated once
            expect(Toggl::for($user)->active('feature'))->toBeTrue();
        });

        test('deny on non-existent feature is no-op', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::deny($user)->to('never-activated');

            // Assert - No error, just inactive
            expect(Toggl::for($user)->active('never-activated'))->toBeFalse();
        });

        test('allow and deny are opposite operations', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act & Assert - Toggle back and forth
            Toggl::allow($user)->to('toggle');
            expect(Toggl::for($user)->active('toggle'))->toBeTrue();

            Toggl::deny($user)->to('toggle');
            expect(Toggl::for($user)->active('toggle'))->toBeFalse();

            Toggl::allow($user)->to('toggle');
            expect(Toggl::for($user)->active('toggle'))->toBeTrue();
        });

        test('Cartesian product calculation with allow', function (): void {
            // Arrange
            $users = [
                User::factory()->create(),
                User::factory()->create(),
                User::factory()->create(),
            ];
            $features = ['a', 'b', 'c', 'd'];

            // Act - 4 features × 3 users = 12 operations
            Toggl::allow($users)->to($features);

            // Assert - Count activations
            $activationCount = 0;

            foreach ($users as $user) {
                foreach ($features as $feature) {
                    if (Toggl::for($user)->active($feature)) {
                        ++$activationCount;
                    }
                }
            }

            expect($activationCount)->toBe(12);
        });
    });

    describe('Metadata', function (): void {
        test('contexts returns single context', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $conductor = Toggl::allow($user);

            // Assert
            expect($conductor->contexts())->toBe($user);
        });

        test('contexts returns array of contexts', function (): void {
            // Arrange
            $users = [
                User::factory()->create(),
                User::factory()->create(),
                User::factory()->create(),
            ];

            // Act
            $conductor = Toggl::allow($users);

            // Assert
            expect($conductor->contexts())->toBe($users);
        });

        test('isAllow returns true for allow operations', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $conductor = Toggl::allow($user);

            // Assert
            expect($conductor->isAllow())->toBeTrue();
        });

        test('isAllow returns false for deny operations', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $conductor = Toggl::deny($user);

            // Assert
            expect($conductor->isAllow())->toBeFalse();
        });
    });
});
