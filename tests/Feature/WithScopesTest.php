<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Contracts\AlwaysUseScope;
use Cline\Toggl\PendingContextualFeatureInteraction;
use Cline\Toggl\Support\FeatureScope;
use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * WithScope Method Test Suite
 *
 * Tests the withScopes() method on PendingContextualFeatureInteraction
 * which enables scoped feature resolution.
 */
describe('withScopes()', function (): void {
    beforeEach(function (): void {
        config(['toggl.default' => 'database']);
    });

    describe('Boolean Mode (Context Scope)', function (): void {
        test('withScopes() enables scope resolution', function (): void {
            $user = User::factory()->create([
                'company_id' => 3,
                'org_id' => 2,
            ]);

            // Check with scope enabled
            $interaction = Toggl::for($user)->withScopes();
            expect($interaction->usesScopes())->toBeTrue();
        });

        test('withScopes extracts scope from context', function (): void {
            $user = User::factory()->create([
                'company_id' => 5,
                'org_id' => 10,
                'team_id' => 15,
            ]);

            $interaction = Toggl::for($user)->withScopes();
            $scope = $interaction->getFeatureScope($user);

            expect($scope)->toBeInstanceOf(FeatureScope::class);
            expect($scope->constraints['company_id'])->toBe(5);
            expect($scope->constraints['org_id'])->toBe(10);
        });
    });

    describe('Explicit Scope Mode', function (): void {
        test('withScopes with array uses explicit scope', function (): void {
            $user = User::factory()->create([
                'company_id' => 99,  // Different from explicit scope
            ]);

            $interaction = Toggl::for($user)->withScopes([
                'company_id' => 3,
                'org_id' => 5,
            ], 'user');

            $scope = $interaction->getFeatureScope($user);

            expect($scope)->toBeInstanceOf(FeatureScope::class);
            expect($scope->constraints['company_id'])->toBe(3);  // Uses explicit, not context
            expect($scope->constraints['org_id'])->toBe(5);
            expect($scope->kind)->toBe('user');
        });

        test('explicit scope with null wildcards', function (): void {
            $user = User::factory()->create();

            $interaction = Toggl::for($user)->withScopes([
                'company_id' => 3,
                'team_id' => null,  // Wildcard
            ], 'user');

            $scope = $interaction->getFeatureScope($user);

            expect($scope->constraints['company_id'])->toBe(3);
            expect($scope->constraints['team_id'])->toBeNull();
        });

        test('explicit scope custom kind', function (): void {
            $user = User::factory()->create();

            $interaction = Toggl::for($user)->withScopes([
                'tenant_id' => 123,
            ], 'tenant');

            $scope = $interaction->getFeatureScope($user);

            expect($scope->kind)->toBe('tenant');
        });
    });

    describe('Default Behavior', function (): void {
        test('without withScopes usesScope is false', function (): void {
            $user = User::factory()->create();

            $interaction = Toggl::for($user);

            expect($interaction->usesScopes())->toBeFalse();
        });

        test('getFeatureScope without withScopes still extracts from context', function (): void {
            $user = User::factory()->create([
                'company_id' => 7,
            ]);

            $interaction = Toggl::for($user);
            $scope = $interaction->getFeatureScope($user);

            // Still extracts because context implements TogglContextable with scope
            expect($scope)->toBeInstanceOf(FeatureScope::class);
            expect($scope->constraints['company_id'])->toBe(7);
        });
    });

    describe('Method Chaining', function (): void {
        test('withScopes returns self for chaining', function (): void {
            $user = User::factory()->create();

            $result = Toggl::for($user)->withScopes();

            expect($result)->toBeInstanceOf(PendingContextualFeatureInteraction::class);
        });

        test('can chain withScopes with other methods', function (): void {
            $user = User::factory()->create([
                'company_id' => 3,
            ]);

            // This should not throw
            $isActive = Toggl::for($user)->withScopes()->active('nonexistent-feature');

            expect($isActive)->toBeFalse();
        });
    });

    describe('Configuration', function (): void {
        test('scope.enabled config option exists', function (): void {
            // Verify config key exists
            expect(config('toggl.scope.enabled'))->toBeFalse();
        });

        test('scope.enabled can be set to true', function (): void {
            config(['toggl.scope.enabled' => true]);

            expect(config('toggl.scope.enabled'))->toBeTrue();
        });
    });

    describe('AlwaysUseScope Interface', function (): void {
        test('interface exists and can be implemented', function (): void {
            expect(interface_exists(AlwaysUseScope::class))->toBeTrue();
        });

        test('User fixture does not implement AlwaysUseScope by default', function (): void {
            $user = User::factory()->create();

            expect($user)->not->toBeInstanceOf(AlwaysUseScope::class);
        });
    });
});
