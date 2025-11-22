<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;

/**
 * Context Integration Test Suite
 *
 * Tests global context context functionality that allows setting additional
 * context (like team, tenant, or account) beyond individual user contexts.
 * Covers context setting/clearing, context persistence across feature checks,
 * multi-tenancy scenarios, and real-world use cases like team-based and
 * account-level feature access control.
 */
describe('Context Integration', function (): void {
    describe('Happy Path', function (): void {
        test('can set global context and access it in strategy', function (): void {
            // Arrange
            Toggl::define('team-feature', fn ($context, $meta = null): bool => $meta === 'team-123');

            // Act
            Toggl::context()->to('team-123');
            $isActive = Toggl::for(TogglContext::simple(1, 'test'))->active('team-feature');

            // Assert
            expect($isActive)->toBeTrue();

            // Cleanup
            Toggl::context()->clear();
        });

        test('strategies receive null context when not set', function (): void {
            // Arrange
            Toggl::define('no-context-feature', fn ($context, $meta = null): bool => $meta === null);

            // Act
            Toggl::context()->clear();
            $isActive = Toggl::for(TogglContext::simple(1, 'test'))->active('no-context-feature');

            // Assert
            expect($isActive)->toBeTrue();
        });

        test('can use context to scope features to teams', function (): void {
            // Arrange
            $userContext = TogglContext::simple(1, 'user');
            Toggl::define('premium-api', fn (TogglContext $context, $meta = null): bool => $meta === 5);

            // Act
            Toggl::context()->to(5);
            $isActiveForTeam = Toggl::for($userContext)->active('premium-api');

            Toggl::context()->to(10);
            $isActiveForDifferentTeam = Toggl::for($userContext)->active('premium-api');

            // Assert
            expect($isActiveForTeam)->toBeTrue();
            expect($isActiveForDifferentTeam)->toBeFalse();

            // Cleanup
            Toggl::context()->clear();
        });

        test('context persists across multiple feature checks', function (): void {
            // Arrange
            Toggl::define('feature-a', fn ($context, $meta = null): bool => $meta === 'account-789');
            Toggl::define('feature-b', fn ($context, $meta = null): bool => $meta === 'account-789');

            // Act
            Toggl::context()->to('account-789');
            $featureAActive = Toggl::for(TogglContext::simple(1, 'test'))->active('feature-a');
            $featureBActive = Toggl::for(TogglContext::simple(1, 'test'))->active('feature-b');

            // Assert
            expect($featureAActive)->toBeTrue();
            expect($featureBActive)->toBeTrue();

            // Cleanup
            Toggl::context()->clear();
        });

        test('can clear context and features behave differently', function (): void {
            // Arrange
            Toggl::define('context-dependent', fn ($context, $meta = null): bool => $meta !== null);

            // Act
            Toggl::context()->to('some-context');
            $activeWithContext = Toggl::for(TogglContext::simple(1, 'test'))->active('context-dependent');

            Toggl::context()->clear();
            $activeWithoutContext = Toggl::for(TogglContext::simple(1, 'test'))->active('context-dependent');

            // Assert
            expect($activeWithContext)->toBeTrue();
            expect($activeWithoutContext)->toBeFalse();
        });

        test('closure-based features receive context', function (): void {
            // Arrange
            Toggl::define('closure-with-context', fn ($context, $meta = null): bool => $meta === 'tenant-42');

            // Act
            Toggl::context()->to('tenant-42');
            $isActive = Toggl::for(TogglContext::simple(1, 'test'))->active('closure-with-context');

            // Assert
            expect($isActive)->toBeTrue();

            // Cleanup
            Toggl::context()->clear();
        });
    });

    describe('Edge Cases', function (): void {
        test('context can be numeric', function (): void {
            // Arrange
            Toggl::define('numeric-context', fn ($context, $meta = null): bool => $meta === 123);

            // Act
            Toggl::context()->to(123);
            $isActive = Toggl::for(TogglContext::simple(1, 'test'))->active('numeric-context');

            // Assert
            expect($isActive)->toBeTrue();

            // Cleanup
            Toggl::context()->clear();
        });

        test('context can be an object', function (): void {
            // Arrange
            $team = (object) ['id' => 99, 'name' => 'Engineering'];
            Toggl::define('object-context', fn ($context, $meta = null): bool => $meta->id === 99);

            // Act
            Toggl::context()->to($team);
            $isActive = Toggl::for(TogglContext::simple(1, 'test'))->active('object-context');

            // Assert
            expect($isActive)->toBeTrue();

            // Cleanup
            Toggl::context()->clear();
        });

        test('multiple context() calls update context', function (): void {
            // Arrange
            Toggl::define('changing-context', fn ($context, $meta = null): bool => $meta === 'latest');

            // Act
            Toggl::context()->to('first');
            $firstCheck = Toggl::for(TogglContext::simple(1, 'test'))->active('changing-context');

            Toggl::context()->to('latest');
            $secondCheck = Toggl::for(TogglContext::simple(1, 'test'))->active('changing-context');

            // Assert
            expect($firstCheck)->toBeFalse();
            expect($secondCheck)->toBeTrue();

            // Cleanup
            Toggl::context()->clear();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('multi-tenancy: user can access feature within their team context', function (): void {
            // Arrange
            $teamAUser = TogglContext::simple(1, 'user');
            $teamBUser = TogglContext::simple(2, 'user');
            // Feature checks if global context matches 'team-a'
            Toggl::define('premium-dashboard', fn ($context, $meta = null): bool => $meta === 'team-a');

            // Act - Check for team-a context
            Toggl::context()->to('team-a');
            $teamAUserHasAccess = Toggl::for($teamAUser)->active('premium-dashboard');

            Toggl::context()->to('team-b');
            $teamBUserHasAccess = Toggl::for($teamBUser)->active('premium-dashboard');

            // Assert
            expect($teamAUserHasAccess)->toBeTrue();
            expect($teamBUserHasAccess)->toBeFalse();

            // Cleanup
            Toggl::context()->clear();
        });

        test('account-level features: feature active only for specific account context', function (): void {
            // Arrange
            Toggl::define('advanced-analytics', fn ($context, $meta = null): bool => $meta >= 100);

            // Act
            Toggl::context()->to(100);
            $hasAccess = Toggl::for(TogglContext::simple(1, 'test'))->active('advanced-analytics');

            Toggl::context()->to(50);
            $noAccess = Toggl::for(TogglContext::simple(1, 'test'))->active('advanced-analytics');

            // Assert
            expect($hasAccess)->toBeTrue();
            expect($noAccess)->toBeFalse();

            // Cleanup
            Toggl::context()->clear();
        });
    });
});
