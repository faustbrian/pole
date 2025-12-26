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
 * Observe Conductor Test Suite
 *
 * Tests feature observation and change detection with callbacks for
 * activation, deactivation, and value changes.
 */
describe('Observe Conductor', function (): void {
    describe('Basic Observation', function (): void {
        test('detects feature activation', function (): void {
            // Arrange
            $user = User::factory()->create();
            $activated = false;

            $observer = Toggl::observe('premium')
                ->onActivate(function () use (&$activated): void {
                    $activated = true;
                })
                ->for($user);

            // Act - Activate feature
            Toggl::for($user)->activate('premium');
            $observer->check();

            // Assert
            expect($activated)->toBeTrue();
        });

        test('detects feature deactivation', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');

            $deactivated = false;
            $observer = Toggl::observe('premium')
                ->onDeactivate(function () use (&$deactivated): void {
                    $deactivated = true;
                })
                ->for($user);

            // Act - Deactivate feature
            Toggl::for($user)->deactivate('premium');
            $observer->check();

            // Assert
            expect($deactivated)->toBeTrue();
        });

        test('detects any feature change', function (): void {
            // Arrange
            $user = User::factory()->create();
            $changed = false;

            $observer = Toggl::observe('theme')
                ->onChange(function () use (&$changed): void {
                    $changed = true;
                })
                ->for($user);

            // Act - Change feature value
            Toggl::for($user)->activate('theme', 'dark');
            $observer->check();

            // Assert
            expect($changed)->toBeTrue();
        });

        test('no callbacks when feature unchanged', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');

            $callbackCalled = false;
            $observer = Toggl::observe('premium')
                ->onChange(function () use (&$callbackCalled): void {
                    $callbackCalled = true;
                })
                ->for($user);

            // Act - Check without changes
            $observer->check();

            // Assert
            expect($callbackCalled)->toBeFalse();
        });
    });

    describe('Callback Parameters', function (): void {
        test('onChange receives feature, old value, new value, and state', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('theme', 'light');

            $params = null;
            $observer = Toggl::observe('theme')
                ->onChange(function ($feature, $oldValue, $newValue, $isActive) use (&$params): void {
                    $params = ['feature' => $feature, 'oldValue' => $oldValue, 'newValue' => $newValue, 'isActive' => $isActive];
                })
                ->for($user);

            // Act
            Toggl::for($user)->activate('theme', 'dark');
            $observer->check();

            // Assert
            expect($params['feature'])->toBe('theme');
            expect($params['oldValue'])->toBe('light');
            expect($params['newValue'])->toBe('dark');
            expect($params['isActive'])->toBeTrue();
        });

        test('onActivate receives feature and value', function (): void {
            // Arrange
            $user = User::factory()->create();
            $params = null;

            $observer = Toggl::observe('premium')
                ->onActivate(function ($feature, $value) use (&$params): void {
                    $params = ['feature' => $feature, 'value' => $value];
                })
                ->for($user);

            // Act
            Toggl::for($user)->activate('premium');
            $observer->check();

            // Assert
            expect($params['feature'])->toBe('premium');
            expect($params['value'])->toBeTrue();
        });

        test('onDeactivate receives feature and old value', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('theme', 'dark');

            $params = null;
            $observer = Toggl::observe('theme')
                ->onDeactivate(function ($feature, $oldValue) use (&$params): void {
                    $params = ['feature' => $feature, 'oldValue' => $oldValue];
                })
                ->for($user);

            // Act
            Toggl::for($user)->deactivate('theme');
            $observer->check();

            // Assert
            expect($params['feature'])->toBe('theme');
            expect($params['oldValue'])->toBe('dark');
        });
    });

    describe('Multiple Callbacks', function (): void {
        test('can chain onChange, onActivate, and onDeactivate', function (): void {
            // Arrange
            $user = User::factory()->create();
            $activateCount = 0;
            $deactivateCount = 0;

            $observer = Toggl::observe('premium')
                ->onActivate(function () use (&$activateCount): void {
                    ++$activateCount;
                })
                ->onDeactivate(function () use (&$deactivateCount): void {
                    ++$deactivateCount;
                })
                ->for($user);

            // Act - Activate
            Toggl::for($user)->activate('premium');
            $observer->check();

            // Act - Deactivate
            Toggl::for($user)->deactivate('premium');
            $observer->check();

            // Assert
            expect($activateCount)->toBe(1);
            expect($deactivateCount)->toBe(1);
        });

        test('onActivate takes precedence over onChange for activation', function (): void {
            // Arrange
            $user = User::factory()->create();
            $changeCount = 0;
            $activateCount = 0;

            $observer = Toggl::observe('premium')
                ->onChange(function () use (&$changeCount): void {
                    ++$changeCount;
                })
                ->onActivate(function () use (&$activateCount): void {
                    ++$activateCount;
                })
                ->for($user);

            // Act
            Toggl::for($user)->activate('premium');
            $observer->check();

            // Assert - Only onActivate should fire
            expect($activateCount)->toBe(1);
            expect($changeCount)->toBe(0);
        });

        test('onDeactivate takes precedence over onChange for deactivation', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');

            $changeCount = 0;
            $deactivateCount = 0;

            $observer = Toggl::observe('premium')
                ->onChange(function () use (&$changeCount): void {
                    ++$changeCount;
                })
                ->onDeactivate(function () use (&$deactivateCount): void {
                    ++$deactivateCount;
                })
                ->for($user);

            // Act
            Toggl::for($user)->deactivate('premium');
            $observer->check();

            // Assert - Only onDeactivate should fire
            expect($deactivateCount)->toBe(1);
            expect($changeCount)->toBe(0);
        });
    });

    describe('Observer State', function (): void {
        test('observer tracks current feature state', function (): void {
            // Arrange
            $user = User::factory()->create();
            $observer = Toggl::observe('premium')->for($user);

            // Act - Initially inactive
            expect($observer->isActive())->toBeFalse();

            // Act - Activate
            Toggl::for($user)->activate('premium');
            expect($observer->isActive())->toBeTrue();
        });

        test('observer tracks current feature value', function (): void {
            // Arrange
            $user = User::factory()->create();
            $observer = Toggl::observe('theme')->for($user);

            // Act - Set value
            Toggl::for($user)->activate('theme', 'dark');
            expect($observer->value())->toBe('dark');

            // Act - Change value
            Toggl::for($user)->activate('theme', 'light');
            expect($observer->value())->toBe('light');
        });

        test('observer state updates after check', function (): void {
            // Arrange
            $user = User::factory()->create();
            $callCount = 0;

            $observer = Toggl::observe('premium')
                ->onActivate(function () use (&$callCount): void {
                    ++$callCount;
                })
                ->for($user);

            // Act - Activate and check
            Toggl::for($user)->activate('premium');
            $observer->check();

            // Act - Check again without changes
            $observer->check();

            // Assert - Callback only fired once
            expect($callCount)->toBe(1);
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('log when premium feature is activated', function (): void {
            // Arrange
            $user = User::factory()->create();
            $log = [];

            $observer = Toggl::observe('premium')
                ->onActivate(function (string $feature) use (&$log): void {
                    $log[] = 'User activated '.$feature;
                })
                ->for($user);

            // Act
            Toggl::for($user)->activate('premium');
            $observer->check();

            // Assert
            expect($log)->toHaveCount(1);
            expect($log[0])->toBe('User activated premium');
        });

        test('send notification when theme changes', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('theme', 'light');

            $notifications = [];
            $observer = Toggl::observe('theme')
                ->onChange(function ($feature, string $old, $new) use (&$notifications): void {
                    $notifications[] = sprintf('Theme changed from %s to %s', $old, $new);
                })
                ->for($user);

            // Act
            Toggl::for($user)->activate('theme', 'dark');
            $observer->check();

            // Assert
            expect($notifications)->toHaveCount(1);
            expect($notifications[0])->toBe('Theme changed from light to dark');
        });

        test('track subscription downgrades', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');

            $downgrades = [];
            $observer = Toggl::observe('premium')
                ->onDeactivate(function () use (&$downgrades, $user): void {
                    $downgrades[] = $user->id;
                })
                ->for($user);

            // Act
            Toggl::for($user)->deactivate('premium');
            $observer->check();

            // Assert
            expect($downgrades)->toContain($user->id);
        });

        test('multiple feature observations', function (): void {
            // Arrange
            $user = User::factory()->create();
            $premiumCount = 0;
            $analyticsCount = 0;

            $premiumObserver = Toggl::observe('premium')
                ->onActivate(function () use (&$premiumCount): void {
                    ++$premiumCount;
                })
                ->for($user);

            $analyticsObserver = Toggl::observe('analytics')
                ->onActivate(function () use (&$analyticsCount): void {
                    ++$analyticsCount;
                })
                ->for($user);

            // Act
            Toggl::for($user)->activate(['premium', 'analytics']);
            $premiumObserver->check();
            $analyticsObserver->check();

            // Assert
            expect($premiumCount)->toBe(1);
            expect($analyticsCount)->toBe(1);
        });
    });

    describe('Edge Cases', function (): void {
        test('conductor exposes feature', function (): void {
            // Arrange & Act
            $conductor = Toggl::observe('test-feature');

            // Assert
            expect($conductor->feature())->toBe('test-feature');
        });

        test('conductor exposes callbacks', function (): void {
            // Arrange
            $onChangeCb = fn (): null => null;
            $onActivateCb = fn (): null => null;
            $onDeactivateCb = fn (): null => null;

            // Act
            $conductor = Toggl::observe('test')
                ->onChange($onChangeCb)
                ->onActivate($onActivateCb)
                ->onDeactivate($onDeactivateCb);

            // Assert
            expect($conductor->onChangeCallback())->toBe($onChangeCb);
            expect($conductor->onActivateCallback())->toBe($onActivateCb);
            expect($conductor->onDeactivateCallback())->toBe($onDeactivateCb);
        });

        test('method chaining creates new instances', function (): void {
            // Arrange
            $conductor1 = Toggl::observe('test');
            $conductor2 = $conductor1->onChange(fn (): null => null);
            $conductor3 = $conductor2->onActivate(fn (): null => null);

            // Assert
            expect($conductor1)->not->toBe($conductor2);
            expect($conductor2)->not->toBe($conductor3);
        });

        test('handles null callbacks gracefully', function (): void {
            // Arrange
            $user = User::factory()->create();
            $observer = Toggl::observe('premium')->for($user);

            // Act - Change feature without callbacks
            Toggl::for($user)->activate('premium');
            $result = $observer->check();

            // Assert - No exception thrown
            expect($result)->toBeNull();
        });
    });
});
