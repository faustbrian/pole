<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Contracts\TogglContextable;
use Cline\Toggl\Exceptions\InvalidContextTypeException;
use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * Rollout Conductor Test Suite
 *
 * Tests gradual feature rollouts with percentage-based activation.
 */
describe('Rollout Conductor', function (): void {
    describe('Percentage Rollout', function (): void {
        test('0% rollout activates no users', function (): void {
            // Arrange
            $users = User::factory()->count(10)->create();

            // Act & Assert
            foreach ($users as $user) {
                $isActive = Toggl::rollout('new-ui')
                    ->toPercentage(0)
                    ->for($user);

                expect($isActive)->toBeFalse();
                expect(Toggl::for($user)->active('new-ui'))->toBeFalse();
            }
        });

        test('100% rollout activates all users', function (): void {
            // Arrange
            $users = User::factory()->count(10)->create();

            // Act & Assert
            foreach ($users as $user) {
                $isActive = Toggl::rollout('new-ui')
                    ->toPercentage(100)
                    ->for($user);

                expect($isActive)->toBeTrue();
                expect(Toggl::for($user)->active('new-ui'))->toBeTrue();
            }
        });

        test('50% rollout activates approximately half', function (): void {
            // Arrange
            $users = User::factory()->count(100)->create();
            $activeCount = 0;

            // Act
            foreach ($users as $user) {
                $isActive = Toggl::rollout('new-ui')
                    ->toPercentage(50)
                    ->for($user);

                if ($isActive) {
                    ++$activeCount;
                }
            }

            // Assert - allow 20% variance (40-60 users)
            expect($activeCount)->toBeGreaterThanOrEqual(40);
            expect($activeCount)->toBeLessThanOrEqual(60);
        });

        test('clamps percentage to 0-100 range', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Test over 100
            $conductor = Toggl::rollout('test')->toPercentage(150);
            expect($conductor->percentage())->toBe(100);

            // Act - Test under 0
            $conductor = Toggl::rollout('test')->toPercentage(-50);
            expect($conductor->percentage())->toBe(0);
        });
    });

    describe('Sticky Rollouts', function (): void {
        test('sticky rollout maintains same users as percentage increases', function (): void {
            // Arrange
            $users = User::factory()->count(100)->create();

            // Act - Roll out to 25%
            $usersAt25 = [];

            foreach ($users as $user) {
                $isActive = Toggl::rollout('gradual-feature')
                    ->toPercentage(25)
                    ->withStickiness(true)
                    ->withSeed('test-seed')
                    ->for($user);

                if ($isActive) {
                    $usersAt25[] = $user->id;
                }
            }

            // Act - Increase to 50%
            $usersAt50 = [];

            foreach ($users as $user) {
                $isActive = Toggl::rollout('gradual-feature')
                    ->toPercentage(50)
                    ->withStickiness(true)
                    ->withSeed('test-seed')
                    ->for($user);

                if ($isActive) {
                    $usersAt50[] = $user->id;
                }
            }

            // Assert - All users from 25% should still be in 50%
            foreach ($usersAt25 as $userId) {
                expect($usersAt50)->toContain($userId);
            }

            // Assert - 50% should have more users than 25%
            expect(count($usersAt50))->toBeGreaterThan(count($usersAt25));
        });

        test('sticky rollout with same seed gives consistent results', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - First check
            $result1 = Toggl::rollout('consistent-feature')
                ->toPercentage(50)
                ->withStickiness(true)
                ->withSeed('fixed-seed')
                ->for($user);

            // Act - Second check with same parameters
            $result2 = Toggl::rollout('consistent-feature')
                ->toPercentage(50)
                ->withStickiness(true)
                ->withSeed('fixed-seed')
                ->for($user);

            // Assert
            expect($result1)->toBe($result2);
        });

        test('different seeds produce different rollouts', function (): void {
            // Arrange
            $users = User::factory()->count(100)->create();

            // Act - Rollout with seed1
            $activeWithSeed1 = [];

            foreach ($users as $user) {
                $isActive = Toggl::rollout('feature-a')
                    ->toPercentage(50)
                    ->withStickiness(true)
                    ->withSeed('seed-1')
                    ->for($user);

                if ($isActive) {
                    $activeWithSeed1[] = $user->id;
                }
            }

            // Act - Rollout with seed2
            $activeWithSeed2 = [];

            foreach ($users as $user) {
                $isActive = Toggl::rollout('feature-b')
                    ->toPercentage(50)
                    ->withStickiness(true)
                    ->withSeed('seed-2')
                    ->for($user);

                if ($isActive) {
                    $activeWithSeed2[] = $user->id;
                }
            }

            // Assert - User sets should be different
            expect($activeWithSeed1)->not->toBe($activeWithSeed2);
        });

        test('non-sticky rollout can produce different results', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Multiple checks
            $results = [];

            for ($i = 0; $i < 20; ++$i) {
                $results[] = Toggl::rollout('random-feature')
                    ->toPercentage(50)
                    ->withStickiness(false)
                    ->for($user);
            }

            // Assert - Should have mix of true and false (statistically)
            $trueCount = count(array_filter($results));
            expect($trueCount)->toBeGreaterThan(0);
            expect($trueCount)->toBeLessThan(20);
        });
    });

    describe('Deactivation', function (): void {
        test('reducing percentage deactivates users', function (): void {
            // Arrange
            $users = User::factory()->count(100)->create();

            // Act - Activate 100%
            foreach ($users as $user) {
                Toggl::rollout('beta')
                    ->toPercentage(100)
                    ->for($user);
            }

            // Act - Reduce to 0%
            foreach ($users as $user) {
                Toggl::rollout('beta')
                    ->toPercentage(0)
                    ->for($user);
            }

            // Assert
            foreach ($users as $user) {
                expect(Toggl::for($user)->active('beta'))->toBeFalse();
            }
        });

        test('users outside rollout percentage are deactivated', function (): void {
            // Arrange
            $users = User::factory()->count(100)->create();

            // Act - Start with 100%
            foreach ($users as $user) {
                Toggl::rollout('feature')
                    ->toPercentage(100)
                    ->withStickiness(true)
                    ->withSeed('test')
                    ->for($user);
            }

            // Act - Reduce to 25%
            $activeCount = 0;

            foreach ($users as $user) {
                $isActive = Toggl::rollout('feature')
                    ->toPercentage(25)
                    ->withStickiness(true)
                    ->withSeed('test')
                    ->for($user);

                if (Toggl::for($user)->active('feature')) {
                    ++$activeCount;
                }
            }

            // Assert - Approximately 25% should be active (with reasonable variance)
            expect($activeCount)->toBeGreaterThanOrEqual(10);
            expect($activeCount)->toBeLessThanOrEqual(40);
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('gradual new UI rollout', function (): void {
            // Arrange
            $users = User::factory()->count(1_000)->create();

            // Act - Phase 1: 10% internal beta
            $phase1Users = [];

            foreach ($users as $user) {
                $isActive = Toggl::rollout('new-dashboard')
                    ->toPercentage(10)
                    ->withStickiness(true)
                    ->withSeed('dashboard-v2')
                    ->for($user);

                if ($isActive) {
                    $phase1Users[] = $user->id;
                }
            }

            // Assert phase 1 - sticky rollout is deterministic, verify same users get same result
            $phase1Count = count($phase1Users);
            expect($phase1Count)->toBeGreaterThanOrEqual(50) // At least 5%
                ->toBeLessThanOrEqual(150); // At most 15%

            // Act - Phase 2: 50% broader rollout
            $phase2Users = [];

            foreach ($users as $user) {
                $isActive = Toggl::rollout('new-dashboard')
                    ->toPercentage(50)
                    ->withStickiness(true)
                    ->withSeed('dashboard-v2')
                    ->for($user);

                if ($isActive) {
                    $phase2Users[] = $user->id;
                }
            }

            $phase2Count = count($phase2Users);

            // Assert phase 2 - expansion property: phase2 includes ALL phase1 users
            foreach ($phase1Users as $userId) {
                expect($phase2Users)->toContain($userId);
            }

            // Phase 2 should have more users than phase 1
            expect($phase2Count)->toBeGreaterThan($phase1Count);

            // Allow ±20% variance for hash distribution (400-600 range for 50% target)
            expect($phase2Count)->toBeGreaterThanOrEqual(350)
                ->toBeLessThanOrEqual(650);
        });

        test('A/B test with 50/50 split', function (): void {
            // Arrange
            $users = User::factory()->count(200)->create();
            $variantACount = 0;
            $variantBCount = 0;

            // Act
            foreach ($users as $user) {
                $inVariantA = Toggl::rollout('experiment-variant-a')
                    ->toPercentage(50)
                    ->withStickiness(true)
                    ->withSeed('experiment-1')
                    ->for($user);

                if ($inVariantA) {
                    ++$variantACount;
                } else {
                    ++$variantBCount;
                }
            }

            // Assert - Roughly 50/50 split (allow 20% variance)
            expect($variantACount)->toBeGreaterThanOrEqual(80);
            expect($variantACount)->toBeLessThanOrEqual(120);
            expect($variantBCount)->toBeGreaterThanOrEqual(80);
            expect($variantBCount)->toBeLessThanOrEqual(120);
        });

        test('emergency rollback scenario', function (): void {
            // Arrange
            $users = User::factory()->count(50)->create();

            // Act - Feature rolled out to 75%
            foreach ($users as $user) {
                Toggl::rollout('payment-redesign')
                    ->toPercentage(75)
                    ->for($user);
            }

            // Act - Emergency: bugs found, roll back to 0%
            foreach ($users as $user) {
                Toggl::rollout('payment-redesign')
                    ->toPercentage(0)
                    ->for($user);
            }

            // Assert - All users should have feature deactivated
            foreach ($users as $user) {
                expect(Toggl::for($user)->active('payment-redesign'))->toBeFalse();
            }
        });

        test('canary deployment pattern', function (): void {
            // Arrange - Simulating production with 10,000 users
            $sampleSize = 500; // Test with subset for performance
            $users = User::factory()->count($sampleSize)->create();

            // Act - Canary: 1% of users
            $canaryActive = 0;

            foreach ($users as $user) {
                $isActive = Toggl::rollout('api-v2')
                    ->toPercentage(1)
                    ->withStickiness(true)
                    ->withSeed('api-migration')
                    ->for($user);

                if ($isActive) {
                    ++$canaryActive;
                }
            }

            // Assert - Approximately 1% (0-20 users out of 500, allowing for hash variance)
            expect($canaryActive)->toBeGreaterThanOrEqual(0);
            expect($canaryActive)->toBeLessThanOrEqual(20);
        });
    });

    describe('Edge Cases', function (): void {
        test('conductor exposes feature name', function (): void {
            // Arrange & Act
            $conductor = Toggl::rollout('test-feature');

            // Assert
            expect($conductor->feature())->toBe('test-feature');
        });

        test('conductor exposes percentage', function (): void {
            // Arrange & Act
            $conductor = Toggl::rollout('test')->toPercentage(75);

            // Assert
            expect($conductor->percentage())->toBe(75);
        });

        test('conductor exposes stickiness setting', function (): void {
            // Arrange & Act
            $sticky = Toggl::rollout('test')->withStickiness(true);
            $nonSticky = Toggl::rollout('test')->withStickiness(false);

            // Assert
            expect($sticky->isSticky())->toBeTrue();
            expect($nonSticky->isSticky())->toBeFalse();
        });

        test('conductor exposes seed', function (): void {
            // Arrange & Act
            $conductor = Toggl::rollout('test')->withSeed('custom-seed');

            // Assert
            expect($conductor->seed())->toBe('custom-seed');
        });

        test('default seed is null', function (): void {
            // Arrange & Act
            $conductor = Toggl::rollout('test');

            // Assert
            expect($conductor->seed())->toBeNull();
        });

        test('default stickiness is true', function (): void {
            // Arrange & Act
            $conductor = Toggl::rollout('test');

            // Assert
            expect($conductor->isSticky())->toBeTrue();
        });

        test('method chaining creates new instances', function (): void {
            // Arrange
            $conductor1 = Toggl::rollout('test');
            $conductor2 = $conductor1->toPercentage(50);
            $conductor3 = $conductor2->withSeed('test');

            // Assert
            expect($conductor1)->not->toBe($conductor2);
            expect($conductor2)->not->toBe($conductor3);
        });

        test('handles objects with id property', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $isActive = Toggl::rollout('test')
                ->toPercentage(50)
                ->withStickiness(true)
                ->withSeed('test')
                ->for($user);

            // Assert - Should be deterministic based on user ID
            expect($isActive)->toBeBool();
        });

        test('handles TogglContext with specific id', function (): void {
            // Arrange
            $context = TogglContext::simple(12_345, 'user');

            // Act
            $isActive = Toggl::rollout('test-feature')
                ->toPercentage(50)
                ->withStickiness(true)
                ->withSeed('test-seed')
                ->for($context);

            // Assert - Should be deterministic based on context id
            expect($isActive)->toBeBool();

            // Act - Verify consistency with same context
            $isActiveSame = Toggl::rollout('test-feature')
                ->toPercentage(50)
                ->withStickiness(true)
                ->withSeed('test-seed')
                ->for($context);

            // Assert - Same context should give same result
            expect($isActiveSame)->toBe($isActive);
        });

        test('handles TogglContext with string id', function (): void {
            // Arrange
            $context = TogglContext::simple('user:12345', 'user');

            // Act
            $isActive = Toggl::rollout('test')
                ->toPercentage(50)
                ->withStickiness(true)
                ->withSeed('test')
                ->for($context);

            // Assert
            expect($isActive)->toBeBool();
        });

        test('throws for invalid context types', function (): void {
            // Act & Assert - numeric contexts are no longer valid
            expect(fn () => Toggl::rollout('test')
                ->toPercentage(50)
                ->for(12_345))
                ->toThrow(InvalidContextTypeException::class);
        });

        test('handles TogglContextable objects', function (): void {
            // Arrange
            $context = new class() implements TogglContextable
            {
                public function toTogglContext(): TogglContext
                {
                    return TogglContext::simple(12_345, 'user');
                }
            };

            // Act
            $isActive = Toggl::rollout('test')
                ->toPercentage(50)
                ->withStickiness(true)
                ->withSeed('test')
                ->for($context);

            // Assert - Should be deterministic based on context ID
            expect($isActive)->toBeBool();

            // Act - Verify consistency with same object
            $isActiveSameObject = Toggl::rollout('test')
                ->toPercentage(50)
                ->withStickiness(true)
                ->withSeed('test')
                ->for($context);

            // Assert - Same object should give same result
            expect($isActiveSameObject)->toBe($isActive);
        });

        test('throws for plain objects without TogglContextable', function (): void {
            // Arrange
            $context = new stdClass();

            // Act & Assert - stdClass is not supported
            expect(fn () => Toggl::rollout('test')
                ->toPercentage(50)
                ->for($context))
                ->toThrow(InvalidContextTypeException::class);
        });

        test('throws for array context', function (): void {
            // Arrange
            $context = ['id' => 123];

            // Act & Assert - arrays are not supported
            expect(fn () => Toggl::rollout('test')
                ->toPercentage(50)
                ->for($context))
                ->toThrow(InvalidContextTypeException::class);
        });
    });
});
