<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Http\Middleware\EnsureFeaturesAreInactive;
use Cline\Toggl\Toggl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\User;

/**
 * EnsureFeaturesAreInactive Middleware Test Suite
 *
 * Integration tests for middleware that ensures specified features are inactive
 * before allowing access to routes. Useful for legacy routes during feature
 * migrations. Tests real HTTP requests through Laravel's routing system.
 */
describe('EnsureFeaturesAreInactive Middleware', function (): void {
    beforeEach(function (): void {
        EnsureFeaturesAreInactive::whenActive(null);

        Route::middleware(EnsureFeaturesAreInactive::using('new-dashboard'))
            ->get('/test/legacy-dashboard', fn (): Response => new Response('Legacy Dashboard'));

        Route::middleware(EnsureFeaturesAreInactive::using('api-v2', 'api-v3'))
            ->get('/test/legacy-api', fn (): Response => new Response('Legacy API'));

        Route::middleware(EnsureFeaturesAreInactive::using('checkout-v2'))
            ->get('/test/legacy-checkout', fn (): Response => new Response('Legacy Checkout'));
    });

    describe('Happy Path', function (): void {
        test('allows request when single feature is inactive', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->deactivate('new-dashboard');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/legacy-dashboard')
                ->assertOk()
                ->assertSee('Legacy Dashboard');
        });

        test('allows request when all features are inactive', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->deactivate('api-v2');
            Toggl::for($user)->deactivate('api-v3');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/legacy-api')
                ->assertOk()
                ->assertSee('Legacy API');
        });

        test('allows request for unauthenticated user when features are undefined', function (): void {
            // Arrange - features not defined means inactive

            // Act & Assert
            $this->get('/test/legacy-dashboard')
                ->assertOk();
        });

        test('using() returns correct middleware string', function (): void {
            // Act
            $single = EnsureFeaturesAreInactive::using('new-api');
            $multiple = EnsureFeaturesAreInactive::using('api-v2', 'api-v3', 'api-v4');

            // Assert
            expect($single)->toBe(EnsureFeaturesAreInactive::class.':new-api');
            expect($multiple)->toBe(EnsureFeaturesAreInactive::class.':api-v2,api-v3,api-v4');
        });
    });

    describe('Sad Path', function (): void {
        test('blocks request when single feature is active', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('new-dashboard');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/legacy-dashboard')
                ->assertStatus(400);
        });

        test('blocks request when any feature is active', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->deactivate('api-v2');
            Toggl::for($user)->activate('api-v3');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/legacy-api')
                ->assertStatus(400);
        });

        test('blocks request when feature is globally active', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::activateForEveryone('new-dashboard');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/legacy-dashboard')
                ->assertStatus(400);
        });

        test('error message includes feature names in debug mode', function (): void {
            // Arrange
            config()->set('app.debug', true);
            $user = User::factory()->create();
            Toggl::for($user)->activate('api-v2');
            Toggl::for($user)->activate('api-v3');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/legacy-api')
                ->assertStatus(400)
                ->assertSee('api-v2')
                ->assertSee('api-v3')
                ->assertSee('must be inactive');
        });

        test('error message is empty in production mode', function (): void {
            // Arrange
            config()->set('app.debug', false);
            $user = User::factory()->create();
            Toggl::for($user)->activate('new-dashboard');

            // Act & Assert
            $response = $this->actingAs($user)->get('/test/legacy-dashboard');
            $response->assertStatus(400);

            expect($response->getContent())->not->toContain('new-dashboard');
        });
    });

    describe('Edge Cases', function (): void {
        test('custom response handler is called when feature is active', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('checkout-v2');

            EnsureFeaturesAreInactive::whenActive(fn ($request, $features): Response => new Response('Upgraded: '.implode(',', $features), Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN));

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/legacy-checkout')
                ->assertStatus(403)
                ->assertSee('Upgraded: checkout-v2');
        });

        test('custom response handler can redirect to new feature', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('new-dashboard');

            EnsureFeaturesAreInactive::whenActive(fn ($request, $features): RedirectResponse|Redirector => redirect('/dashboard'));

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/legacy-dashboard')
                ->assertRedirect('/dashboard');
        });

        test('different users can have different feature states', function (): void {
            // Arrange
            $upgradedUser = User::factory()->create();
            $legacyUser = User::factory()->create();

            Toggl::for($upgradedUser)->activate('new-dashboard');
            Toggl::for($legacyUser)->deactivate('new-dashboard');

            // Act & Assert - upgraded user blocked from legacy
            $this->actingAs($upgradedUser)
                ->get('/test/legacy-dashboard')
                ->assertStatus(400);

            // Legacy user can access legacy route
            $this->actingAs($legacyUser)
                ->get('/test/legacy-dashboard')
                ->assertOk();
        });

        test('works with per-user feature states', function (): void {
            // Arrange
            Route::middleware(EnsureFeaturesAreInactive::using('new-feature'))
                ->get('/test/legacy-conditional', fn (): Response => new Response('OK'));

            $betaUser = User::factory()->create();
            $regularUser = User::factory()->create();

            // Beta users have new feature active, regular users don't
            Toggl::for($betaUser)->activate('new-feature');
            Toggl::for($regularUser)->deactivate('new-feature');

            // Act & Assert - beta user blocked from legacy
            $this->actingAs($betaUser)
                ->get('/test/legacy-conditional')
                ->assertStatus(400);

            // Regular user can access legacy route
            $this->actingAs($regularUser)
                ->get('/test/legacy-conditional')
                ->assertOk();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('gradual migration: legacy route accessible during rollout', function (): void {
            // Arrange - simulate gradual rollout where most users don't have new feature yet
            $user = User::factory()->create();
            Toggl::for($user)->deactivate('checkout-v2');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/legacy-checkout')
                ->assertOk()
                ->assertSee('Legacy Checkout');
        });

        test('gradual migration: legacy route blocked after user upgrade', function (): void {
            // Arrange - user has been migrated to new checkout
            $user = User::factory()->create();
            Toggl::for($user)->activate('checkout-v2');

            EnsureFeaturesAreInactive::whenActive(fn (): RedirectResponse|Redirector => redirect('/checkout/new'));

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/legacy-checkout')
                ->assertRedirect('/checkout/new');
        });

        test('API versioning: v1 blocked when user has v2 and v3', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('api-v2');
            Toggl::for($user)->activate('api-v3');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/legacy-api')
                ->assertStatus(400);
        });
    });
});
