<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Strategies\ScheduledStrategy;
use Illuminate\Support\Facades\Date;

/**
 * Test suite for ScheduledStrategy functionality.
 *
 * Validates time-based feature flag activation and deactivation using scheduled
 * timestamps. Tests activation windows, boundary conditions at exact activation/
 * deactivation times, timezone handling, and context-independence. Features can be
 * configured to activate at a specific time, deactivate at a specific time, or
 * operate within a defined time window between activation and deactivation.
 */
describe('ScheduledStrategy', function (): void {
    describe('Happy Path', function (): void {
        test('resolves to true when no activation or deactivation time is set', function (): void {
            // Arrange
            $strategy = new ScheduledStrategy();

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();
        });

        test('resolves to true when only activation time is set and time has passed', function (): void {
            // Arrange
            Date::setTestNow('2024-01-15 12:00:00');
            $strategy = new ScheduledStrategy(
                activateAt: Date::parse('2024-01-01 00:00:00'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to true when only deactivation time is set and time has not passed', function (): void {
            // Arrange
            Date::setTestNow('2024-01-15 12:00:00');
            $strategy = new ScheduledStrategy(
                deactivateAt: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to true when current time is between activation and deactivation', function (): void {
            // Arrange
            Date::setTestNow('2024-06-15 12:00:00');
            $strategy = new ScheduledStrategy(
                activateAt: Date::parse('2024-01-01 00:00:00'),
                deactivateAt: Date::parse('2024-12-31 23:59:59'),
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
            $strategy = new ScheduledStrategy();

            // Act
            $canHandle = $strategy->canHandleNullContext();

            // Assert
            expect($canHandle)->toBeTrue();
        });

        test('ignores context parameter', function (): void {
            // Arrange
            Date::setTestNow('2024-06-15 12:00:00');
            $strategy = new ScheduledStrategy(
                activateAt: Date::parse('2024-01-01 00:00:00'),
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
        test('resolves to false when activation time has not been reached', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 00:00:00');
            $strategy = new ScheduledStrategy(
                activateAt: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to false when deactivation time has passed', function (): void {
            // Arrange
            Date::setTestNow('2024-12-31 23:59:59');
            $strategy = new ScheduledStrategy(
                deactivateAt: Date::parse('2024-01-01 00:00:00'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to false when before activation time even with deactivation time set', function (): void {
            // Arrange
            Date::setTestNow('2023-12-31 23:59:59');
            $strategy = new ScheduledStrategy(
                activateAt: Date::parse('2024-01-01 00:00:00'),
                deactivateAt: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to false when after deactivation time even with activation time set', function (): void {
            // Arrange
            Date::setTestNow('2025-01-01 00:00:00');
            $strategy = new ScheduledStrategy(
                activateAt: Date::parse('2024-01-01 00:00:00'),
                deactivateAt: Date::parse('2024-12-31 23:59:59'),
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
        test('resolves to true exactly at activation time', function (): void {
            // Arrange
            $activationTime = Date::parse('2024-01-01 00:00:00');
            Date::setTestNow($activationTime);
            $strategy = new ScheduledStrategy(
                activateAt: $activationTime,
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to true one second before deactivation time', function (): void {
            // Arrange
            $deactivationTime = Date::parse('2024-12-31 23:59:59');
            Date::setTestNow($deactivationTime->copy()->subSecond());
            $strategy = new ScheduledStrategy(
                deactivateAt: $deactivationTime,
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to false one second before activation time', function (): void {
            // Arrange
            $activationTime = Date::parse('2024-01-01 00:00:00');
            Date::setTestNow($activationTime->copy()->subSecond());
            $strategy = new ScheduledStrategy(
                activateAt: $activationTime,
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });

        test('resolves to true exactly at deactivation time', function (): void {
            // Arrange
            $deactivationTime = Date::parse('2024-12-31 23:59:59');
            Date::setTestNow($deactivationTime);
            $strategy = new ScheduledStrategy(
                deactivateAt: $deactivationTime,
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert - At exact time, isAfter returns false, so feature is still active
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('handles very short time window', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $strategy = new ScheduledStrategy(
                activateAt: Date::parse('2024-01-01 11:59:59'),
                deactivateAt: Date::parse('2024-01-01 12:00:01'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('handles immediate activation with future deactivation', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $strategy = new ScheduledStrategy(
                activateAt: null, // Already active
                deactivateAt: Date::parse('2024-12-31 23:59:59'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('handles past activation with no deactivation', function (): void {
            // Arrange
            Date::setTestNow('2024-06-15 12:00:00');
            $strategy = new ScheduledStrategy(
                activateAt: Date::parse('2024-01-01 00:00:00'), // Never deactivates
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
            $strategy = new ScheduledStrategy(
                activateAt: Date::parse('2024-01-01 00:00:00', 'America/New_York'),
                deactivateAt: Date::parse('2024-12-31 23:59:59', 'America/Los_Angeles'),
            );

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });
    });
});
