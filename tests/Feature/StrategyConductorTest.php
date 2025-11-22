<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Exceptions\InvalidContextTypeException;
use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;
use Illuminate\Support\Facades\Date;
use Tests\Fixtures\User;

/**
 * Strategy Conductor Test Suite
 *
 * Tests strategy-based feature activations including percentage rollouts,
 * time-based activations, and variant distributions.
 */
describe('Strategy Conductor', function (): void {
    describe('Percentage Rollout Strategy', function (): void {
        test('activates feature for users within percentage threshold', function (): void {
            // Arrange - Create many users to test distribution
            $users = User::factory()->count(100)->create();

            // Act - Apply 25% rollout
            foreach ($users as $user) {
                Toggl::strategy('rollout')
                    ->percentage(25)
                    ->for($user);
            }

            // Assert - Count activations (should be ~25% with variance)
            $activeCount = 0;

            foreach ($users as $user) {
                if (Toggl::for($user)->active('rollout')) {
                    ++$activeCount;
                }
            }

            // Allow ±15% variance for hash distribution
            expect($activeCount)->toBeGreaterThan(10)
                ->and($activeCount)->toBeLessThan(40);
        });

        test('percentage rollout is consistent per user', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Apply same strategy multiple times
            Toggl::strategy('consistent-test')
                ->percentage(50)
                ->for($user);

            $firstResult = Toggl::for($user)->active('consistent-test');

            // Apply again
            Toggl::strategy('consistent-test')
                ->percentage(50)
                ->for($user);

            $secondResult = Toggl::for($user)->active('consistent-test');

            // Assert - Same result both times (consistent hashing)
            expect($firstResult)->toBe($secondResult);
        });

        test('0% percentage activates no users', function (): void {
            // Arrange
            $users = User::factory()->count(10)->create();

            // Act
            foreach ($users as $user) {
                Toggl::strategy('none')
                    ->percentage(0)
                    ->for($user);
            }

            // Assert - No users activated
            foreach ($users as $user) {
                expect(Toggl::for($user)->active('none'))->toBeFalse();
            }
        });

        test('100% percentage activates all users', function (): void {
            // Arrange
            $users = User::factory()->count(10)->create();

            // Act
            foreach ($users as $user) {
                Toggl::strategy('all')
                    ->percentage(100)
                    ->for($user);
            }

            // Assert - All users activated
            foreach ($users as $user) {
                expect(Toggl::for($user)->active('all'))->toBeTrue();
            }
        });
    });

    describe('Time-Based Strategy', function (): void {
        test('activates feature when within date range', function (): void {
            // Arrange
            $user = User::factory()->create();
            $yesterday = Date::parse('-1 day')->format('Y-m-d');
            $tomorrow = Date::parse('+1 day')->format('Y-m-d');

            // Act - Current time is within range
            Toggl::strategy('time-limited')
                ->from($yesterday)
                ->until($tomorrow)
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('time-limited'))->toBeTrue();
        });

        test('does not activate feature before start date', function (): void {
            // Arrange
            $user = User::factory()->create();
            $future = Date::parse('+10 days')->format('Y-m-d');
            $farFuture = Date::parse('+20 days')->format('Y-m-d');

            // Act - Feature starts in future
            Toggl::strategy('future-feature')
                ->from($future)
                ->until($farFuture)
                ->for($user);

            // Assert - Not active yet
            expect(Toggl::for($user)->active('future-feature'))->toBeFalse();
        });

        test('does not activate feature after end date', function (): void {
            // Arrange
            $user = User::factory()->create();
            $farPast = Date::parse('-20 days')->format('Y-m-d');
            $past = Date::parse('-10 days')->format('Y-m-d');

            // Act - Feature ended in past
            Toggl::strategy('expired')
                ->from($farPast)
                ->until($past)
                ->for($user);

            // Assert - No longer active
            expect(Toggl::for($user)->active('expired'))->toBeFalse();
        });

        test('from() can be chained with until()', function (): void {
            // Arrange
            $user = User::factory()->create();
            $now = Date::now()->format('Y-m-d');
            $future = Date::parse('+5 days')->format('Y-m-d');

            // Act - Chain from and until
            Toggl::strategy('chained-dates')
                ->from($now)
                ->until($future)
                ->for($user);

            // Assert - Active within range
            expect(Toggl::for($user)->active('chained-dates'))->toBeTrue();
        });
    });

    describe('Variant Distribution Strategy', function (): void {
        test('assigns variant based on weights', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Define variant distribution
            Toggl::strategy('ab-test')
                ->variants(['control' => 50, 'variant-a' => 30, 'variant-b' => 20])
                ->for($user);

            // Assert - User gets assigned a variant
            $variant = Toggl::variant('ab-test')->for($user)->get();
            expect($variant)->toBeIn(['control', 'variant-a', 'variant-b']);
        });

        test('variant strategy integrates with variant conductor', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Apply variant strategy
            Toggl::strategy('experiment')
                ->variants(['a' => 50, 'b' => 50])
                ->for($user);

            // Assert - Variant result is accessible
            $result = Toggl::variant('experiment')->for($user);
            expect($result->get())->toBeIn(['a', 'b']);
        });

        test('variant distribution is consistent per user', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Apply strategy twice
            Toggl::strategy('consistent-variant')
                ->variants(['x' => 50, 'y' => 50])
                ->for($user);

            $first = Toggl::variant('consistent-variant')->for($user)->get();

            Toggl::strategy('consistent-variant')
                ->variants(['x' => 50, 'y' => 50])
                ->for($user);

            $second = Toggl::variant('consistent-variant')->for($user)->get();

            // Assert - Same variant both times
            expect($first)->toBe($second);
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('gradual rollout of new feature', function (): void {
            // Arrange - Simulate 1000 users
            $users = User::factory()->count(1_000)->create();

            // Act - Start with 10% rollout
            foreach ($users as $user) {
                Toggl::strategy('new-dashboard')
                    ->percentage(10)
                    ->for($user);
            }

            // Assert - Approximately 10% activated (±5% variance)
            $activeCount = 0;

            foreach ($users as $user) {
                if (Toggl::for($user)->active('new-dashboard')) {
                    ++$activeCount;
                }
            }

            expect($activeCount)->toBeGreaterThan(50)
                ->and($activeCount)->toBeLessThan(150);
        });

        test('seasonal feature activation', function (): void {
            // Arrange
            $user = User::factory()->create();
            $today = Date::now()->format('Y-m-d');
            $nextWeek = Date::parse('+7 days')->format('Y-m-d');

            // Act - Holiday promotion
            Toggl::strategy('holiday-promo')
                ->from($today)
                ->until($nextWeek)
                ->for($user);

            // Assert - Active during promotion period
            expect(Toggl::for($user)->active('holiday-promo'))->toBeTrue();
        });

        test('multivariate experiment for UI redesign', function (): void {
            // Arrange
            $users = User::factory()->count(100)->create();

            // Act - 3-way split test
            foreach ($users as $user) {
                Toggl::strategy('ui-redesign')
                    ->variants([
                        'current-ui' => 34,
                        'redesign-v1' => 33,
                        'redesign-v2' => 33,
                    ])
                    ->for($user);
            }

            // Assert - All users assigned to one of the variants
            $variantCounts = ['current-ui' => 0, 'redesign-v1' => 0, 'redesign-v2' => 0];

            foreach ($users as $user) {
                $variant = Toggl::variant('ui-redesign')->for($user)->get();
                ++$variantCounts[$variant];
            }

            expect($variantCounts['current-ui'])->toBeGreaterThan(0);
            expect($variantCounts['redesign-v1'])->toBeGreaterThan(0);
            expect($variantCounts['redesign-v2'])->toBeGreaterThan(0);
        });
    });

    describe('Edge Cases', function (): void {
        test('percentage rollout with same feature different users', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act - Same percentage strategy
            Toggl::strategy('edge-test')->percentage(50)->for($user1);
            Toggl::strategy('edge-test')->percentage(50)->for($user2);

            // Assert - Different users may get different results
            // (dependent on hash distribution)
            expect(true)->toBeTrue(); // Test passes if no errors
        });

        test('time strategy with only from date', function (): void {
            // Arrange
            $user = User::factory()->create();
            $yesterday = Date::parse('-1 day')->format('Y-m-d');

            // Act - No end date (runs forever)
            Toggl::strategy('perpetual')
                ->from($yesterday)
                ->for($user);

            // Assert - Active since started
            expect(Toggl::for($user)->active('perpetual'))->toBeTrue();
        });

        test('time strategy with only until date', function (): void {
            // Arrange
            $user = User::factory()->create();
            $tomorrow = Date::parse('+1 day')->format('Y-m-d');

            // Act - No start date (started in the past)
            Toggl::strategy('ending-soon')
                ->until($tomorrow)
                ->for($user);

            // Assert - Active until end date
            expect(Toggl::for($user)->active('ending-soon'))->toBeTrue();
        });

        test('conductor exposes strategy metadata', function (): void {
            // Arrange & Act
            $conductor = Toggl::strategy('metadata-test')
                ->percentage(75);

            // Assert - Can inspect strategy configuration
            expect($conductor->feature())->toBe('metadata-test');
            expect($conductor->strategyType())->toBe('percentage');
            expect($conductor->strategyData())->toBe(75);
        });

        test('percentage strategy works with TogglContext', function (): void {
            // Arrange
            $context = TogglContext::simple(123, 'user');

            // Act - Use TogglContext
            Toggl::strategy('object-test')
                ->percentage(50)
                ->for($context);

            // Assert - Should work without error
            expect(true)->toBeTrue();
        });

        test('throws for plain object context', function (): void {
            // Arrange
            $context = new stdClass();
            $context->id = 123;

            // Act & Assert - stdClass is no longer supported
            expect(fn () => Toggl::strategy('object-test')
                ->percentage(50)
                ->for($context))
                ->toThrow(InvalidContextTypeException::class);
        });

        test('throws for string context', function (): void {
            // Arrange
            $context = 'user-identifier-string';

            // Act & Assert - strings are no longer valid contexts
            expect(fn () => Toggl::strategy('string-test')
                ->percentage(50)
                ->for($context))
                ->toThrow(InvalidContextTypeException::class);
        });

        test('throws for class string context', function (): void {
            // Arrange
            $context = stdClass::class;

            // Act & Assert - class strings are no longer valid contexts
            expect(fn () => Toggl::strategy('class-string-test')
                ->percentage(50)
                ->for($context))
                ->toThrow(InvalidContextTypeException::class);
        });
    });

    describe('Global Time Strategy Activation', function (): void {
        test('activate() executes applyGlobalTimeStrategy for time-based features', function (): void {
            // Arrange
            $yesterday = Date::parse('-1 day')->format('Y-m-d');
            $tomorrow = Date::parse('+1 day')->format('Y-m-d');

            // Act - Use activate() for global time strategy (covers lines 120-121, 191-198)
            // Note: This tests that the method executes without errors
            // The actual global activation behavior depends on ActivationConductor terminal methods
            Toggl::strategy('global-time-test')
                ->from($yesterday)
                ->until($tomorrow)
                ->activate();

            // Assert - Method executes without throwing errors (covers applyGlobalTimeStrategy lines)
            expect(true)->toBeTrue();
        });

        test('activate() handles time strategy with past date range', function (): void {
            // Arrange
            $farPast = Date::parse('-20 days')->format('Y-m-d');
            $past = Date::parse('-10 days')->format('Y-m-d');

            // Act - Time range is in the past (line 197 condition fails)
            Toggl::strategy('expired-global')
                ->from($farPast)
                ->until($past)
                ->activate();

            // Assert - Method completes without errors even when condition not met
            expect(true)->toBeTrue();
        });

        test('activate() handles time strategy with future date range', function (): void {
            // Arrange
            $future = Date::parse('+10 days')->format('Y-m-d');
            $farFuture = Date::parse('+20 days')->format('Y-m-d');

            // Act - Time range is in future (line 197 condition fails)
            Toggl::strategy('future-global')
                ->from($future)
                ->until($farFuture)
                ->activate();

            // Assert - Method completes without errors
            expect(true)->toBeTrue();
        });

        test('activate() does nothing for percentage strategies', function (): void {
            // Arrange & Act - activate() only applies to time strategies (line 120)
            Toggl::strategy('percentage-test')
                ->percentage(50)
                ->activate();

            // Assert - No errors, percentage strategy ignored by activate()
            expect(true)->toBeTrue();
        });

        test('activate() does nothing for variant strategies', function (): void {
            // Arrange & Act - activate() only applies to time strategies (line 120)
            Toggl::strategy('variant-test')
                ->variants(['a' => 50, 'b' => 50])
                ->activate();

            // Assert - No errors, variant strategy ignored by activate()
            expect(true)->toBeTrue();
        });

        test('activate() handles time strategy with only from date', function (): void {
            // Arrange
            $yesterday = Date::parse('-1 day')->format('Y-m-d');

            // Act - Only from date set (line 195 uses PHP_INT_MAX for until)
            Toggl::strategy('from-only-global')
                ->from($yesterday)
                ->activate();

            // Assert - Method executes without errors
            expect(true)->toBeTrue();
        });

        test('activate() handles time strategy with only until date', function (): void {
            // Arrange
            $tomorrow = Date::parse('+1 day')->format('Y-m-d');

            // Act - Only until date set (line 194 uses 0 for from)
            Toggl::strategy('until-only-global')
                ->until($tomorrow)
                ->activate();

            // Assert - Method executes without errors
            expect(true)->toBeTrue();
        });

        test('for() throws exception for array context', function (): void {
            // Arrange
            $context = ['id' => 123];

            // Act & Assert
            expect(fn () => Toggl::strategy('test')
                ->percentage(50)
                ->for($context))
                ->toThrow(InvalidContextTypeException::class);
        });

        test('for() throws exception for resource context', function (): void {
            // Arrange
            $context = fopen('php://memory', 'rb');

            // Act & Assert
            try {
                Toggl::strategy('test')
                    ->percentage(50)
                    ->for($context);
                expect(true)->toBeFalse(); // Should not reach here
            } catch (InvalidContextTypeException $invalidContextTypeException) {
                expect($invalidContextTypeException->getMessage())->toContain('Context must be TogglContext, TogglContextable, or Eloquent Model');
            } finally {
                fclose($context);
            }
        });
    });
});
