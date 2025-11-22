<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\LazilyResolvedFeature;
use Cline\Toggl\Toggl;
use Illuminate\Support\Facades\Date;

/**
 * Test suite for LazilyResolvedFeature functionality.
 *
 * Validates the behavior of feature flags that use lazy resolution with closures,
 * including expiration handling, dependency management, and fluent configuration
 * patterns. Tests cover resolver registration, time-based expiration logic, and
 * feature dependency chains.
 */
describe('LazilyResolvedFeature', function (): void {
    describe('Happy Path', function (): void {
        test('creates instance with name and resolver', function (): void {
            // Arrange & Act
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);

            // Assert
            expect($feature->getName())->toBe('test-feature');
            expect($feature->getResolver())->toBeCallable();
        });

        test('sets and retrieves expiration date', function (): void {
            // Arrange
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);
            $expiresAt = Date::now()->addDays(7);

            // Act
            $result = $feature->expiresAt($expiresAt);

            // Assert
            expect($result)->toBe($feature); // Fluent interface
            expect($feature->getExpiresAt())->toBe($expiresAt);
        });

        test('sets expiration using expiresAfter with days', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);

            // Act
            $feature->expiresAfter(days: 7);

            // Assert
            expect($feature->getExpiresAt()->toDateTimeString())->toBe('2024-01-08 12:00:00');

            // Cleanup
            Date::setTestNow();
        });

        test('sets expiration using expiresAfter with hours', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);

            // Act
            $feature->expiresAfter(hours: 24);

            // Assert
            expect($feature->getExpiresAt()->toDateTimeString())->toBe('2024-01-02 12:00:00');

            // Cleanup
            Date::setTestNow();
        });

        test('sets expiration using expiresAfter with minutes', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);

            // Act
            $feature->expiresAfter(minutes: 30);

            // Assert
            expect($feature->getExpiresAt()->toDateTimeString())->toBe('2024-01-01 12:30:00');

            // Cleanup
            Date::setTestNow();
        });

        test('sets expiration using expiresAfter with combined time', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);

            // Act
            $feature->expiresAfter(days: 1, hours: 2, minutes: 30);

            // Assert
            expect($feature->getExpiresAt()->toDateTimeString())->toBe('2024-01-02 14:30:00');

            // Cleanup
            Date::setTestNow();
        });

        test('sets and retrieves single dependency', function (): void {
            // Arrange
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);

            // Act
            $result = $feature->requires('required-feature');

            // Assert
            expect($result)->toBe($feature); // Fluent interface
            expect($feature->getRequires())->toBe(['required-feature']);
        });

        test('sets and retrieves multiple dependencies', function (): void {
            // Arrange
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);

            // Act
            $result = $feature->requires(['feature1', 'feature2', 'feature3']);

            // Assert
            expect($result)->toBe($feature); // Fluent interface
            expect($feature->getRequires())->toBe(['feature1', 'feature2', 'feature3']);
        });

        test('sets resolver using resolver method', function (): void {
            // Arrange
            $feature = new LazilyResolvedFeature('test-feature', fn (): false => false);
            $newResolver = fn (): true => true;

            // Act
            $feature->resolver($newResolver);

            // Assert
            expect($feature->getResolver())->toBe($newResolver);
        });

        test('resolver method registers with decorator when provided', function (): void {
            // Arrange
            $decorator = Toggl::driver();
            $feature = new LazilyResolvedFeature('test-feature', fn (): false => false, $decorator);

            // Act
            $feature->resolver(fn (): true => true);

            // Assert
            expect($decorator->defined())->toContain('test-feature');
        });

        test('isExpired returns false when no expiration set', function (): void {
            // Arrange
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);

            // Act & Assert
            expect($feature->isExpired())->toBeFalse();
        });

        test('isExpired returns false when expiration is in future', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);
            $feature->expiresAt(Date::now()->addDays(7));

            // Act & Assert
            expect($feature->isExpired())->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });

        test('isExpired returns true when expiration is in past', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);
            $feature->expiresAt(Date::now()->subDays(7));

            // Act & Assert
            expect($feature->isExpired())->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('isExpiringSoon returns false when no expiration set', function (): void {
            // Arrange
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);

            // Act & Assert
            expect($feature->isExpiringSoon(7))->toBeFalse(); // Line 191
        });

        test('isExpiringSoon returns true when expiring within specified days', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);
            $feature->expiresAt(Date::now()->addDays(3));

            // Act & Assert
            expect($feature->isExpiringSoon(7))->toBeTrue();

            // Cleanup
            Date::setTestNow();
        });

        test('isExpiringSoon returns false when expiring beyond specified days', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);
            $feature->expiresAt(Date::now()->addDays(30));

            // Act & Assert
            expect($feature->isExpiringSoon(7))->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });

        test('isExpiringSoon returns false when already expired', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);
            $feature->expiresAt(Date::now()->subDays(1));

            // Act & Assert
            expect($feature->isExpiringSoon(7))->toBeFalse();

            // Cleanup
            Date::setTestNow();
        });

        test('getName returns feature name', function (): void {
            // Arrange
            $feature = new LazilyResolvedFeature('my-feature', fn (): true => true);

            // Act & Assert
            expect($feature->getName())->toBe('my-feature'); // Line 205
        });
    });

    describe('Edge Cases', function (): void {
        test('handles static value resolver', function (): void {
            // Arrange & Act
            $feature = new LazilyResolvedFeature('test-feature', 'static-value');

            // Assert
            expect($feature->getResolver())->toBe('static-value');
        });

        test('fluent API allows method chaining', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $decorator = Toggl::driver();
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true, $decorator);

            // Act
            $feature
                ->expiresAfter(days: 7)
                ->requires(['feature1', 'feature2']);

            // Assert
            expect($feature->getExpiresAt())->not->toBeNull();
            expect($feature->getRequires())->toBe(['feature1', 'feature2']);

            // Cleanup
            Date::setTestNow();
        });

        test('expiresAfter with zero values does not change time', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);

            // Act
            $feature->expiresAfter(days: 0, hours: 0, minutes: 0);

            // Assert
            expect($feature->getExpiresAt()->toDateTimeString())->toBe('2024-01-01 12:00:00');

            // Cleanup
            Date::setTestNow();
        });

        test('requires with empty string array creates empty dependency list', function (): void {
            // Arrange
            $feature = new LazilyResolvedFeature('test-feature', fn (): true => true);

            // Act
            $feature->requires([]);

            // Assert
            expect($feature->getRequires())->toBe([]);
        });
    });
});
