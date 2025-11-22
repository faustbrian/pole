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
 * Cascade Conductor Test Suite
 *
 * Tests cascading feature activation/deactivation where enabling/disabling
 * a primary feature also affects all dependent features.
 */
describe('Cascade Conductor', function (): void {
    describe('Cascading Activation', function (): void {
        test('activates primary feature and all dependents', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Activate premium and all its dependent features
            Toggl::cascade('premium')
                ->activating(['analytics', 'export', 'api-access'])
                ->for($user);

            // Assert - All features active
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeTrue();
            expect(Toggl::for($user)->active('api-access'))->toBeTrue();
        });

        test('activates single dependent feature', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::cascade('basic-plan')
                ->activating(['dashboard-access'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('basic-plan'))->toBeTrue();
            expect(Toggl::for($user)->active('dashboard-access'))->toBeTrue();
        });

        test('activates multiple dependents in correct order', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Verify order: primary first, then dependents
            Toggl::cascade('enterprise')
                ->activating(['advanced-analytics', 'priority-support', 'custom-integrations'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('enterprise'))->toBeTrue();
            expect(Toggl::for($user)->active('advanced-analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('priority-support'))->toBeTrue();
            expect(Toggl::for($user)->active('custom-integrations'))->toBeTrue();
        });

        test('throws exception when no dependents specified', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act & Assert
            expect(fn () => Toggl::cascade('premium')->for($user))
                ->toThrow(RuntimeException::class, 'No dependent features specified');
        });
    });

    describe('Cascading Deactivation', function (): void {
        test('deactivates dependents before primary feature', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['premium', 'analytics', 'export', 'api-access']);

            // Act - Deactivate premium and cascade to dependents
            Toggl::cascade('premium')
                ->deactivating(['analytics', 'export', 'api-access'])
                ->for($user);

            // Assert - All features inactive
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
            expect(Toggl::for($user)->active('analytics'))->toBeFalse();
            expect(Toggl::for($user)->active('export'))->toBeFalse();
            expect(Toggl::for($user)->active('api-access'))->toBeFalse();
        });

        test('deactivates single dependent with primary', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['basic-plan', 'dashboard-access']);

            // Act
            Toggl::cascade('basic-plan')
                ->deactivating(['dashboard-access'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('basic-plan'))->toBeFalse();
            expect(Toggl::for($user)->active('dashboard-access'))->toBeFalse();
        });

        test('deactivates in correct order', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['enterprise', 'advanced-analytics', 'priority-support', 'custom-integrations']);

            // Act - Verify order: dependents first, then primary
            Toggl::cascade('enterprise')
                ->deactivating(['advanced-analytics', 'priority-support', 'custom-integrations'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('enterprise'))->toBeFalse();
            expect(Toggl::for($user)->active('advanced-analytics'))->toBeFalse();
            expect(Toggl::for($user)->active('priority-support'))->toBeFalse();
            expect(Toggl::for($user)->active('custom-integrations'))->toBeFalse();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('subscription upgrade cascades all features', function (): void {
            // Arrange - User on basic plan
            $user = User::factory()->create();
            Toggl::for($user)->activate(['basic-plan', 'basic-features']);

            // Act - Upgrade to premium activates all premium features
            Toggl::cascade('premium-plan')
                ->activating([
                    'premium-dashboard',
                    'advanced-reporting',
                    'export-data',
                    'api-access',
                    'priority-support',
                ])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium-plan'))->toBeTrue();
            expect(Toggl::for($user)->active('premium-dashboard'))->toBeTrue();
            expect(Toggl::for($user)->active('advanced-reporting'))->toBeTrue();
            expect(Toggl::for($user)->active('export-data'))->toBeTrue();
            expect(Toggl::for($user)->active('api-access'))->toBeTrue();
            expect(Toggl::for($user)->active('priority-support'))->toBeTrue();
        });

        test('subscription downgrade cascades deactivation', function (): void {
            // Arrange - User on premium plan
            $user = User::factory()->create();
            Toggl::for($user)->activate([
                'premium-plan',
                'premium-dashboard',
                'advanced-reporting',
                'export-data',
                'api-access',
                'priority-support',
            ]);

            // Act - Downgrade removes premium features
            Toggl::cascade('premium-plan')
                ->deactivating([
                    'premium-dashboard',
                    'advanced-reporting',
                    'export-data',
                    'api-access',
                    'priority-support',
                ])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium-plan'))->toBeFalse();
            expect(Toggl::for($user)->active('premium-dashboard'))->toBeFalse();
            expect(Toggl::for($user)->active('advanced-reporting'))->toBeFalse();
            expect(Toggl::for($user)->active('export-data'))->toBeFalse();
            expect(Toggl::for($user)->active('api-access'))->toBeFalse();
            expect(Toggl::for($user)->active('priority-support'))->toBeFalse();
        });

        test('module activation enables all sub-features', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Enable CRM module with all features
            Toggl::cascade('crm-module')
                ->activating(['contacts', 'deals', 'tasks', 'calendar', 'email-integration'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('crm-module'))->toBeTrue();
            expect(Toggl::for($user)->active('contacts'))->toBeTrue();
            expect(Toggl::for($user)->active('deals'))->toBeTrue();
            expect(Toggl::for($user)->active('tasks'))->toBeTrue();
            expect(Toggl::for($user)->active('calendar'))->toBeTrue();
            expect(Toggl::for($user)->active('email-integration'))->toBeTrue();
        });

        test('beta program enables experimental features', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Opt into beta program
            Toggl::cascade('beta-program')
                ->activating(['new-ui', 'ai-assistant', 'experimental-api', 'debug-mode'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('beta-program'))->toBeTrue();
            expect(Toggl::for($user)->active('new-ui'))->toBeTrue();
            expect(Toggl::for($user)->active('ai-assistant'))->toBeTrue();
            expect(Toggl::for($user)->active('experimental-api'))->toBeTrue();
            expect(Toggl::for($user)->active('debug-mode'))->toBeTrue();
        });
    });

    describe('Edge Cases', function (): void {
        test('cascade with already active features is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['premium', 'analytics']);

            // Act - Activate again
            Toggl::cascade('premium')
                ->activating(['analytics', 'export'])
                ->for($user);

            // Assert - All still active
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeTrue();
        });

        test('cascade with already inactive features is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');

            // Act - Deactivate features that are already inactive
            Toggl::cascade('premium')
                ->deactivating(['analytics', 'export'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
            expect(Toggl::for($user)->active('analytics'))->toBeFalse();
            expect(Toggl::for($user)->active('export'))->toBeFalse();
        });

        test('conductor exposes metadata', function (): void {
            // Arrange & Act
            $conductor = Toggl::cascade('premium')
                ->activating(['analytics', 'export']);

            // Assert
            expect($conductor->feature())->toBe('premium');
            expect($conductor->dependents())->toBe(['analytics', 'export']);
            expect($conductor->isActivating())->toBeTrue();
        });

        test('deactivating conductor exposes correct metadata', function (): void {
            // Arrange & Act
            $conductor = Toggl::cascade('premium')
                ->deactivating(['analytics', 'export']);

            // Assert
            expect($conductor->feature())->toBe('premium');
            expect($conductor->dependents())->toBe(['analytics', 'export']);
            expect($conductor->isActivating())->toBeFalse();
        });

        test('empty dependent array is accepted', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Empty array of dependents
            Toggl::cascade('standalone')
                ->activating([])
                ->for($user);

            // Assert - Only primary feature activated
            expect(Toggl::for($user)->active('standalone'))->toBeTrue();
        });

        test('cascade can be chained multiple times', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Multiple cascades
            Toggl::cascade('tier-1')
                ->activating(['feature-a', 'feature-b'])
                ->for($user);

            Toggl::cascade('tier-2')
                ->activating(['feature-c', 'feature-d'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('tier-1'))->toBeTrue();
            expect(Toggl::for($user)->active('feature-a'))->toBeTrue();
            expect(Toggl::for($user)->active('feature-b'))->toBeTrue();
            expect(Toggl::for($user)->active('tier-2'))->toBeTrue();
            expect(Toggl::for($user)->active('feature-c'))->toBeTrue();
            expect(Toggl::for($user)->active('feature-d'))->toBeTrue();
        });
    });
});
