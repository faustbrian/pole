<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Strategies\TimeBasedStrategy;
use Illuminate\Support\Facades\Date;

/**
 * Tests for TimeBasedStrategy implementation.
 *
 * Validates time-based feature flag behavior including:
 * - Time window validation (before, within, after range)
 * - Boundary conditions (exact start/end times, one-second precision)
 * - Edge cases (short windows, same start/end, multi-year ranges)
 * - Timezone handling (aware dates, UTC conversions, DST boundaries)
 * - Context independence (null context support, parameter ignored)
 *
 * All tests use Date::setTestNow() to freeze time for deterministic results.
 */
describe('TimeBasedStrategy', function (): void {
    describe('Happy Path', function (): void {
        test('resolves to true when current time is between start and end', function (): void {
            // Arrange
            Date::setTestNow('2024-06-15 12:00:00');
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00'),
                end: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to true at exact start time', function (): void {
            // Arrange
            $startTime = Date::parse('2024-01-01 00:00:00');
            Date::setTestNow($startTime);
            $strategy = new TimeBasedStrategy(
                start: $startTime,
                end: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to true at exact end time', function (): void {
            // Arrange
            $endTime = Date::parse('2024-12-31 23:59:59');
            Date::setTestNow($endTime);
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00'),
                end: $endTime,
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('can handle null context', function (): void {
            // Arrange
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00'),
                end: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $canHandle = $strategy->canHandleNullContext();

            // Assert
            expect($canHandle)->toBeTrue();
        });

        test('ignores context parameter', function (): void {
            // Arrange
            Date::setTestNow('2024-06-15 12:00:00');
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00'),
                end: Date::parse('2024-12-31 23:59:59'),
            );

            // Act & Assert
            expect($strategy->resolve(null))->toBeTrue();
            expect($strategy->resolve('user'))->toBeTrue();
            expect($strategy->resolve(123))->toBeTrue();
            expect($strategy->resolve(
                new stdClass(),
            ))->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });
    });

    describe('Sad Path', function (): void {
        test('resolves to false when current time is before start', function (): void {
            // Arrange
            Date::setTestNow('2023-12-31 23:59:59');
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00'),
                end: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to false when current time is after end', function (): void {
            // Arrange
            Date::setTestNow('2025-01-01 00:00:00');
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00'),
                end: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to false one second before start time', function (): void {
            // Arrange
            $startTime = Date::parse('2024-01-01 00:00:00');
            Date::setTestNow($startTime->copy()->subSecond());
            $strategy = new TimeBasedStrategy(
                start: $startTime,
                end: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to false one second after end time', function (): void {
            // Arrange
            $endTime = Date::parse('2024-12-31 23:59:59');
            Date::setTestNow($endTime->copy()->addSecond());
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00'),
                end: $endTime,
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });
    });

    describe('Edge Cases', function (): void {
        test('handles very short time window', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 11:59:59'),
                end: Date::parse('2024-01-01 12:00:01'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('handles one-second time window at start', function (): void {
            // Arrange
            $startTime = Date::parse('2024-01-01 12:00:00');
            Date::setTestNow($startTime);
            $strategy = new TimeBasedStrategy(
                start: $startTime,
                end: $startTime->copy()->addSecond(),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('handles one-second time window at end', function (): void {
            // Arrange
            $endTime = Date::parse('2024-01-01 12:00:01');
            Date::setTestNow($endTime);
            $strategy = new TimeBasedStrategy(
                start: $endTime->copy()->subSecond(),
                end: $endTime,
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('handles same start and end time', function (): void {
            // Arrange
            $time = Date::parse('2024-01-01 12:00:00');
            Date::setTestNow($time);
            $strategy = new TimeBasedStrategy(
                start: $time,
                end: $time,
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('handles long time range spanning multiple years', function (): void {
            // Arrange
            Date::setTestNow('2025-06-15 12:00:00');
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2020-01-01 00:00:00'),
                end: Date::parse('2030-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('handles timezone aware times', function (): void {
            // Arrange
            Date::setTestNow('2024-06-15 12:00:00');
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00', 'America/New_York'),
                end: Date::parse('2024-12-31 23:59:59', 'America/Los_Angeles'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to false when timezone causes time to be outside range', function (): void {
            // Arrange - Set current time to be outside the range when considering timezones
            Date::setTestNow('2024-01-01 03:00:00'); // 3 AM UTC
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00', 'America/Los_Angeles'), // 8 AM UTC
                end: Date::parse('2024-01-01 23:59:59', 'America/Los_Angeles'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });

        test('handles midnight boundary', function (): void {
            // Arrange
            Date::setTestNow('2024-06-15 00:00:00');
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00'),
                end: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('handles end of day boundary', function (): void {
            // Arrange
            Date::setTestNow('2024-06-15 23:59:59');
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00'),
                end: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('returns consistent results for multiple calls in same time window', function (): void {
            // Arrange
            Date::setTestNow('2024-06-15 12:00:00');
            $strategy = new TimeBasedStrategy(
                start: Date::parse('2024-01-01 00:00:00'),
                end: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result1 = $strategy->resolve(null);
            $result2 = $strategy->resolve('user');
            $result3 = $strategy->resolve(123);

            // Assert
            expect($result1)->toBeTrue();
            expect($result2)->toBeTrue();
            expect($result3)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });
    });
});
