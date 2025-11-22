<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Conductors\PipelineConductor;
use Cline\Toggl\FeatureManager;
use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * Pipeline Conductor Test Suite
 *
 * Tests chaining multiple feature operations in a single pipeline.
 */
describe('Pipeline Conductor', function (): void {
    describe('Basic Pipelining', function (): void {
        test('activates multiple features in sequence', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::pipeline()
                ->activate('premium')
                ->activate('analytics')
                ->activate('export')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeTrue();
        });

        test('deactivates multiple features in sequence', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['premium', 'analytics', 'export']);

            // Act
            Toggl::pipeline()
                ->deactivate('premium')
                ->deactivate('analytics')
                ->deactivate('export')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
            expect(Toggl::for($user)->active('analytics'))->toBeFalse();
            expect(Toggl::for($user)->active('export'))->toBeFalse();
        });

        test('activates array of features in one step', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::pipeline()
                ->activate(['premium', 'analytics', 'export'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeTrue();
        });

        test('deactivates array of features in one step', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['premium', 'analytics', 'export']);

            // Act
            Toggl::pipeline()
                ->deactivate(['premium', 'analytics', 'export'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
            expect(Toggl::for($user)->active('analytics'))->toBeFalse();
            expect(Toggl::for($user)->active('export'))->toBeFalse();
        });
    });

    describe('Mixed Operations', function (): void {
        test('mixes activate and deactivate operations', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['old-dashboard', 'beta-features']);

            // Act
            Toggl::pipeline()
                ->activate(['premium', 'analytics'])
                ->deactivate(['old-dashboard', 'beta-features'])
                ->activate('export')
                ->for($user);

            // Assert - Activated
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeTrue();

            // Assert - Deactivated
            expect(Toggl::for($user)->active('old-dashboard'))->toBeFalse();
            expect(Toggl::for($user)->active('beta-features'))->toBeFalse();
        });

        test('handles overlapping activate/deactivate', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Activate then deactivate same feature
            Toggl::pipeline()
                ->activate('premium')
                ->deactivate('premium')
                ->for($user);

            // Assert - Last operation wins
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
        });

        test('executes operations in order', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Deactivate then activate same feature
            Toggl::pipeline()
                ->deactivate('premium')
                ->activate('premium')
                ->for($user);

            // Assert - Last operation wins
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });
    });

    describe('Tap Operations', function (): void {
        test('executes tap callback', function (): void {
            // Arrange
            $user = User::factory()->create();
            $tapped = false;

            // Act
            Toggl::pipeline()
                ->activate('premium')
                ->tap(function () use (&$tapped): void {
                    $tapped = true;
                })
                ->activate('analytics')
                ->for($user);

            // Assert
            expect($tapped)->toBeTrue();
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
        });

        test('tap receives context parameter', function (): void {
            // Arrange
            $user = User::factory()->create();
            $receivedContext = null;

            // Act
            Toggl::pipeline()
                ->activate('premium')
                ->tap(function ($context) use (&$receivedContext): void {
                    $receivedContext = $context;
                })
                ->for($user);

            // Assert
            expect($receivedContext)->toBe($user);
        });

        test('multiple tap operations execute in order', function (): void {
            // Arrange
            $user = User::factory()->create();
            $order = [];

            // Act
            Toggl::pipeline()
                ->tap(function () use (&$order): void {
                    $order[] = 1;
                })
                ->activate('premium')
                ->tap(function () use (&$order): void {
                    $order[] = 2;
                })
                ->activate('analytics')
                ->tap(function () use (&$order): void {
                    $order[] = 3;
                })
                ->for($user);

            // Assert
            expect($order)->toBe([1, 2, 3]);
        });

        test('tap can access feature state', function (): void {
            // Arrange
            $user = User::factory()->create();
            $premiumActive = false;

            // Act
            Toggl::pipeline()
                ->activate('premium')
                ->tap(function ($context) use (&$premiumActive): void {
                    $premiumActive = Toggl::for($context)->active('premium');
                })
                ->for($user);

            // Assert
            expect($premiumActive)->toBeTrue();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('subscription upgrade pipeline', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['basic-dashboard', 'basic-support']);

            // Act - Upgrade from basic to premium
            Toggl::pipeline()
                ->deactivate(['basic-dashboard', 'basic-support'])
                ->activate(['premium-dashboard', 'analytics', 'export', 'priority-support'])
                ->for($user);

            // Assert - Old features removed
            expect(Toggl::for($user)->active('basic-dashboard'))->toBeFalse();
            expect(Toggl::for($user)->active('basic-support'))->toBeFalse();

            // Assert - New features added
            expect(Toggl::for($user)->active('premium-dashboard'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeTrue();
            expect(Toggl::for($user)->active('priority-support'))->toBeTrue();
        });

        test('feature migration with logging', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('old-api');
            $logged = [];

            // Act - Migrate from old to new API with logging
            Toggl::pipeline()
                ->tap(function () use (&$logged): void {
                    $logged[] = 'Starting migration';
                })
                ->deactivate('old-api')
                ->tap(function () use (&$logged): void {
                    $logged[] = 'Deactivated old API';
                })
                ->activate('new-api')
                ->tap(function () use (&$logged): void {
                    $logged[] = 'Activated new API';
                })
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('old-api'))->toBeFalse();
            expect(Toggl::for($user)->active('new-api'))->toBeTrue();
            expect($logged)->toBe([
                'Starting migration',
                'Deactivated old API',
                'Activated new API',
            ]);
        });

        test('rollout pipeline with staged activation', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Staged feature rollout
            Toggl::pipeline()
                ->activate('new-ui-phase-1')
                ->activate('new-ui-phase-2')
                ->activate('new-ui-phase-3')
                ->deactivate('old-ui')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('new-ui-phase-1'))->toBeTrue();
            expect(Toggl::for($user)->active('new-ui-phase-2'))->toBeTrue();
            expect(Toggl::for($user)->active('new-ui-phase-3'))->toBeTrue();
            expect(Toggl::for($user)->active('old-ui'))->toBeFalse();
        });

        test('beta program enrollment with cleanup', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['stable-features', 'old-beta']);

            // Act - Enroll in new beta, remove old beta
            Toggl::pipeline()
                ->deactivate('old-beta')
                ->activate(['new-beta-ui', 'new-beta-api', 'debug-mode'])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('stable-features'))->toBeTrue();
            expect(Toggl::for($user)->active('old-beta'))->toBeFalse();
            expect(Toggl::for($user)->active('new-beta-ui'))->toBeTrue();
            expect(Toggl::for($user)->active('new-beta-api'))->toBeTrue();
            expect(Toggl::for($user)->active('debug-mode'))->toBeTrue();
        });
    });

    describe('Edge Cases', function (): void {
        test('empty pipeline does nothing', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::pipeline()->for($user);

            // Assert - No features active
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
        });

        test('pipeline exposes operations', function (): void {
            // Arrange & Act
            $pipeline = Toggl::pipeline()
                ->activate('premium')
                ->deactivate('beta')
                ->tap(fn (): null => null);

            // Assert
            $operations = $pipeline->operations();
            expect($operations)->toHaveCount(3);
            expect($operations[0]['type'])->toBe('activate');
            expect($operations[1]['type'])->toBe('deactivate');
            expect($operations[2]['type'])->toBe('tap');
        });

        test('activating already active feature is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');

            // Act
            Toggl::pipeline()
                ->activate('premium')
                ->activate('premium')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('deactivating already inactive feature is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::pipeline()
                ->deactivate('premium')
                ->deactivate('premium')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
        });

        test('can chain many operations', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - 20 operations
            Toggl::pipeline()
                ->activate('f1')
                ->activate('f2')
                ->activate('f3')
                ->activate('f4')
                ->activate('f5')
                ->deactivate('f6')
                ->deactivate('f7')
                ->activate('f8')
                ->activate('f9')
                ->activate('f10')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('f1'))->toBeTrue();
            expect(Toggl::for($user)->active('f5'))->toBeTrue();
            expect(Toggl::for($user)->active('f6'))->toBeFalse();
            expect(Toggl::for($user)->active('f10'))->toBeTrue();
        });

        test('handles unknown operation type gracefully', function (): void {
            // Arrange
            $user = User::factory()->create();
            $manager = app(FeatureManager::class);

            // Create conductor with unknown operation type
            $conductor = new PipelineConductor(
                $manager,
                [
                    ['type' => 'activate', 'features' => ['premium']],
                    ['type' => 'unknown', 'data' => 'test'],
                    ['type' => 'activate', 'features' => ['analytics']],
                ],
            );

            // Act - Should not throw exception
            $conductor->for($user);

            // Assert - Known operations execute, unknown operation ignored
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
        });
    });
});
