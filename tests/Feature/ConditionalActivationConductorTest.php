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
 * Conditional Activation Conductor Test Suite
 *
 * Tests conditional feature activation where features are only enabled
 * if specified conditions are met. Covers onlyIf() for positive conditions,
 * unless() for negative conditions, and chaining multiple conditions.
 */
describe('Conditional Activation Conductor', function (): void {
    describe('onlyIf Conditions', function (): void {
        test('activates when onlyIf condition is true', function (): void {
            // Arrange
            $user = User::factory()->create();
            $user->role = 'admin';

            // Act
            Toggl::activate('admin-panel')
                ->onlyIf(fn ($u): bool => $u->role === 'admin')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('admin-panel'))->toBeTrue();
        });

        test('does not activate when onlyIf condition is false', function (): void {
            // Arrange
            $user = User::factory()->create();
            $user->role = 'user';

            // Act
            Toggl::activate('admin-panel')
                ->onlyIf(fn ($u): bool => $u->role === 'admin')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('admin-panel'))->toBeFalse();
        });

        test('can chain multiple onlyIf conditions (AND logic)', function (): void {
            // Arrange
            $adminUser = User::factory()->create();
            $adminUser->role = 'admin';
            $adminUser->verified = true;

            $unverifiedAdmin = User::factory()->create();
            $unverifiedAdmin->role = 'admin';
            $unverifiedAdmin->verified = false;

            // Act
            Toggl::activate('enterprise-suite')
                ->onlyIf(fn ($u): bool => $u->role === 'admin')
                ->onlyIf(fn ($u): bool => $u->verified === true)
                ->for($adminUser);

            Toggl::activate('enterprise-suite')
                ->onlyIf(fn ($u): bool => $u->role === 'admin')
                ->onlyIf(fn ($u): bool => $u->verified === true)
                ->for($unverifiedAdmin);

            // Assert - Only verified admin gets feature
            expect(Toggl::for($adminUser)->active('enterprise-suite'))->toBeTrue();
            expect(Toggl::for($unverifiedAdmin)->active('enterprise-suite'))->toBeFalse();
        });
    });

    describe('unless Conditions', function (): void {
        test('activates when unless condition is false', function (): void {
            // Arrange
            $freeUser = User::factory()->create();
            $freeUser->subscribed = false;

            // Act
            Toggl::activate('trial-banner')
                ->unless(fn ($u): bool => $u->subscribed === true)
                ->for($freeUser);

            // Assert
            expect(Toggl::for($freeUser)->active('trial-banner'))->toBeTrue();
        });

        test('does not activate when unless condition is true', function (): void {
            // Arrange
            $subscribedUser = User::factory()->create();
            $subscribedUser->subscribed = true;

            // Act
            Toggl::activate('trial-banner')
                ->unless(fn ($u): bool => $u->subscribed === true)
                ->for($subscribedUser);

            // Assert
            expect(Toggl::for($subscribedUser)->active('trial-banner'))->toBeFalse();
        });

        test('can chain multiple unless conditions (NOR logic)', function (): void {
            // Arrange
            $normalUser = User::factory()->create();
            $normalUser->banned = false;
            $normalUser->suspended = false;

            $bannedUser = User::factory()->create();
            $bannedUser->banned = true;
            $bannedUser->suspended = false;

            // Act
            Toggl::activate('messaging')
                ->unless(fn ($u): bool => $u->banned === true)
                ->unless(fn ($u): bool => $u->suspended === true)
                ->for($normalUser);

            Toggl::activate('messaging')
                ->unless(fn ($u): bool => $u->banned === true)
                ->unless(fn ($u): bool => $u->suspended === true)
                ->for($bannedUser);

            // Assert - Only non-banned, non-suspended user gets feature
            expect(Toggl::for($normalUser)->active('messaging'))->toBeTrue();
            expect(Toggl::for($bannedUser)->active('messaging'))->toBeFalse();
        });
    });

    describe('Mixed Conditions', function (): void {
        test('can combine onlyIf and unless conditions', function (): void {
            // Arrange
            $validUser = User::factory()->create();
            $validUser->role = 'admin';
            $validUser->banned = false;

            $bannedAdmin = User::factory()->create();
            $bannedAdmin->role = 'admin';
            $bannedAdmin->banned = true;

            // Act
            Toggl::activate('advanced-features')
                ->onlyIf(fn ($u): bool => $u->role === 'admin')
                ->unless(fn ($u): bool => $u->banned === true)
                ->for($validUser);

            Toggl::activate('advanced-features')
                ->onlyIf(fn ($u): bool => $u->role === 'admin')
                ->unless(fn ($u): bool => $u->banned === true)
                ->for($bannedAdmin);

            // Assert - Admin but not banned
            expect(Toggl::for($validUser)->active('advanced-features'))->toBeTrue();
            expect(Toggl::for($bannedAdmin)->active('advanced-features'))->toBeFalse();
        });

        test('multiple conditions all must pass', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user1->role = 'admin';
            $user1->verified = true;
            $user1->banned = false;

            $user2 = User::factory()->create();
            $user2->role = 'user';
            $user2->verified = true;
            $user2->banned = false;

            $user3 = User::factory()->create();
            $user3->role = 'admin';
            $user3->verified = false;
            $user3->banned = false;

            $user4 = User::factory()->create();
            $user4->role = 'admin';
            $user4->verified = true;
            $user4->banned = true;

            // Act - Feature requires: admin + verified + not banned
            $users = [$user1, $user2, $user3, $user4];

            foreach ($users as $user) {
                Toggl::activate('premium-feature')
                    ->onlyIf(fn ($u): bool => $u->role === 'admin')
                    ->onlyIf(fn ($u): bool => $u->verified === true)
                    ->unless(fn ($u): bool => $u->banned === true)
                    ->for($user);
            }

            // Assert - Only user1 meets all criteria
            expect(Toggl::for($user1)->active('premium-feature'))->toBeTrue();
            expect(Toggl::for($user2)->active('premium-feature'))->toBeFalse();
            expect(Toggl::for($user3)->active('premium-feature'))->toBeFalse();
            expect(Toggl::for($user4)->active('premium-feature'))->toBeFalse();
        });
    });

    describe('Value Integration', function (): void {
        test('conditional activation works with withValue', function (): void {
            // Arrange
            $adminUser = User::factory()->create();
            $adminUser->role = 'admin';

            $normalUser = User::factory()->create();
            $normalUser->role = 'user';

            // Act
            Toggl::activate('theme')
                ->withValue('dark-pro')
                ->onlyIf(fn ($u): bool => $u->role === 'admin')
                ->for($adminUser);

            Toggl::activate('theme')
                ->withValue('dark-pro')
                ->onlyIf(fn ($u): bool => $u->role === 'admin')
                ->for($normalUser);

            // Assert
            expect(Toggl::for($adminUser)->value('theme'))->toBe('dark-pro');
            expect(Toggl::for($normalUser)->value('theme'))->toBe(false);
        });

        test('conditions work without explicit withValue', function (): void {
            // Arrange
            $user = User::factory()->create();
            $user->verified = true;

            // Act - Uses default value (true)
            Toggl::activate('email-notifications')
                ->onlyIf(fn ($u): bool => $u->verified === true)
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('email-notifications'))->toBeTrue();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('subscription-based feature access', function (): void {
            // Arrange
            $freeUser = User::factory()->create();
            $freeUser->subscription = 'free';

            $proUser = User::factory()->create();
            $proUser->subscription = 'pro';

            // Act
            Toggl::activate('advanced-analytics')
                ->onlyIf(fn ($u): bool => in_array($u->subscription, ['pro', 'enterprise'], true))
                ->for($freeUser);

            Toggl::activate('advanced-analytics')
                ->onlyIf(fn ($u): bool => in_array($u->subscription, ['pro', 'enterprise'], true))
                ->for($proUser);

            // Assert
            expect(Toggl::for($freeUser)->active('advanced-analytics'))->toBeFalse();
            expect(Toggl::for($proUser)->active('advanced-analytics'))->toBeTrue();
        });

        test('short-circuits on first failed condition', function (): void {
            // Arrange
            $user = User::factory()->create();
            $user->role = 'user';

            $secondCalled = false;

            // Act
            Toggl::activate('feature')
                ->onlyIf(fn ($u): bool => $u->role === 'admin') // Fails
                ->onlyIf(function () use (&$secondCalled): true {
                    $secondCalled = true;

                    return true;
                })
                ->for($user);

            // Assert - Second condition should not execute
            expect($secondCalled)->toBeFalse();
            expect(Toggl::for($user)->active('feature'))->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('condition receives correct context', function (): void {
            // Arrange
            $user = User::factory()->create();
            $receivedContext = null;

            // Act
            Toggl::activate('test-feature')
                ->onlyIf(function ($context) use (&$receivedContext): true {
                    $receivedContext = $context;

                    return true;
                })
                ->for($user);

            // Assert
            expect($receivedContext)->toBe($user);
        });

        test('empty conditions always activate', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - No conditions, should activate
            Toggl::activate('feature')->for($user);

            // Assert
            expect(Toggl::for($user)->active('feature'))->toBeTrue();
        });
    });

    describe('Getter Methods', function (): void {
        test('feature returns configured feature name', function (): void {
            // Arrange & Act
            $conductor = Toggl::activate('premium')->onlyIf(fn (): true => true);

            // Assert
            expect($conductor->feature())->toBe('premium');
        });

        test('value returns configured value', function (): void {
            // Arrange & Act
            $conductor = Toggl::activate('theme')->withValue('dark')->onlyIf(fn (): true => true);

            // Assert
            expect($conductor->value())->toBe('dark');
        });

        test('value returns true when not explicitly set', function (): void {
            // Arrange & Act
            $conductor = Toggl::activate('premium')->onlyIf(fn (): true => true);

            // Assert
            expect($conductor->value())->toBe(true);
        });
    });
});
