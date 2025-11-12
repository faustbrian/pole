<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Feature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

describe('Time Bombs', function (): void {
    beforeEach(function (): void {
        // Set a consistent "now" for testing
        Date::setTestNow('2025-01-15 12:00:00');
    });

    afterEach(function (): void {
        Date::setTestNow();
    });

    describe('Happy Path', function (): void {
        test('can define a feature with expiration date', function (): void {
            // Arrange & Act
            Feature::define('black-friday-sale')
                ->expiresAt(now()->addDays(7))
                ->resolver(fn (): true => true);

            // Assert
            expect(Feature::expiresAt('black-friday-sale'))
                ->toBeInstanceOf(Carbon::class)
                ->and(Feature::expiresAt('black-friday-sale')->toDateString())
                ->toBe('2025-01-22');
        });

        test('can define a feature with relative expiration', function (): void {
            // Arrange & Act
            Feature::define('temp-experiment')
                ->expiresAfter(days: 14)
                ->resolver(fn (): true => true);

            // Assert
            expect(Feature::expiresAt('temp-experiment'))
                ->toBeInstanceOf(Carbon::class)
                ->and(Feature::expiresAt('temp-experiment')->toDateString())
                ->toBe('2025-01-29');
        });

        test('non-expired feature returns resolver value', function (): void {
            // Arrange
            Feature::define('active-promo')
                ->expiresAt(now()->addDays(5))
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::active('active-promo'))->toBeTrue();
        });

        test('expired feature returns false regardless of resolver', function (): void {
            // Arrange
            Feature::define('expired-promo')
                ->expiresAt(now()->subDays(1))
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::active('expired-promo'))->toBeFalse();
        });

        test('can check if feature is expired', function (): void {
            // Arrange
            Feature::define('expired-feature')
                ->expiresAt(now()->subDays(1))
                ->resolver(fn (): true => true);

            Feature::define('active-feature')
                ->expiresAt(now()->addDays(7))
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::isExpired('expired-feature'))->toBeTrue();
            expect(Feature::isExpired('active-feature'))->toBeFalse();
        });

        test('can check if feature is expiring soon', function (): void {
            // Arrange
            Feature::define('expiring-soon')
                ->expiresAt(now()->addDays(2))
                ->resolver(fn (): true => true);

            Feature::define('not-expiring-soon')
                ->expiresAt(now()->addDays(10))
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::isExpiringSoon('expiring-soon', 3))->toBeTrue();
            expect(Feature::isExpiringSoon('not-expiring-soon', 3))->toBeFalse();
        });

        test('can get list of expiring soon features', function (): void {
            // Arrange
            Feature::define('expires-tomorrow')
                ->expiresAt(now()->addDays(1))
                ->resolver(fn (): true => true);

            Feature::define('expires-next-week')
                ->expiresAt(now()->addDays(10))
                ->resolver(fn (): true => true);

            Feature::define('no-expiration', fn (): true => true);

            // Act
            $expiringSoon = Feature::expiringSoon(days: 3);

            // Assert
            expect($expiringSoon)
                ->toContain('expires-tomorrow')
                ->not->toContain('expires-next-week')
                ->not->toContain('no-expiration');
        });

        test('can define feature with hours and minutes', function (): void {
            // Arrange & Act
            Feature::define('short-lived')
                ->expiresAfter(hours: 2, minutes: 30)
                ->resolver(fn (): true => true);

            // Assert
            $expiresAt = Feature::expiresAt('short-lived');
            expect($expiresAt->format('Y-m-d H:i:s'))->toBe('2025-01-15 14:30:00');
        });

        test('can combine days, hours, and minutes', function (): void {
            // Arrange & Act
            Feature::define('combined-expiry')
                ->expiresAfter(days: 1, hours: 2, minutes: 30)
                ->resolver(fn (): true => true);

            // Assert
            $expiresAt = Feature::expiresAt('combined-expiry');
            expect($expiresAt->format('Y-m-d H:i:s'))->toBe('2025-01-16 14:30:00');
        });

        test('feature without expiration never expires', function (): void {
            // Arrange
            Feature::define('permanent', fn (): true => true);

            // Act & Assert
            expect(Feature::isExpired('permanent'))->toBeFalse();
            expect(Feature::expiresAt('permanent'))->toBeNull();
        });
    });

    describe('Sad Path', function (): void {
        test('returns null for expiration on undefined feature', function (): void {
            // Act & Assert
            expect(Feature::expiresAt('undefined'))->toBeNull();
        });

        test('returns false for isExpired on undefined feature', function (): void {
            // Act & Assert
            expect(Feature::isExpired('undefined'))->toBeFalse();
        });

        test('returns false for isExpiringSoon on undefined feature', function (): void {
            // Act & Assert
            expect(Feature::isExpiringSoon('undefined', 7))->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('feature expiring exactly now is considered expired', function (): void {
            // Arrange
            Feature::define('expires-now')
                ->expiresAt(now())
                ->resolver(fn (): true => true);

            // Move time forward by 1 second
            Date::setTestNow(now()->addSecond());

            // Act & Assert
            expect(Feature::isExpired('expires-now'))->toBeTrue();
            expect(Feature::active('expires-now'))->toBeFalse();
        });

        test('already expired feature is not expiring soon', function (): void {
            // Arrange
            Feature::define('already-expired')
                ->expiresAt(now()->subDays(5))
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::isExpiringSoon('already-expired', 7))->toBeFalse();
        });

        test('can redefine feature with different expiration', function (): void {
            // Arrange
            Feature::define('mutable')
                ->expiresAt(now()->addDays(1))
                ->resolver(fn (): true => true);

            // Act
            Feature::define('mutable')
                ->expiresAt(now()->addDays(10))
                ->resolver(fn (): true => true);

            // Assert
            expect(Feature::expiresAt('mutable')->toDateString())->toBe('2025-01-25');
        });

        test('expiringSoon returns empty array when no features expiring', function (): void {
            // Arrange
            Feature::define('permanent', fn (): true => true);

            // Act
            $expiring = Feature::expiringSoon(days: 7);

            // Assert
            expect($expiring)->toBeEmpty();
        });

        test('scoped features with expiration work correctly', function (): void {
            // Arrange
            Feature::define('user-promo')
                ->expiresAt(now()->addDays(1))
                ->resolver(fn ($user): bool => $user === 'premium');

            // Act & Assert
            expect(Feature::for('premium')->active('user-promo'))->toBeTrue();
            expect(Feature::for('basic')->active('user-promo'))->toBeFalse();

            // Fast forward past expiration
            Date::setTestNow(now()->addDays(2));

            expect(Feature::for('premium')->active('user-promo'))->toBeFalse();
        });
    });
});
