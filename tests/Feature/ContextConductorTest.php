<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Conductors\ContextConductor;
use Cline\Toggl\Toggl;
use Tests\Fixtures\FeatureFlag;
use Tests\Fixtures\User;

/**
 * Context Conductor Test Suite
 *
 * Tests the within() pattern: Toggl::within($team)->activate('feat-1')->activate('feat-2')
 * This provides a cleaner API when performing multiple operations on the same context,
 * avoiding repetition of Toggl::for($context) for each operation.
 */
describe('Context Conductor', function (): void {
    describe('Basic Activation', function (): void {
        test('can activate single feature within context context', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::within($user)->activate('premium');

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('can activate multiple features by chaining', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::within($user)
                ->activate('feat-1')
                ->activate('feat-2')
                ->activate('feat-3');

            // Assert
            expect(Toggl::for($user)->active('feat-1'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-2'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-3'))->toBeTrue();
        });

        test('can activate array of features', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::within($user)->activate(['feat-1', 'feat-2', 'feat-3']);

            // Assert
            expect(Toggl::for($user)->active('feat-1'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-2'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-3'))->toBeTrue();
        });
    });

    describe('Basic Deactivation', function (): void {
        test('can deactivate single feature within context context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('old-feature');

            // Act
            Toggl::within($user)->deactivate('old-feature');

            // Assert
            expect(Toggl::for($user)->active('old-feature'))->toBeFalse();
        });

        test('can deactivate multiple features by chaining', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['old-1', 'old-2', 'old-3']);

            // Act
            Toggl::within($user)
                ->deactivate('old-1')
                ->deactivate('old-2')
                ->deactivate('old-3');

            // Assert
            expect(Toggl::for($user)->active('old-1'))->toBeFalse();
            expect(Toggl::for($user)->active('old-2'))->toBeFalse();
            expect(Toggl::for($user)->active('old-3'))->toBeFalse();
        });

        test('can deactivate array of features', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['old-1', 'old-2', 'old-3']);

            // Act
            Toggl::within($user)->deactivate(['old-1', 'old-2', 'old-3']);

            // Assert
            expect(Toggl::for($user)->active('old-1'))->toBeFalse();
            expect(Toggl::for($user)->active('old-2'))->toBeFalse();
            expect(Toggl::for($user)->active('old-3'))->toBeFalse();
        });
    });

    describe('Mixed Operations', function (): void {
        test('can mix activate and deactivate operations', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['old-1', 'old-2']);

            // Act
            Toggl::within($user)
                ->activate('new-1')
                ->activate('new-2')
                ->deactivate('old-1')
                ->deactivate('old-2');

            // Assert
            expect(Toggl::for($user)->active('new-1'))->toBeTrue();
            expect(Toggl::for($user)->active('new-2'))->toBeTrue();
            expect(Toggl::for($user)->active('old-1'))->toBeFalse();
            expect(Toggl::for($user)->active('old-2'))->toBeFalse();
        });

        test('can perform complex workflow within context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['legacy-ui', 'beta-feature']);

            // Act - Migration scenario
            Toggl::within($user)
                ->deactivate('legacy-ui')
                ->activate('new-ui')
                ->activate('new-dashboard')
                ->deactivate('beta-feature')
                ->activate('stable-feature');

            // Assert
            expect(Toggl::for($user)->active('legacy-ui'))->toBeFalse();
            expect(Toggl::for($user)->active('new-ui'))->toBeTrue();
            expect(Toggl::for($user)->active('new-dashboard'))->toBeTrue();
            expect(Toggl::for($user)->active('beta-feature'))->toBeFalse();
            expect(Toggl::for($user)->active('stable-feature'))->toBeTrue();
        });
    });

    describe('Value Operations', function (): void {
        test('can activate with custom value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::within($user)->activateWithValue('theme', 'dark');

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
        });

        test('can mix regular activation and value activation', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::within($user)
                ->activate('premium')
                ->activateWithValue('theme', 'dark')
                ->activateWithValue('language', 'es')
                ->activate('analytics');

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
            expect(Toggl::for($user)->value('language'))->toBe('es');
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
        });

        test('can activate with array value', function (): void {
            // Arrange
            $user = User::factory()->create();
            $config = ['email' => true, 'sms' => false];

            // Act
            Toggl::within($user)->activateWithValue('notifications', $config);

            // Assert
            expect(Toggl::for($user)->value('notifications'))->toBe($config);
        });
    });

    describe('Group Operations', function (): void {
        test('can activate group within context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::groups()->define('premium', ['feat-1', 'feat-2', 'feat-3']);

            // Act
            Toggl::within($user)->activateGroup('premium');

            // Assert
            expect(Toggl::for($user)->active('feat-1'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-2'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-3'))->toBeTrue();
        });

        test('can deactivate group within context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::groups()->define('beta', ['beta-1', 'beta-2']);
            Toggl::for($user)->activateGroup('beta');

            // Act
            Toggl::within($user)->deactivateGroup('beta');

            // Assert
            expect(Toggl::for($user)->active('beta-1'))->toBeFalse();
            expect(Toggl::for($user)->active('beta-2'))->toBeFalse();
        });

        test('can mix individual features and groups', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::groups()->define('premium', ['premium-1', 'premium-2']);
            Toggl::groups()->define('beta', ['beta-1', 'beta-2']);

            // Act
            Toggl::within($user)
                ->activate('standalone-feature')
                ->activateGroup('premium')
                ->deactivateGroup('beta')
                ->activate('another-feature');

            // Assert
            expect(Toggl::for($user)->active('standalone-feature'))->toBeTrue();
            expect(Toggl::for($user)->active('premium-1'))->toBeTrue();
            expect(Toggl::for($user)->active('premium-2'))->toBeTrue();
            expect(Toggl::for($user)->active('beta-1'))->toBeFalse();
            expect(Toggl::for($user)->active('beta-2'))->toBeFalse();
            expect(Toggl::for($user)->active('another-feature'))->toBeTrue();
        });
    });

    describe('BackedEnum Support', function (): void {
        test('can activate enum features within context', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::within($user)
                ->activate(FeatureFlag::NewDashboard)
                ->activate(FeatureFlag::BetaFeatures);

            // Assert
            expect(Toggl::for($user)->active(FeatureFlag::NewDashboard))->toBeTrue();
            expect(Toggl::for($user)->active(FeatureFlag::BetaFeatures))->toBeTrue();
        });

        test('can deactivate enum features within context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate([
                FeatureFlag::NewDashboard,
                FeatureFlag::BetaFeatures,
            ]);

            // Act
            Toggl::within($user)
                ->deactivate(FeatureFlag::NewDashboard)
                ->deactivate(FeatureFlag::BetaFeatures);

            // Assert
            expect(Toggl::for($user)->active(FeatureFlag::NewDashboard))->toBeFalse();
            expect(Toggl::for($user)->active(FeatureFlag::BetaFeatures))->toBeFalse();
        });

        test('can activate enum with value within context', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::within($user)->activateWithValue(FeatureFlag::ApiV2, 'v3');

            // Assert
            expect(Toggl::for($user)->value(FeatureFlag::ApiV2))->toBe('v3');
        });
    });

    describe('Context Isolation', function (): void {
        test('operations only affect specified context', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act - Only modify user1
            Toggl::within($user1)
                ->activate('feat-1')
                ->activate('feat-2');

            // Assert
            expect(Toggl::for($user1)->active('feat-1'))->toBeTrue();
            expect(Toggl::for($user1)->active('feat-2'))->toBeTrue();
            expect(Toggl::for($user2)->active('feat-1'))->toBeFalse();
            expect(Toggl::for($user2)->active('feat-2'))->toBeFalse();
        });

        test('can use different contexts for different contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act
            Toggl::within($user1)
                ->activate('user1-feat-1')
                ->activate('user1-feat-2');

            Toggl::within($user2)
                ->activate('user2-feat-1')
                ->activate('user2-feat-2');

            // Assert
            expect(Toggl::for($user1)->active('user1-feat-1'))->toBeTrue();
            expect(Toggl::for($user1)->active('user1-feat-2'))->toBeTrue();
            expect(Toggl::for($user1)->active('user2-feat-1'))->toBeFalse();
            expect(Toggl::for($user1)->active('user2-feat-2'))->toBeFalse();

            expect(Toggl::for($user2)->active('user2-feat-1'))->toBeTrue();
            expect(Toggl::for($user2)->active('user2-feat-2'))->toBeTrue();
            expect(Toggl::for($user2)->active('user1-feat-1'))->toBeFalse();
            expect(Toggl::for($user2)->active('user1-feat-2'))->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('empty operations still return conductor', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $conductor = Toggl::within($user);

            // Assert - Conductor is created even with no operations
            expect($conductor)->toBeInstanceOf(ContextConductor::class);
        });

        test('operations are idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Activate same feature multiple times
            Toggl::within($user)
                ->activate('feature')
                ->activate('feature')
                ->activate('feature');

            // Assert - Feature is still just activated once
            expect(Toggl::for($user)->active('feature'))->toBeTrue();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('team onboarding scenario', function (): void {
            // Arrange
            $team = User::factory()->create(); // Representing a team

            // Act - Onboard team with all features
            Toggl::within($team)
                ->activate('team-dashboard')
                ->activate('team-analytics')
                ->activate('team-reporting')
                ->activate('team-collaboration')
                ->activateWithValue('team-plan', 'pro');

            // Assert
            expect(Toggl::for($team)->active('team-dashboard'))->toBeTrue();
            expect(Toggl::for($team)->active('team-analytics'))->toBeTrue();
            expect(Toggl::for($team)->active('team-reporting'))->toBeTrue();
            expect(Toggl::for($team)->active('team-collaboration'))->toBeTrue();
            expect(Toggl::for($team)->value('team-plan'))->toBe('pro');
        });

        test('user migration scenario', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['old-ui', 'legacy-feature', 'deprecated-tool']);

            // Act - Migrate user to new platform
            Toggl::within($user)
                ->deactivate('old-ui')
                ->deactivate('legacy-feature')
                ->deactivate('deprecated-tool')
                ->activate('new-ui')
                ->activate('modern-feature')
                ->activate('new-tool')
                ->activateWithValue('ui-version', 'v2');

            // Assert - Old features removed, new features added
            expect(Toggl::for($user)->active('old-ui'))->toBeFalse();
            expect(Toggl::for($user)->active('legacy-feature'))->toBeFalse();
            expect(Toggl::for($user)->active('deprecated-tool'))->toBeFalse();
            expect(Toggl::for($user)->active('new-ui'))->toBeTrue();
            expect(Toggl::for($user)->active('modern-feature'))->toBeTrue();
            expect(Toggl::for($user)->active('new-tool'))->toBeTrue();
            expect(Toggl::for($user)->value('ui-version'))->toBe('v2');
        });
    });
});
