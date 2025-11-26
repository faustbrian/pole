<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Http\Middleware\EnsureFeaturesAreActive;
use Cline\Toggl\Toggl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\User;

/**
 * EnsureFeaturesAreActive Middleware Test Suite
 *
 * Integration tests for middleware that ensures specified features are active
 * before allowing access to routes. Tests real HTTP requests through Laravel's
 * routing system.
 */
describe('EnsureFeaturesAreActive Middleware', function (): void {
    beforeEach(function (): void {
        EnsureFeaturesAreActive::whenInactive(null);

        Route::middleware(EnsureFeaturesAreActive::using('dashboard'))
            ->get('/test/single-feature', fn (): Response => new Response('OK'));

        Route::middleware(EnsureFeaturesAreActive::using('feature-1', 'feature-2', 'feature-3'))
            ->get('/test/multiple-features', fn (): Response => new Response('OK'));

        Route::middleware(EnsureFeaturesAreActive::using('premium'))
            ->get('/test/premium', fn (): Response => new Response('Premium Content'));

        Route::middleware(EnsureFeaturesAreActive::using('analytics', 'reporting'))
            ->get('/test/analytics', fn (): Response => new Response('Analytics'));
    });

    describe('Happy Path', function (): void {
        test('allows request when single feature is active for authenticated user', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('dashboard');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/single-feature')
                ->assertOk()
                ->assertSee('OK');
        });

        test('allows request when all features are active', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('feature-1');
            Toggl::for($user)->activate('feature-2');
            Toggl::for($user)->activate('feature-3');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/multiple-features')
                ->assertOk()
                ->assertSee('OK');
        });

        test('allows request when feature is globally active', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::activateForEveryone('dashboard');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/single-feature')
                ->assertOk();
        });

        test('using() returns correct middleware string', function (): void {
            // Act
            $single = EnsureFeaturesAreActive::using('premium');
            $multiple = EnsureFeaturesAreActive::using('premium', 'analytics', 'reporting');

            // Assert
            expect($single)->toBe(EnsureFeaturesAreActive::class.':premium');
            expect($multiple)->toBe(EnsureFeaturesAreActive::class.':premium,analytics,reporting');
        });
    });

    describe('Sad Path', function (): void {
        test('blocks request when single feature is inactive', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->deactivate('dashboard');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/single-feature')
                ->assertStatus(400);
        });

        test('blocks request when any feature is inactive', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('feature-1');
            Toggl::for($user)->deactivate('feature-2');
            Toggl::for($user)->activate('feature-3');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/multiple-features')
                ->assertStatus(400);
        });

        test('blocks request for unauthenticated user when feature requires context', function (): void {
            // Arrange - feature not globally active
            Toggl::define('dashboard', false);

            // Act & Assert
            $this->get('/test/single-feature')
                ->assertStatus(400);
        });

        test('error message includes feature names in debug mode', function (): void {
            // Arrange
            config()->set('app.debug', true);
            $user = User::factory()->create();
            Toggl::for($user)->deactivate('analytics');
            Toggl::for($user)->deactivate('reporting');

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/analytics')
                ->assertStatus(400)
                ->assertSee('analytics')
                ->assertSee('reporting');
        });

        test('error message is empty in production mode', function (): void {
            // Arrange
            config()->set('app.debug', false);
            $user = User::factory()->create();
            Toggl::for($user)->deactivate('dashboard');

            // Act & Assert
            $response = $this->actingAs($user)->get('/test/single-feature');
            $response->assertStatus(400);

            expect($response->getContent())->not->toContain('dashboard');
        });
    });

    describe('Edge Cases', function (): void {
        test('custom response handler is called when feature is inactive', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->deactivate('premium');

            EnsureFeaturesAreActive::whenInactive(fn ($request, $features): Response => new Response('Custom: '.implode(',', $features), Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN));

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/premium')
                ->assertStatus(403)
                ->assertSee('Custom: premium');
        });

        test('custom response handler can redirect', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->deactivate('premium');

            EnsureFeaturesAreActive::whenInactive(fn ($request, $features): Redirector|RedirectResponse => redirect('/upgrade'));

            // Act & Assert
            $this->actingAs($user)
                ->get('/test/premium')
                ->assertRedirect('/upgrade');
        });

        test('different users can have different feature states', function (): void {
            // Arrange
            $premiumUser = User::factory()->create();
            $freeUser = User::factory()->create();

            Toggl::for($premiumUser)->activate('premium');
            Toggl::for($freeUser)->deactivate('premium');

            // Act & Assert
            $this->actingAs($premiumUser)
                ->get('/test/premium')
                ->assertOk();

            $this->actingAs($freeUser)
                ->get('/test/premium')
                ->assertStatus(400);
        });

        test('works with closure-based feature definitions', function (): void {
            // Arrange
            Route::middleware(EnsureFeaturesAreActive::using('conditional'))
                ->get('/test/conditional', fn (): Response => new Response('OK'));

            $adminUser = User::factory()->create();
            $regularUser = User::factory()->create();

            // Activate for admin, not for regular user
            Toggl::for($adminUser)->activate('conditional');
            Toggl::for($regularUser)->deactivate('conditional');

            // Act & Assert
            $this->actingAs($adminUser)
                ->get('/test/conditional')
                ->assertOk();

            $this->actingAs($regularUser)
                ->get('/test/conditional')
                ->assertStatus(400);
        });
    });
});
