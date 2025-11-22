<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\FeatureManager;
use Cline\Toggl\Toggl;
use Illuminate\Support\Facades\Date;
use Tests\Fixtures\FeatureFlag;
use Tests\Fixtures\User;

/**
 * Schedule Conductor Test Suite
 *
 * Tests time-based feature activation and deactivation scheduling.
 */
describe('Schedule Conductor', function (): void {
    describe('Time Window Scheduling', function (): void {
        test('activates feature within time window', function (): void {
            // Arrange
            $user = User::factory()->create();
            $now = Date::now();
            $start = (clone $now)->modify('-1 hour');
            $end = (clone $now)->modify('+1 hour');

            // Act
            $isActive = Toggl::schedule('flash-sale')
                ->between($start, $end)
                ->for($user);

            // Assert
            expect($isActive)->toBeTrue();
            expect(Toggl::for($user)->active('flash-sale'))->toBeTrue();
        });

        test('does not activate before start time', function (): void {
            // Arrange
            $user = User::factory()->create();
            $now = Date::now();
            $start = (clone $now)->modify('+1 hour');
            $end = (clone $now)->modify('+2 hours');

            // Act
            $isActive = Toggl::schedule('flash-sale')
                ->between($start, $end)
                ->for($user);

            // Assert
            expect($isActive)->toBeFalse();
            expect(Toggl::for($user)->active('flash-sale'))->toBeFalse();
        });

        test('does not activate after end time', function (): void {
            // Arrange
            $user = User::factory()->create();
            $now = Date::now();
            $start = (clone $now)->modify('-2 hours');
            $end = (clone $now)->modify('-1 hour');

            // Act
            $isActive = Toggl::schedule('flash-sale')
                ->between($start, $end)
                ->for($user);

            // Assert
            expect($isActive)->toBeFalse();
            expect(Toggl::for($user)->active('flash-sale'))->toBeFalse();
        });

        test('deactivates feature when window ends', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('flash-sale');

            $now = Date::now();
            $start = (clone $now)->modify('-2 hours');
            $end = (clone $now)->modify('-1 hour');

            // Act
            $isActive = Toggl::schedule('flash-sale')
                ->between($start, $end)
                ->for($user);

            // Assert
            expect($isActive)->toBeFalse();
            expect(Toggl::for($user)->active('flash-sale'))->toBeFalse();
        });
    });

    describe('Specific Time Scheduling', function (): void {
        test('activates at specific time', function (): void {
            // Arrange
            $user = User::factory()->create();
            $activateTime = Date::now()->modify('-1 hour');

            // Act
            $isActive = Toggl::schedule('promotion')
                ->activateAt($activateTime)
                ->for($user);

            // Assert
            expect($isActive)->toBeTrue();
            expect(Toggl::for($user)->active('promotion'))->toBeTrue();
        });

        test('does not activate before scheduled time', function (): void {
            // Arrange
            $user = User::factory()->create();
            $activateTime = Date::now()->modify('+1 hour');

            // Act
            $isActive = Toggl::schedule('promotion')
                ->activateAt($activateTime)
                ->for($user);

            // Assert
            expect($isActive)->toBeFalse();
            expect(Toggl::for($user)->active('promotion'))->toBeFalse();
        });

        test('deactivates at specific time', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('trial');

            $deactivateTime = Date::now()->modify('-1 hour');

            // Act
            $isActive = Toggl::schedule('trial')
                ->deactivateAt($deactivateTime)
                ->for($user);

            // Assert
            expect($isActive)->toBeFalse();
            expect(Toggl::for($user)->active('trial'))->toBeFalse();
        });

        test('remains active until deactivation time', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('trial');

            $deactivateTime = Date::now()->modify('+1 hour');

            // Act
            $isActive = Toggl::schedule('trial')
                ->deactivateAt($deactivateTime)
                ->for($user);

            // Assert
            expect($isActive)->toBeTrue();
            expect(Toggl::for($user)->active('trial'))->toBeTrue();
        });
    });

    describe('String Date Parsing', function (): void {
        test('accepts string dates for between', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $isActive = Toggl::schedule('sale')
                ->between('-1 hour', '+1 hour')
                ->for($user);

            // Assert
            expect($isActive)->toBeTrue();
        });

        test('accepts string dates for activateAt', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $isActive = Toggl::schedule('promo')
                ->activateAt('-1 hour')
                ->for($user);

            // Assert
            expect($isActive)->toBeTrue();
        });

        test('accepts string dates for deactivateAt', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('trial');

            // Act
            $isActive = Toggl::schedule('trial')
                ->deactivateAt('-1 hour')
                ->for($user);

            // Assert
            expect($isActive)->toBeFalse();
        });
    });

    describe('Value Assignment', function (): void {
        test('activates with custom value', function (): void {
            // Arrange
            $user = User::factory()->create();
            $now = Date::now();

            // Act
            Toggl::schedule('premium')
                ->activateAt((clone $now)->modify('-1 hour'))
                ->withValue(['tier' => 'gold', 'credits' => 100])
                ->for($user);

            // Assert
            $value = Toggl::for($user)->value('premium');
            expect($value)->toBe(['tier' => 'gold', 'credits' => 100]);
        });

        test('default value is true', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::schedule('feature')
                ->activateAt('-1 hour')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->value('feature'))->toBeTrue();
        });
    });

    describe('Saved Schedules', function (): void {
        test('saves schedule for later evaluation', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $scheduleId = Toggl::schedule('maintenance')
                ->between('+1 hour', '+2 hours')
                ->save($user);

            // Assert
            expect($scheduleId)->toBeString();
            expect($scheduleId)->toStartWith('schedule_');
        });

        test('lists all saved schedules', function (): void {
            // Arrange
            $user = User::factory()->create();

            Toggl::schedule('sale-1')->between('-1 hour', '+1 hour')->save($user);
            Toggl::schedule('sale-2')->between('+1 hour', '+2 hours')->save($user);

            // Act
            $schedules = Toggl::schedule('any')->listSchedules($user);

            // Assert
            expect($schedules)->toHaveCount(2);
            expect($schedules[0])->toHaveKey('feature');
            expect($schedules[0])->toHaveKey('start_time');
            expect($schedules[0])->toHaveKey('end_time');
        });

        test('deletes saved schedule', function (): void {
            // Arrange
            $user = User::factory()->create();
            $scheduleId = Toggl::schedule('temp')->between('-1 hour', '+1 hour')->save($user);

            // Act
            Toggl::schedule('temp')->deleteSchedule($scheduleId, $user);

            // Assert
            $schedules = Toggl::schedule('temp')->listSchedules($user);
            expect($schedules)->toBe([]);
        });

        test('deleteSchedule handles missing schedules gracefully', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Try to delete when no schedules exist
            Toggl::schedule('test-feature')->deleteSchedule('non-existent-id', $user);

            // Assert
            $schedules = Toggl::schedule('test-feature')->listSchedules($user);
            expect($schedules)->toBe([]);
        });

        test('deleteSchedule removes single schedule from multiple', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Save multiple schedules
            $id1 = Toggl::schedule('feature1')->activateAt('+1 hour')->save($user);
            $id2 = Toggl::schedule('feature2')->activateAt('+2 hours')->save($user);
            $id3 = Toggl::schedule('feature3')->activateAt('+3 hours')->save($user);

            // Act - Delete one schedule
            Toggl::schedule('feature2')->deleteSchedule($id2, $user);

            // Assert - Verify 2 schedules remain
            $remaining = Toggl::schedule('any')->listSchedules($user);
            expect($remaining)->toHaveCount(2);
            expect(collect($remaining)->pluck('id')->all())->toContain($id1, $id3);
            expect(collect($remaining)->pluck('id')->all())->not->toContain($id2);
        });

        test('applies all saved schedules', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Save schedules: one active, one inactive
            Toggl::schedule('active-sale')->between('-1 hour', '+1 hour')->save($user);
            Toggl::schedule('future-sale')->between('+1 hour', '+2 hours')->save($user);

            // Act
            $updated = Toggl::schedule('any')->applyAll($user);

            // Assert
            expect($updated)->toBe(1); // Only active-sale changed state
            expect(Toggl::for($user)->active('active-sale'))->toBeTrue();
            expect(Toggl::for($user)->active('future-sale'))->toBeFalse();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('flash sale with time window', function (): void {
            // Arrange
            $user = User::factory()->create();
            $saleStart = Date::parse('2024-12-25 00:00:00');
            $saleEnd = Date::parse('2024-12-25 23:59:59');

            // Simulate during sale (mock current time within window)
            $during = Date::parse('2024-12-25 12:00:00');

            // Act - Manual test by checking if would be active
            $conductor = Toggl::schedule('christmas-sale')
                ->between($saleStart, $saleEnd)
                ->withValue(['discount' => 50]);

            // Assert structure
            expect($conductor->startTime())->toEqual($saleStart);
            expect($conductor->endTime())->toEqual($saleEnd);
            expect($conductor->value())->toBe(['discount' => 50]);
        });

        test('trial expiration', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('trial', ['days_remaining' => 30]);

            $expirationDate = Date::now()->modify('-1 day');

            // Act
            Toggl::schedule('trial')
                ->deactivateAt($expirationDate)
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('trial'))->toBeFalse();
        });

        test('scheduled maintenance window', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Schedule maintenance for tonight
            $scheduleId = Toggl::schedule('maintenance-mode')
                ->between('+8 hours', '+10 hours')
                ->withValue(['message' => 'System maintenance in progress'])
                ->save($user);

            // Act - Check schedule was saved
            $schedules = Toggl::schedule('any')->listSchedules($user);

            // Assert
            expect($schedules)->toHaveCount(1);
            expect($schedules[0]['feature'])->toBe('maintenance-mode');
            expect($schedules[0]['value'])->toBe(['message' => 'System maintenance in progress']);
        });

        test('beta program enrollment period', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Beta enrollment open for one week
            $enrollmentStart = Date::now()->modify('-3 days');
            $enrollmentEnd = Date::now()->modify('+4 days');

            // Act
            Toggl::schedule('beta-enrollment')
                ->between($enrollmentStart, $enrollmentEnd)
                ->for($user);

            // Assert
            expect(Toggl::for($user)->active('beta-enrollment'))->toBeTrue();
        });
    });

    describe('Edge Cases', function (): void {
        test('conductor exposes feature name', function (): void {
            // Arrange & Act
            $conductor = Toggl::schedule('test-feature');

            // Assert
            expect($conductor->feature())->toBe('test-feature');
        });

        test('conductor exposes start time', function (): void {
            // Arrange
            $start = Date::now()->subHours(1);

            // Act
            $conductor = Toggl::schedule('test')->activateAt($start);

            // Assert
            expect($conductor->startTime())->toEqual($start);
        });

        test('conductor exposes end time', function (): void {
            // Arrange
            $end = Date::now()->addHours(1);

            // Act
            $conductor = Toggl::schedule('test')->deactivateAt($end);

            // Assert
            expect($conductor->endTime())->toEqual($end);
        });

        test('conductor exposes value', function (): void {
            // Arrange & Act
            $conductor = Toggl::schedule('test')->withValue(['custom' => 'data']);

            // Assert
            expect($conductor->value())->toBe(['custom' => 'data']);
        });

        test('no schedule means always active', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - No time constraints
            $isActive = Toggl::schedule('always-on')->for($user);

            // Assert
            expect($isActive)->toBeTrue();
        });

        test('does not reactivate if already active with same state', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('sale');

            // Act
            Toggl::schedule('sale')
                ->between('-1 hour', '+1 hour')
                ->for($user);

            // Assert - Should still be active
            expect(Toggl::for($user)->active('sale'))->toBeTrue();
        });

        test('method chaining creates new instances', function (): void {
            // Arrange
            $conductor1 = Toggl::schedule('test');
            $conductor2 = $conductor1->activateAt('-1 hour');
            $conductor3 = $conductor2->withValue(['data']);

            // Assert
            expect($conductor1)->not->toBe($conductor2);
            expect($conductor2)->not->toBe($conductor3);
        });

        test('handles empty saved schedules', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $schedules = Toggl::schedule('any')->listSchedules($user);

            // Assert
            expect($schedules)->toBe([]);
        });

        test('applyAll with no schedules returns zero', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $updated = Toggl::schedule('any')->applyAll($user);

            // Assert
            expect($updated)->toBe(0);
        });

        test('applyAll does not count unchanged features', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Feature already active
            Toggl::for($user)->activate('sale');

            // Save schedule that would activate it (already active)
            Toggl::schedule('sale')->between('-1 hour', '+1 hour')->save($user);

            // Act
            $updated = Toggl::schedule('any')->applyAll($user);

            // Assert
            expect($updated)->toBe(0); // No state change
        });

        test('applyAll skips non-array schedules', function (): void {
            // Arrange
            $user = User::factory()->create();
            $manager = app(FeatureManager::class);
            $driver = $manager->driver();

            // Manually corrupt schedules data with non-array entry
            $driver->for($user)->activate('__schedules__', [
                'invalid-1',
                ['feature' => 'valid', 'start_time' => now()->toIso8601String()],
                123,
            ]);

            // Act - Should skip invalid entries without error
            $updated = Toggl::schedule('any')->applyAll($user);

            // Assert - Should have processed only the valid entry
            expect($updated)->toBe(1);
        });

        test('applyAll skips schedules with non-string feature', function (): void {
            // Arrange
            $user = User::factory()->create();
            $manager = app(FeatureManager::class);
            $driver = $manager->driver();

            // Manually create schedule with non-string feature
            $driver->for($user)->activate('__schedules__', [
                ['feature' => 123, 'start_time' => now()->toIso8601String()],
                ['feature' => null, 'start_time' => now()->toIso8601String()],
                ['feature' => 'valid', 'start_time' => now()->toIso8601String()],
            ]);

            // Act - Should skip invalid entries without error
            $updated = Toggl::schedule('any')->applyAll($user);

            // Assert - Should have processed only the valid entry
            expect($updated)->toBe(1);
        });

        test('handles BackedEnum feature names', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Save schedule with BackedEnum (line 328 converts to string)
            Toggl::schedule(FeatureFlag::NewDashboard)
                ->between('-1 hour', '+1 hour')
                ->save($user);

            // Assert - Should save and retrieve successfully
            $schedules = Toggl::schedule('any')->listSchedules($user);
            expect($schedules)->not->toBeEmpty();
            expect($schedules[0]['feature'])->toBe('new-dashboard');
        });
    });
});
