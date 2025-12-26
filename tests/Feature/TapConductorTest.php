<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Conductors\ActivationConductor;
use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * ActivationConductor Tap Integration Test Suite
 *
 * Tests the tap() method on ActivationConductor via Toggl::activate() pattern.
 * Pattern: Toggl::activate('premium')->tap(fn() => Log::info('msg'))->for($user)
 * This enables executing side effects without breaking the fluent chain.
 * Callbacks receive the ActivationConductor instance as parameter.
 *
 * Note: This tests ActivationConductor::tap(), not the standalone TapConductor class.
 * See tests/Unit/TapConductorTest.php for TapConductor unit tests.
 */
describe('ActivationConductor - Tap Integration', function (): void {
    describe('Basic Tapping', function (): void {
        test('can tap into activation chain', function (): void {
            // Arrange
            $user = User::factory()->create();
            $tapped = false;

            // Act
            Toggl::activate('premium')
                ->tap(function () use (&$tapped): void {
                    $tapped = true;
                })
                ->for($user);

            // Assert
            expect($tapped)->toBeTrue();
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('can tap multiple times in chain', function (): void {
            // Arrange
            $user = User::factory()->create();
            $tapCount = 0;

            // Act
            Toggl::activate('premium')
                ->tap(function () use (&$tapCount): void {
                    ++$tapCount;
                })
                ->tap(function () use (&$tapCount): void {
                    ++$tapCount;
                })
                ->tap(function () use (&$tapCount): void {
                    ++$tapCount;
                })
                ->for($user);

            // Assert
            expect($tapCount)->toBe(3);
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('tap executes before terminal for() method', function (): void {
            // Arrange
            $user = User::factory()->create();
            $executionOrder = [];

            // Act
            Toggl::activate('premium')
                ->tap(function () use (&$executionOrder): void {
                    $executionOrder[] = 'tap';
                })
                ->for($user);

            $executionOrder[] = 'after-for';

            // Assert
            expect($executionOrder)->toBe(['tap', 'after-for']);
        });

        test('tap does not prevent activation', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate('premium')
                ->tap(function (): void {
                    // Side effect that doesn't affect activation
                })
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });
    });

    describe('Conductor Access', function (): void {
        test('tap receives conductor instance', function (): void {
            // Arrange
            $user = User::factory()->create();
            $receivedConductor = null;

            // Act
            Toggl::activate('premium')
                ->tap(function ($conductor) use (&$receivedConductor): void {
                    $receivedConductor = $conductor;
                })
                ->for($user);

            // Assert
            expect($receivedConductor)->not->toBeNull();
            expect($receivedConductor)->toBeInstanceOf(ActivationConductor::class);
        });

        test('can access feature from conductor', function (): void {
            // Arrange
            $user = User::factory()->create();
            $feature = null;

            // Act
            Toggl::activate('premium')
                ->tap(function ($conductor) use (&$feature): void {
                    $feature = $conductor->features();
                })
                ->for($user);

            // Assert
            expect($feature)->toBe('premium');
        });

        test('can access value from conductor', function (): void {
            // Arrange
            $user = User::factory()->create();
            $value = null;

            // Act
            Toggl::activate('theme')
                ->withValue('dark')
                ->tap(function ($conductor) use (&$value): void {
                    $value = $conductor->value();
                })
                ->for($user);

            // Assert
            expect($value)->toBe('dark');
        });

        test('can access multiple features from conductor', function (): void {
            // Arrange
            $user = User::factory()->create();
            $features = null;

            // Act
            Toggl::activate(['premium', 'beta', 'analytics'])
                ->tap(function ($conductor) use (&$features): void {
                    $features = $conductor->features();
                })
                ->for($user);

            // Assert
            expect($features)->toBe(['premium', 'beta', 'analytics']);
        });
    });

    describe('With Value Chain', function (): void {
        test('can tap after withValue()', function (): void {
            // Arrange
            $user = User::factory()->create();
            $tappedValue = null;

            // Act
            Toggl::activate('theme')
                ->withValue('dark')
                ->tap(function ($conductor) use (&$tappedValue): void {
                    $tappedValue = $conductor->value();
                })
                ->for($user);

            // Assert
            expect($tappedValue)->toBe('dark');
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
        });

        test('can tap before withValue()', function (): void {
            // Arrange
            $user = User::factory()->create();
            $tappedValue = null;

            // Act
            Toggl::activate('theme')
                ->tap(function ($conductor) use (&$tappedValue): void {
                    $tappedValue = $conductor->value();
                })
                ->withValue('dark')
                ->for($user);

            // Assert
            expect($tappedValue)->toBe(true); // Default value before withValue
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
        });

        test('can tap between multiple withValue() calls', function (): void {
            // Arrange
            $user = User::factory()->create();
            $values = [];

            // Act
            Toggl::activate('setting')
                ->withValue('first')
                ->tap(function ($conductor) use (&$values): void {
                    $values[] = $conductor->value();
                })
                ->withValue('second')
                ->tap(function ($conductor) use (&$values): void {
                    $values[] = $conductor->value();
                })
                ->for($user);

            // Assert
            expect($values)->toBe(['first', 'second']);
            expect(Toggl::for($user)->value('setting'))->toBe('second');
        });
    });

    describe('Side Effects', function (): void {
        test('can log during activation', function (): void {
            // Arrange
            $user = User::factory()->create();
            $logs = [];

            // Act
            Toggl::activate('premium')
                ->tap(function ($conductor) use (&$logs): void {
                    $logs[] = 'Activating: '.$conductor->features();
                })
                ->for($user);

            // Assert
            expect($logs)->toBe(['Activating: premium']);
        });

        test('can collect metrics during activation', function (): void {
            // Arrange
            $user = User::factory()->create();
            $metrics = [];

            // Act
            Toggl::activate('premium')
                ->tap(function ($conductor) use (&$metrics, $user): void {
                    $metrics[] = [
                        'feature' => $conductor->features(),
                        'value' => $conductor->value(),
                        'user_id' => $user->id,
                        'timestamp' => now()->timestamp,
                    ];
                })
                ->for($user);

            // Assert
            expect($metrics)->toHaveCount(1);
            expect($metrics[0]['feature'])->toBe('premium');
            expect($metrics[0]['user_id'])->toBe($user->id);
        });

        test('can modify external state', function (): void {
            // Arrange
            $user = User::factory()->create();
            $cache = [];

            // Act
            Toggl::activate('premium')
                ->tap(function () use (&$cache): void {
                    $cache['features_invalidated'] = true;
                })
                ->for($user);

            // Assert
            expect($cache['features_invalidated'])->toBeTrue();
        });
    });

    describe('Multiple Contexts', function (): void {
        test('tap executes once for activation with multiple contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();
            $tapCount = 0;

            // Act
            Toggl::activate('premium')
                ->tap(function () use (&$tapCount): void {
                    ++$tapCount;
                })
                ->for([$user1, $user2, $user3]);

            // Assert - Tap executes once (before for(), not per context)
            expect($tapCount)->toBe(1);
            expect(Toggl::for($user1)->active('premium'))->toBeTrue();
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
            expect(Toggl::for($user3)->active('premium'))->toBeTrue();
        });
    });

    describe('Edge Cases', function (): void {
        test('tap with no-op callback works', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate('premium')
                ->tap(function (): void {
                    // No operation
                })
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('tap can be chained extensively', function (): void {
            // Arrange
            $user = User::factory()->create();
            $chain = [];

            // Act
            Toggl::activate('premium')
                ->tap(function () use (&$chain): void {
                    $chain[] = 1;
                })
                ->tap(function () use (&$chain): void {
                    $chain[] = 2;
                })
                ->tap(function () use (&$chain): void {
                    $chain[] = 3;
                })
                ->tap(function () use (&$chain): void {
                    $chain[] = 4;
                })
                ->tap(function () use (&$chain): void {
                    $chain[] = 5;
                })
                ->for($user);

            // Assert
            expect($chain)->toBe([1, 2, 3, 4, 5]);
        });

        test('tap works with complex values', function (): void {
            // Arrange
            $user = User::factory()->create();
            $tappedValue = null;

            // Act
            Toggl::activate('config')
                ->withValue(['theme' => 'dark', 'lang' => 'es', 'tz' => 'UTC'])
                ->tap(function ($conductor) use (&$tappedValue): void {
                    $tappedValue = $conductor->value();
                })
                ->for($user);

            // Assert
            expect($tappedValue)->toBe(['theme' => 'dark', 'lang' => 'es', 'tz' => 'UTC']);
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('audit trail scenario', function (): void {
            // Arrange
            $user = User::factory()->create();
            $auditLog = [];

            // Act
            Toggl::activate('premium')
                ->tap(function ($conductor) use (&$auditLog, $user): void {
                    $auditLog[] = [
                        'action' => 'feature_activation',
                        'feature' => $conductor->features(),
                        'user_id' => $user->id,
                        'timestamp' => now()->toDateTimeString(),
                    ];
                })
                ->for($user);

            // Assert
            expect($auditLog)->toHaveCount(1);
            expect($auditLog[0]['action'])->toBe('feature_activation');
            expect($auditLog[0]['feature'])->toBe('premium');
        });

        test('cache invalidation scenario', function (): void {
            // Arrange
            $user = User::factory()->create();
            $invalidatedKeys = [];

            // Act
            Toggl::activate('premium')
                ->tap(function () use (&$invalidatedKeys, $user): void {
                    $invalidatedKeys[] = sprintf('user:%s:features', $user->id);
                    $invalidatedKeys[] = sprintf('user:%s:permissions', $user->id);
                })
                ->for($user);

            // Assert
            expect($invalidatedKeys)->toHaveCount(2);
            expect($invalidatedKeys[0])->toBe(sprintf('user:%s:features', $user->id));
        });

        test('event dispatching scenario', function (): void {
            // Arrange
            $user = User::factory()->create();
            $events = [];

            // Act
            Toggl::activate('premium')
                ->tap(function ($conductor) use (&$events, $user): void {
                    $events[] = [
                        'name' => 'PremiumActivated',
                        'feature' => $conductor->features(),
                        'user' => $user,
                    ];
                })
                ->for($user);

            // Assert
            expect($events)->toHaveCount(1);
            expect($events[0]['name'])->toBe('PremiumActivated');
        });

        test('notification scenario', function (): void {
            // Arrange
            $user = User::factory()->create();
            $notifications = [];

            // Act
            Toggl::activate('beta-access')
                ->tap(function ($conductor) use (&$notifications, $user): void {
                    $notifications[] = [
                        'user' => $user,
                        'message' => 'You now have access to: '.$conductor->features(),
                    ];
                })
                ->for($user);

            // Assert
            expect($notifications)->toHaveCount(1);
            expect($notifications[0]['message'])->toBe('You now have access to: beta-access');
        });
    });
});
