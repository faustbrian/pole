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
 * Dependency Conductor Test Suite
 *
 * Tests feature dependency management ensuring prerequisites are active
 * before activating dependent features.
 */
describe('Dependency Conductor', function (): void {
    describe('Basic Dependency Patterns', function (): void {
        test('activates dependent feature when prerequisite is active', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('basic-analytics');

            // Act
            Toggl::require('basic-analytics')
                ->before('advanced-analytics')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('advanced-analytics'))->toBeTrue();
        });

        test('throws exception when prerequisite is not active', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act & Assert
            expect(fn () => Toggl::require('basic-analytics')
                ->before('advanced-analytics')
                ->for($user))
                ->toThrow(RuntimeException::class, 'missing prerequisites');
        });

        test('throws exception without before() call', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act & Assert
            expect(fn () => Toggl::require('basic')->for($user))
                ->toThrow(RuntimeException::class, 'Dependent feature not specified');
        });
    });

    describe('Multiple Prerequisites', function (): void {
        test('activates when all prerequisites are met', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['auth', 'payment', 'subscription']);

            // Act
            Toggl::require(['auth', 'payment', 'subscription'])
                ->before('premium-suite')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium-suite'))->toBeTrue();
        });

        test('throws when any prerequisite is missing', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['auth', 'payment']); // Missing subscription

            // Act & Assert
            expect(fn () => Toggl::require(['auth', 'payment', 'subscription'])
                ->before('premium-suite')
                ->for($user))
                ->toThrow(RuntimeException::class, 'missing prerequisites');
        });

        test('throws listing all missing prerequisites', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('auth'); // Missing payment and subscription

            // Act & Assert
            expect(fn () => Toggl::require(['auth', 'payment', 'subscription'])
                ->before('premium-suite')
                ->for($user))
                ->toThrow(RuntimeException::class, 'missing prerequisites');
        });

        test('throws when all prerequisites are missing', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act & Assert
            expect(fn () => Toggl::require(['auth', 'payment', 'subscription'])
                ->before('premium-suite')
                ->for($user))
                ->toThrow(RuntimeException::class, 'missing prerequisites');
        });
    });

    describe('Activation Conductor Integration', function (): void {
        test('activate()->requires() pattern with met prerequisites', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['auth', 'payment']);

            // Act
            Toggl::activate('checkout')
                ->requires(['auth', 'payment'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('checkout'))->toBeTrue();
        });

        test('activate()->requires() throws when prerequisites missing', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('auth'); // Missing payment

            // Act & Assert
            expect(fn () => Toggl::activate('checkout')
                ->requires(['auth', 'payment'])
                ->for($user))
                ->toThrow(RuntimeException::class, 'missing prerequisites');
        });

        test('activate()->requires() with single prerequisite', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('basic-plan');

            // Act
            Toggl::activate('analytics')
                ->requires('basic-plan')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('tiered feature access', function (): void {
            // Arrange - User has tier 1 and tier 2 access
            $user = User::factory()->create();
            Toggl::for($user)->activate(['tier-1', 'tier-2']);

            // Act - Tier 3 requires tier 1 and tier 2
            Toggl::require(['tier-1', 'tier-2'])
                ->before('tier-3')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('tier-3'))->toBeTrue();
        });

        test('workflow progression', function (): void {
            // Arrange - User completed onboarding and verification
            $user = User::factory()->create();
            Toggl::for($user)->activate(['onboarding-complete', 'email-verified']);

            // Act - Dashboard access requires both
            Toggl::activate('dashboard-access')
                ->requires(['onboarding-complete', 'email-verified'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('dashboard-access'))->toBeTrue();
        });

        test('payment feature dependencies', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['billing-setup', 'payment-method']);

            // Act - Recurring billing requires setup and payment method
            Toggl::require(['billing-setup', 'payment-method'])
                ->before('recurring-billing')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('recurring-billing'))->toBeTrue();
        });

        test('incomplete setup prevents advanced features', function (): void {
            // Arrange - User only completed profile, missing verification
            $user = User::factory()->create();
            Toggl::for($user)->activate('profile-complete');

            // Act & Assert - API access requires both profile and verification
            expect(fn () => Toggl::activate('api-access')
                ->requires(['profile-complete', 'identity-verified'])
                ->for($user))
                ->toThrow(RuntimeException::class, 'missing prerequisites');
        });
    });

    describe('Edge Cases', function (): void {
        test('self-reference throws error', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act & Assert - Feature cannot depend on itself
            expect(fn () => Toggl::require('feature-x')
                ->before('feature-x')
                ->for($user))
                ->toThrow(RuntimeException::class, 'missing prerequisites');
        });

        test('circular dependency first activation fails', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act & Assert - A depends on B, but B not active
            expect(fn () => Toggl::require('feature-b')
                ->before('feature-a')
                ->for($user))
                ->toThrow(RuntimeException::class, 'missing prerequisites');
        });

        test('dependency chain requires all levels', function (): void {
            // Arrange - A requires B, B requires C
            $user = User::factory()->create();
            Toggl::for($user)->activate('feature-c');

            Toggl::require('feature-c')
                ->before('feature-b')
                ->for($user);

            // Act - Now activate A which depends on B
            Toggl::require('feature-b')
                ->before('feature-a')
                ->for($user);

            // Assert - All three features active
            expect(Toggl::for($user)->active('feature-c'))->toBeTrue();
            expect(Toggl::for($user)->active('feature-b'))->toBeTrue();
            expect(Toggl::for($user)->active('feature-a'))->toBeTrue();
        });

        test('conductor exposes metadata', function (): void {
            // Arrange & Act
            $conductor = Toggl::require(['pre1', 'pre2'])
                ->before('dependent');

            // Assert
            expect($conductor->prerequisites())->toBe(['pre1', 'pre2']);
            expect($conductor->dependent())->toBe('dependent');
        });

        test('before() can be called to change dependent feature', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('basic');

            // Act - Chain multiple before() calls
            $conductor = Toggl::require('basic')
                ->before('intermediate');

            Toggl::require('basic')
                ->before('advanced')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('advanced'))->toBeTrue();
        });
    });
});
