<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Toggl;
use Tests\Fixtures\FeatureFlag;
use Tests\Fixtures\User;

/**
 * Query Conductor Test Suite
 *
 * Tests the query conductor pattern: Toggl::when('premium')->for($user)->then()->otherwise()
 * This pattern enables conditional execution based on feature status with a fluent API.
 */
describe('Query Conductor', function (): void {
    describe('Happy Path', function (): void {
        test('can execute then callback when feature is active', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('premium', fn (): true => true);
            $executed = false;

            // Act
            Toggl::when('premium')
                ->for($user)
                ->then(function () use (&$executed): void {
                    $executed = true;
                })
                ->otherwise(function (): void {
                    // Should not execute
                });

            // Assert
            expect($executed)->toBeTrue();
        });

        test('can execute otherwise callback when feature is inactive', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('premium', fn (): false => false);
            $executed = false;

            // Act
            Toggl::when('premium')
                ->for($user)
                ->then(function (): void {
                    // Should not execute
                })
                ->otherwise(function () use (&$executed): void {
                    $executed = true;
                });

            // Assert
            expect($executed)->toBeTrue();
        });

        test('can use when/then without otherwise', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('premium', fn (): true => true);
            $result = 'initial';

            // Act
            Toggl::when('premium')
                ->for($user)
                ->then(function () use (&$result): void {
                    $result = 'executed';
                });

            // Assert
            expect($result)->toBe('executed');
        });

        test('can use BackedEnum for feature', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define(FeatureFlag::NewDashboard, fn (): true => true);
            $executed = false;

            // Act
            Toggl::when(FeatureFlag::NewDashboard)
                ->for($user)
                ->then(function () use (&$executed): void {
                    $executed = true;
                });

            // Assert
            expect($executed)->toBeTrue();
        });

        test('callbacks can return values', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('premium', fn (): true => true);

            // Act
            $result = null;
            Toggl::when('premium')
                ->for($user)
                ->then(function () use (&$result): void {
                    $result = 'premium-active';
                })
                ->otherwise(function () use (&$result): void {
                    $result = 'premium-inactive';
                });

            // Assert
            expect($result)->toBe('premium-active');
        });
    });

    describe('Edge Cases', function (): void {
        test('then callback not executed when feature is inactive', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('premium', fn (): false => false);
            $executed = false;

            // Act
            Toggl::when('premium')
                ->for($user)
                ->then(function () use (&$executed): void {
                    $executed = true;
                });

            // Assert
            expect($executed)->toBeFalse();
        });

        test('otherwise callback not executed when feature is active', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('premium', fn (): true => true);
            $executed = false;

            // Act
            Toggl::when('premium')
                ->for($user)
                ->then(function (): void {
                    // Do nothing
                })
                ->otherwise(function () use (&$executed): void {
                    $executed = true;
                });

            // Assert
            expect($executed)->toBeFalse();
        });

        test('works with undefined features (defaults to false)', function (): void {
            // Arrange
            $user = User::factory()->create();
            $otherwiseExecuted = false;

            // Act
            Toggl::when('undefined-feature')
                ->for($user)
                ->then(function (): void {
                    // Should not execute
                })
                ->otherwise(function () use (&$otherwiseExecuted): void {
                    $otherwiseExecuted = true;
                });

            // Assert
            expect($otherwiseExecuted)->toBeTrue();
        });

        test('works with contextual activation', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            Toggl::define('premium', fn (): false => false);
            Toggl::for($user1)->activate('premium');

            $user1Result = null;
            $user2Result = null;

            // Act
            Toggl::when('premium')
                ->for($user1)
                ->then(function () use (&$user1Result): void {
                    $user1Result = 'active';
                })
                ->otherwise(function () use (&$user1Result): void {
                    $user1Result = 'inactive';
                });

            Toggl::when('premium')
                ->for($user2)
                ->then(function () use (&$user2Result): void {
                    $user2Result = 'active';
                })
                ->otherwise(function () use (&$user2Result): void {
                    $user2Result = 'inactive';
                });

            // Assert
            expect($user1Result)->toBe('active');
            expect($user2Result)->toBe('inactive');
        });
    });

    describe('Integration with existing API', function (): void {
        test('conductor pattern works alongside traditional Toggl::for()->when() pattern', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('premium', fn (): true => true);
            $conductorResult = null;
            $traditionalResult = null;

            // Act - new conductor pattern
            Toggl::when('premium')
                ->for($user)
                ->then(function () use (&$conductorResult): void {
                    $conductorResult = 'active';
                })
                ->otherwise(function () use (&$conductorResult): void {
                    $conductorResult = 'inactive';
                });

            // Act - traditional pattern
            Toggl::for($user)->when('premium', function () use (&$traditionalResult): void {
                $traditionalResult = 'active';
            }, function () use (&$traditionalResult): void {
                $traditionalResult = 'inactive';
            });

            // Assert - both work
            expect($conductorResult)->toBe('active');
            expect($traditionalResult)->toBe('active');
        });

        test('can chain multiple when checks for same context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('premium', fn (): true => true);
            Toggl::define('admin', fn (): false => false);
            $premiumChecked = false;
            $adminChecked = false;

            // Act
            Toggl::when('premium')
                ->for($user)
                ->then(function () use (&$premiumChecked): void {
                    $premiumChecked = true;
                });

            Toggl::when('admin')
                ->for($user)
                ->otherwise(function () use (&$adminChecked): void {
                    $adminChecked = true;
                });

            // Assert
            expect($premiumChecked)->toBeTrue();
            expect($adminChecked)->toBeTrue();
        });
    });
});
