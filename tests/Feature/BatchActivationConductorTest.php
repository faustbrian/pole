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
 * Batch Activation Conductor Test Suite
 *
 * Tests Cartesian product batch operations where multiple features are
 * activated/deactivated for multiple contexts in one call. Validates that
 * all features × all contexts are processed correctly.
 */
describe('Batch Activation Conductor', function (): void {
    describe('Basic Batch Activation', function (): void {
        test('activates single feature for multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();

            // Act
            Toggl::batch()
                ->activate('premium')
                ->for([$user1, $user2, $user3]);

            // Assert - All users get feature
            expect(Toggl::for($user1)->active('premium'))->toBeTrue();
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
            expect(Toggl::for($user3)->active('premium'))->toBeTrue();
        });

        test('activates multiple features for single context', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::batch()
                ->activate(['premium', 'analytics', 'api-access'])
                ->for($user);

            // Assert - User gets all features
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('api-access'))->toBeTrue();
        });

        test('activates multiple features for multiple contexts (Cartesian product)', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();

            // Act - 3 features × 3 users = 9 operations
            Toggl::batch()
                ->activate(['premium', 'analytics', 'api-access'])
                ->for([$user1, $user2, $user3]);

            // Assert - Every user gets every feature
            foreach ([$user1, $user2, $user3] as $user) {
                expect(Toggl::for($user)->active('premium'))->toBeTrue();
                expect(Toggl::for($user)->active('analytics'))->toBeTrue();
                expect(Toggl::for($user)->active('api-access'))->toBeTrue();
            }
        });
    });

    describe('Batch Activation with Values', function (): void {
        test('activates with custom value for multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act
            Toggl::batch()
                ->activate('theme', 'dark')
                ->for([$user1, $user2]);

            // Assert
            expect(Toggl::for($user1)->value('theme'))->toBe('dark');
            expect(Toggl::for($user2)->value('theme'))->toBe('dark');
        });

        test('activates multiple features with same value', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act
            Toggl::batch()
                ->activate(['tier', 'plan'], 'enterprise')
                ->for([$user1, $user2]);

            // Assert
            expect(Toggl::for($user1)->value('tier'))->toBe('enterprise');
            expect(Toggl::for($user1)->value('plan'))->toBe('enterprise');
            expect(Toggl::for($user2)->value('tier'))->toBe('enterprise');
            expect(Toggl::for($user2)->value('plan'))->toBe('enterprise');
        });
    });

    describe('Batch Deactivation', function (): void {
        test('deactivates single feature for multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();

            // Activate first
            foreach ([$user1, $user2, $user3] as $user) {
                Toggl::for($user)->activate('beta');
            }

            // Act
            Toggl::batch()
                ->deactivate('beta')
                ->for([$user1, $user2, $user3]);

            // Assert - All users lose feature
            expect(Toggl::for($user1)->active('beta'))->toBeFalse();
            expect(Toggl::for($user2)->active('beta'))->toBeFalse();
            expect(Toggl::for($user3)->active('beta'))->toBeFalse();
        });

        test('deactivates multiple features for multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Activate first
            foreach ([$user1, $user2] as $user) {
                Toggl::for($user)->activate(['f1', 'f2', 'f3']);
            }

            // Act - 3 features × 2 users = 6 operations
            Toggl::batch()
                ->deactivate(['f1', 'f2', 'f3'])
                ->for([$user1, $user2]);

            // Assert - All features deactivated for all users
            foreach ([$user1, $user2] as $user) {
                expect(Toggl::for($user)->active('f1'))->toBeFalse();
                expect(Toggl::for($user)->active('f2'))->toBeFalse();
                expect(Toggl::for($user)->active('f3'))->toBeFalse();
            }
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('mass activation for new user cohort', function (): void {
            // Arrange - New premium users
            $cohort = [
                User::factory()->create(),
                User::factory()->create(),
                User::factory()->create(),
            ];

            // Act - Grant premium features to entire cohort
            Toggl::batch()
                ->activate(['premium-ui', 'advanced-search', 'export', 'analytics'])
                ->for($cohort);

            // Assert - All users in cohort have all premium features
            foreach ($cohort as $user) {
                expect(Toggl::for($user)->active('premium-ui'))->toBeTrue();
                expect(Toggl::for($user)->active('advanced-search'))->toBeTrue();
                expect(Toggl::for($user)->active('export'))->toBeTrue();
                expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            }
        });

        test('bulk deactivation for trial expiration', function (): void {
            // Arrange - Users with expired trials
            $expiredUsers = [
                User::factory()->create(),
                User::factory()->create(),
            ];

            // Activate trial features
            foreach ($expiredUsers as $user) {
                Toggl::for($user)->activate(['trial-feature-1', 'trial-feature-2']);
            }

            // Act - Remove all trial features
            Toggl::batch()
                ->deactivate(['trial-feature-1', 'trial-feature-2'])
                ->for($expiredUsers);

            // Assert
            foreach ($expiredUsers as $user) {
                expect(Toggl::for($user)->active('trial-feature-1'))->toBeFalse();
                expect(Toggl::for($user)->active('trial-feature-2'))->toBeFalse();
            }
        });

        test('rollout beta features to test group', function (): void {
            // Arrange
            $betaTesters = [
                User::factory()->create(),
                User::factory()->create(),
                User::factory()->create(),
                User::factory()->create(),
            ];

            // Act
            Toggl::batch()
                ->activate(['beta-ui', 'beta-api', 'beta-workflow'])
                ->for($betaTesters);

            // Assert - 3 features × 4 users = 12 activations
            foreach ($betaTesters as $user) {
                expect(Toggl::for($user)->active('beta-ui'))->toBeTrue();
                expect(Toggl::for($user)->active('beta-api'))->toBeTrue();
                expect(Toggl::for($user)->active('beta-workflow'))->toBeTrue();
            }
        });
    });

    describe('Edge Cases', function (): void {
        test('handles single feature and single context', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::batch()
                ->activate('feature')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('feature'))->toBeTrue();
        });

        test('handles empty activation gracefully', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Empty feature array
            Toggl::batch()
                ->activate([])
                ->for($user);

            // Assert - No error, no activation
            expect(true)->toBeTrue();
        });

        test('batch operations are independent', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act - Separate batch operations
            Toggl::batch()
                ->activate('f1')
                ->for($user1);

            Toggl::batch()
                ->activate('f2')
                ->for($user2);

            // Assert - Each user only gets their feature
            expect(Toggl::for($user1)->active('f1'))->toBeTrue();
            expect(Toggl::for($user1)->active('f2'))->toBeFalse();
            expect(Toggl::for($user2)->active('f1'))->toBeFalse();
            expect(Toggl::for($user2)->active('f2'))->toBeTrue();
        });

        test('Cartesian product calculation is correct', function (): void {
            // Arrange
            $users = [
                User::factory()->create(),
                User::factory()->create(),
            ];
            $features = ['a', 'b', 'c'];

            // Act - 3 features × 2 users = 6 operations
            Toggl::batch()
                ->activate($features)
                ->for($users);

            // Assert - Count activations: 2 users × 3 features = 6
            $activationCount = 0;

            foreach ($users as $user) {
                foreach ($features as $feature) {
                    if (Toggl::for($user)->active($feature)) {
                        ++$activationCount;
                    }
                }
            }

            expect($activationCount)->toBe(6);
        });
    });

    describe('Getter Methods', function (): void {
        test('features returns configured features', function (): void {
            // Arrange & Act
            $conductor = Toggl::batch()->activate(['premium', 'analytics']);

            // Assert
            expect($conductor->features())->toBe(['premium', 'analytics']);
        });

        test('value returns configured value', function (): void {
            // Arrange & Act
            $conductor = Toggl::batch()->activate('theme', 'dark');

            // Assert
            expect($conductor->value())->toBe('dark');
        });

        test('value returns true when not explicitly set', function (): void {
            // Arrange & Act
            $conductor = Toggl::batch()->activate('premium');

            // Assert
            expect($conductor->value())->toBe(true);
        });

        test('operation returns activate for activation', function (): void {
            // Arrange & Act
            $conductor = Toggl::batch()->activate('premium');

            // Assert
            expect($conductor->operation())->toBe('activate');
        });

        test('operation returns deactivate for deactivation', function (): void {
            // Arrange & Act
            $conductor = Toggl::batch()->deactivate('premium');

            // Assert
            expect($conductor->operation())->toBe('deactivate');
        });
    });
});
