<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Conductors\ActivationConductor;
use Cline\Toggl\Conductors\DeactivationConductor;
use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * Conductor withScopes() Method Test Suite
 *
 * Tests the withScopes() method on ActivationConductor and DeactivationConductor
 * which enables scoped feature activation/deactivation via method chaining.
 */
describe('Conductor withScopes()', function (): void {
    beforeEach(function (): void {
        config(['toggl.default' => 'database']);
    });

    describe('ActivationConductor', function (): void {
        test('withScopes returns new ActivationConductor instance', function (): void {
            $conductor = Toggl::activate('feature');
            $result = $conductor->withScopes(['company_id' => 3]);

            expect($result)->toBeInstanceOf(ActivationConductor::class);
            expect($result)->not->toBe($conductor);
        });

        test('withScopes activates via for() using scope scope', function (): void {
            $user = User::factory()->create([
                'company_id' => 3,
                'org_id' => 2,
            ]);

            // Kind is automatically derived from $user ('user')
            Toggl::activate('scope-feature')
                ->withScopes([
                    'company_id' => 3,
                    'org_id' => 2,
                    'user_id' => null,
                ])
                ->for($user);

            // Verify feature is active via scope
            $isActive = Toggl::for($user)->withScopes()->active('scope-feature');
            expect($isActive)->toBeTrue();
        });

        test('withScopes preserves value from withValue', function (): void {
            $user = User::factory()->create(['company_id' => 5]);

            Toggl::activate('theme')
                ->withValue('dark')
                ->withScopes(['company_id' => 5, 'user_id' => null])
                ->for($user);

            $value = Toggl::for($user)->withScopes()->value('theme');
            expect($value)->toBe('dark');
        });

        test('withScopes with wildcards', function (): void {
            $user1 = User::factory()->create(['company_id' => 10, 'org_id' => 1]);
            $user2 = User::factory()->create(['company_id' => 10, 'org_id' => 2]);

            Toggl::activate('company-wide')
                ->withScopes([
                    'company_id' => 10,
                    'org_id' => null,  // Any org
                    'user_id' => null,  // Any user
                ])
                ->for($user1);

            // Both users should have access via scope
            expect(Toggl::for($user1)->withScopes()->active('company-wide'))->toBeTrue();
            expect(Toggl::for($user2)->withScopes()->active('company-wide'))->toBeTrue();
        });

        test('withScopes can chain with withValue in either order', function (): void {
            $user = User::factory()->create(['company_id' => 7]);

            // withValue first
            Toggl::activate('setting1')
                ->withValue('value1')
                ->withScopes(['company_id' => 7])
                ->for($user);

            // withScopes first
            Toggl::activate('setting2')
                ->withScopes(['company_id' => 7])
                ->for($user);

            expect(Toggl::for($user)->withScopes()->value('setting1'))->toBe('value1');
            expect(Toggl::for($user)->withScopes()->active('setting2'))->toBeTrue();
        });

        test('multiple features with withScopes', function (): void {
            $user = User::factory()->create(['company_id' => 8]);

            Toggl::activate(['feature-a', 'feature-b'])
                ->withScopes(['company_id' => 8, 'user_id' => null])
                ->for($user);

            expect(Toggl::for($user)->withScopes()->active('feature-a'))->toBeTrue();
            expect(Toggl::for($user)->withScopes()->active('feature-b'))->toBeTrue();
        });

        test('withScopes explicit kind override', function (): void {
            $user = User::factory()->create(['company_id' => 14]);

            // Explicitly override kind to 'team' (cross-context scenario)
            Toggl::activate('team-feature')
                ->withScopes(['company_id' => 14], 'team')
                ->for($user);

            // Feature is activated with kind='team', not 'user'
            // This is for cross-context scenarios where you want to activate
            // as a different context type
            expect(Toggl::for($user)->withScopes()->active('team-feature'))->toBeFalse();
        });
    });

    describe('DeactivationConductor', function (): void {
        test('withScopes returns new DeactivationConductor instance', function (): void {
            $conductor = Toggl::deactivate('feature');
            $result = $conductor->withScopes(['company_id' => 3]);

            expect($result)->toBeInstanceOf(DeactivationConductor::class);
            expect($result)->not->toBe($conductor);
        });

        test('withScopes deactivates via for() using scope scope', function (): void {
            $user = User::factory()->create(['company_id' => 9]);

            // First activate
            Toggl::activate('to-remove')
                ->withScopes(['company_id' => 9, 'user_id' => null])
                ->for($user);

            expect(Toggl::for($user)->withScopes()->active('to-remove'))->toBeTrue();

            // Now deactivate via withScopes
            Toggl::deactivate('to-remove')
                ->withScopes(['company_id' => 9, 'user_id' => null])
                ->for($user);

            expect(Toggl::for($user)->withScopes()->active('to-remove'))->toBeFalse();
        });

        test('multiple features deactivation with withScopes', function (): void {
            $user = User::factory()->create(['company_id' => 11]);

            // Activate multiple
            Toggl::activate(['remove-a', 'remove-b'])
                ->withScopes(['company_id' => 11, 'user_id' => null])
                ->for($user);

            expect(Toggl::for($user)->withScopes()->active('remove-a'))->toBeTrue();

            // Deactivate multiple
            Toggl::deactivate(['remove-a', 'remove-b'])
                ->withScopes(['company_id' => 11, 'user_id' => null])
                ->for($user);

            expect(Toggl::for($user)->withScopes()->active('remove-a'))->toBeFalse();
            expect(Toggl::for($user)->withScopes()->active('remove-b'))->toBeFalse();
        });
    });

    describe('Kind Auto-Derivation', function (): void {
        test('kind is derived from context TogglContext', function (): void {
            $user = User::factory()->create(['company_id' => 15]);

            // The kind should be automatically 'user' (from User model)
            Toggl::activate('auto-kind-feature')
                ->withScopes(['company_id' => 15, 'user_id' => null])
                ->for($user);

            // Feature should be found when checking with the same kind
            expect(Toggl::for($user)->withScopes()->active('auto-kind-feature'))->toBeTrue();
        });
    });
});
