<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Conductors\TapConductor;
use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * TapConductor Unit Test Suite
 *
 * Tests the standalone TapConductor class directly by instantiating it
 * and testing all its methods:
 * - tap() method for executing callbacks
 * - for() method for applying activations
 * - features() getter
 * - value() getter
 *
 * This ensures TapConductor gets proper code coverage.
 */
describe('TapConductor', function (): void {
    describe('Constructor', function (): void {
        test('can be instantiated with string feature', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();

            // Act
            $conductor = new TapConductor($manager, 'premium');

            // Assert
            expect($conductor)->toBeInstanceOf(TapConductor::class);
            expect($conductor->features())->toBe('premium');
            expect($conductor->value())->toBeTrue();
        });

        test('can be instantiated with array of features', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $features = ['premium', 'beta', 'analytics'];

            // Act
            $conductor = new TapConductor($manager, $features);

            // Assert
            expect($conductor->features())->toBe($features);
        });

        test('can be instantiated with custom value', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();

            // Act
            $conductor = new TapConductor($manager, 'theme', 'dark');

            // Assert
            expect($conductor->features())->toBe('theme');
            expect($conductor->value())->toBe('dark');
        });

        test('can be instantiated with complex value', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $value = ['theme' => 'dark', 'lang' => 'es'];

            // Act
            $conductor = new TapConductor($manager, 'settings', $value);

            // Assert
            expect($conductor->value())->toBe($value);
        });
    });

    describe('tap() method', function (): void {
        test('executes callback and returns self', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $conductor = new TapConductor($manager, 'premium');
            $executed = false;

            // Act
            $result = $conductor->tap(function () use (&$executed): void {
                $executed = true;
            });

            // Assert
            expect($executed)->toBeTrue();
            expect($result)->toBe($conductor);
        });

        test('callback receives conductor instance', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $conductor = new TapConductor($manager, 'premium');
            $receivedConductor = null;

            // Act
            $conductor->tap(function ($c) use (&$receivedConductor): void {
                $receivedConductor = $c;
            });

            // Assert
            expect($receivedConductor)->toBe($conductor);
        });

        test('can chain multiple tap calls', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $conductor = new TapConductor($manager, 'premium');
            $tapCount = 0;

            // Act
            $result = $conductor
                ->tap(function () use (&$tapCount): void {
                    ++$tapCount;
                })
                ->tap(function () use (&$tapCount): void {
                    ++$tapCount;
                })
                ->tap(function () use (&$tapCount): void {
                    ++$tapCount;
                });

            // Assert
            expect($tapCount)->toBe(3);
            expect($result)->toBe($conductor);
        });

        test('callback can access features', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $conductor = new TapConductor($manager, 'premium');
            $feature = null;

            // Act
            $conductor->tap(function ($c) use (&$feature): void {
                $feature = $c->features();
            });

            // Assert
            expect($feature)->toBe('premium');
        });

        test('callback can access value', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $conductor = new TapConductor($manager, 'theme', 'dark');
            $value = null;

            // Act
            $conductor->tap(function ($c) use (&$value): void {
                $value = $c->value();
            });

            // Assert
            expect($value)->toBe('dark');
        });
    });

    describe('for() method', function (): void {
        test('activates single feature for single context', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $user = User::factory()->create();
            $conductor = new TapConductor($manager, 'premium');

            // Act
            $conductor->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('activates feature with custom value', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $user = User::factory()->create();
            $conductor = new TapConductor($manager, 'theme', 'dark');

            // Act
            $conductor->for($user);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
        });

        test('activates multiple features for single context', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $user = User::factory()->create();
            $features = ['premium', 'beta', 'analytics'];
            $conductor = new TapConductor($manager, $features);

            // Act
            $conductor->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('beta'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
        });

        test('activates single feature for multiple contexts', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();
            $conductor = new TapConductor($manager, 'premium');

            // Act
            $conductor->for([$user1, $user2, $user3]);

            // Assert
            expect(Toggl::for($user1)->active('premium'))->toBeTrue();
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
            expect(Toggl::for($user3)->active('premium'))->toBeTrue();
        });

        test('activates multiple features for multiple contexts', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $features = ['premium', 'beta'];
            $conductor = new TapConductor($manager, $features);

            // Act
            $conductor->for([$user1, $user2]);

            // Assert
            expect(Toggl::for($user1)->active('premium'))->toBeTrue();
            expect(Toggl::for($user1)->active('beta'))->toBeTrue();
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
            expect(Toggl::for($user2)->active('beta'))->toBeTrue();
        });

        test('activates with complex value', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $user = User::factory()->create();
            $value = ['theme' => 'dark', 'lang' => 'es', 'tz' => 'UTC'];
            $conductor = new TapConductor($manager, 'settings', $value);

            // Act
            $conductor->for($user);

            // Assert
            expect(Toggl::for($user)->value('settings'))->toBe($value);
        });
    });

    describe('features() getter', function (): void {
        test('returns string feature', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $conductor = new TapConductor($manager, 'premium');

            // Act
            $features = $conductor->features();

            // Assert
            expect($features)->toBe('premium');
        });

        test('returns array of features', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $features = ['premium', 'beta', 'analytics'];
            $conductor = new TapConductor($manager, $features);

            // Act
            $result = $conductor->features();

            // Assert
            expect($result)->toBe($features);
        });
    });

    describe('value() getter', function (): void {
        test('returns default true value', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $conductor = new TapConductor($manager, 'premium');

            // Act
            $value = $conductor->value();

            // Assert
            expect($value)->toBeTrue();
        });

        test('returns custom scalar value', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $conductor = new TapConductor($manager, 'theme', 'dark');

            // Act
            $value = $conductor->value();

            // Assert
            expect($value)->toBe('dark');
        });

        test('returns custom array value', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $customValue = ['theme' => 'dark', 'lang' => 'es'];
            $conductor = new TapConductor($manager, 'settings', $customValue);

            // Act
            $value = $conductor->value();

            // Assert
            expect($value)->toBe($customValue);
        });

        test('returns custom numeric value', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $conductor = new TapConductor($manager, 'quota', 1_000);

            // Act
            $value = $conductor->value();

            // Assert
            expect($value)->toBe(1_000);
        });
    });

    describe('Full Chain Integration', function (): void {
        test('tap and for work together', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $user = User::factory()->create();
            $conductor = new TapConductor($manager, 'premium');
            $tapped = false;

            // Act
            $conductor
                ->tap(function () use (&$tapped): void {
                    $tapped = true;
                })
                ->for($user);

            // Assert
            expect($tapped)->toBeTrue();
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('multiple taps and for work together', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $user = User::factory()->create();
            $conductor = new TapConductor($manager, 'premium', 'gold');
            $logs = [];

            // Act
            $conductor
                ->tap(function ($c) use (&$logs): void {
                    $logs[] = 'Feature: '.$c->features();
                })
                ->tap(function ($c) use (&$logs): void {
                    $logs[] = 'Value: '.$c->value();
                })
                ->for($user);

            // Assert
            expect($logs)->toBe(['Feature: premium', 'Value: gold']);
            expect(Toggl::for($user)->value('premium'))->toBe('gold');
        });

        test('complex scenario with multiple features and contexts', function (): void {
            // Arrange
            $manager = Toggl::getFacadeRoot();
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $features = ['premium', 'beta'];
            $conductor = new TapConductor($manager, $features);
            $activations = [];

            // Act
            $conductor
                ->tap(function ($c) use (&$activations): void {
                    $activations[] = [
                        'features' => $c->features(),
                        'value' => $c->value(),
                    ];
                })
                ->for([$user1, $user2]);

            // Assert
            expect($activations)->toHaveCount(1);
            expect($activations[0]['features'])->toBe($features);
            expect(Toggl::for($user1)->active('premium'))->toBeTrue();
            expect(Toggl::for($user1)->active('beta'))->toBeTrue();
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
            expect(Toggl::for($user2)->active('beta'))->toBeTrue();
        });
    });
});
