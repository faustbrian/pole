<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Feature;

describe('Feature Dependencies', function (): void {
    describe('Happy Path', function (): void {
        test('can define a feature with a single dependency', function (): void {
            // Arrange
            Feature::define('basic-analytics', fn (): true => true);
            Feature::define('advanced-analytics')
                ->requires('basic-analytics')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::getDependencies('advanced-analytics'))->toBe(['basic-analytics']);
        });

        test('feature is active when dependency is met', function (): void {
            // Arrange
            Feature::define('user-auth', true);
            Feature::define('premium-dashboard')
                ->requires('user-auth')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::active('premium-dashboard'))->toBeTrue();
        });

        test('feature is inactive when dependency is not met', function (): void {
            // Arrange
            Feature::define('user-auth', false);
            Feature::define('premium-dashboard')
                ->requires('user-auth')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::active('premium-dashboard'))->toBeFalse();
        });

        test('can define feature with multiple dependencies', function (): void {
            // Arrange
            Feature::define('basic-feature', true);
            Feature::define('intermediate-feature', true);
            Feature::define('advanced-feature')
                ->requires(['basic-feature', 'intermediate-feature'])
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::getDependencies('advanced-feature'))
                ->toBe(['basic-feature', 'intermediate-feature']);
            expect(Feature::active('advanced-feature'))->toBeTrue();
        });

        test('feature is inactive when any dependency is not met', function (): void {
            // Arrange
            Feature::define('feature-1', true);
            Feature::define('feature-2', false);
            Feature::define('feature-3', true);
            Feature::define('requires-all')
                ->requires(['feature-1', 'feature-2', 'feature-3'])
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::active('requires-all'))->toBeFalse();
        });

        test('dependencies work with scoped features', function (): void {
            // Arrange
            Feature::define('subscription', fn ($user): bool => $user === 'premium');
            Feature::define('premium-support')
                ->requires('subscription')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::for('premium')->active('premium-support'))->toBeTrue();
            expect(Feature::for('basic')->active('premium-support'))->toBeFalse();
        });

        test('can check if dependencies are met', function (): void {
            // Arrange
            Feature::define('dep-1', true);
            Feature::define('dep-2', false);
            Feature::define('with-met-deps')
                ->requires('dep-1')
                ->resolver(fn (): true => true);
            Feature::define('with-unmet-deps')
                ->requires('dep-2')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::dependenciesMet('with-met-deps'))->toBeTrue();
            expect(Feature::dependenciesMet('with-unmet-deps'))->toBeFalse();
        });

        test('feature without dependencies always has dependencies met', function (): void {
            // Arrange
            Feature::define('independent', fn (): true => true);

            // Act & Assert
            expect(Feature::getDependencies('independent'))->toBeEmpty();
            expect(Feature::dependenciesMet('independent'))->toBeTrue();
        });
    });

    describe('Sad Path', function (): void {
        test('returns empty array for dependencies of undefined feature', function (): void {
            // Act & Assert
            expect(Feature::getDependencies('undefined'))->toBeEmpty();
        });

        test('returns true for dependenciesMet of undefined feature', function (): void {
            // Act & Assert - undefined feature has no dependencies, so they're "met"
            expect(Feature::dependenciesMet('undefined'))->toBeTrue();
        });

        test('feature with undefined dependency is inactive', function (): void {
            // Arrange
            Feature::define('depends-on-nothing')
                ->requires('non-existent-feature')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::active('depends-on-nothing'))->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('circular dependencies are handled correctly', function (): void {
            // Arrange
            Feature::define('feature-a')
                ->requires('feature-b')
                ->resolver(fn (): true => true);

            Feature::define('feature-b')
                ->requires('feature-a')
                ->resolver(fn (): true => true);

            // Act & Assert - both should be false due to circular dependency
            expect(Feature::active('feature-a'))->toBeFalse();
            expect(Feature::active('feature-b'))->toBeFalse();
        });

        test('transitive dependencies work correctly', function (): void {
            // Arrange
            Feature::define('level-1', true);
            Feature::define('level-2')
                ->requires('level-1')
                ->resolver(fn (): true => true);
            Feature::define('level-3')
                ->requires('level-2')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Feature::active('level-3'))->toBeTrue();

            // Now disable level-1
            Feature::deactivate('level-1');

            // All should be false now
            expect(Feature::active('level-1'))->toBeFalse();
            expect(Feature::active('level-2'))->toBeFalse();
            expect(Feature::active('level-3'))->toBeFalse();
        });

        test('dependencies combined with expiration', function (): void {
            // Arrange
            Feature::define('base-feature', true);
            Feature::define('expiring-dependent')
                ->requires('base-feature')
                ->expiresAt(now()->addDays(1))
                ->resolver(fn (): true => true);

            // Act & Assert - should be true (dependency met and not expired)
            expect(Feature::active('expiring-dependent'))->toBeTrue();

            // Disable base feature
            Feature::deactivate('base-feature');

            // Should be false (dependency not met)
            expect(Feature::active('expiring-dependent'))->toBeFalse();
        });

        test('can activate feature even when dependency is not met', function (): void {
            // Arrange
            Feature::define('dependency', false);
            Feature::define('dependent')
                ->requires('dependency')
                ->resolver(fn (): false => false);

            // Act - explicitly activate
            Feature::activate('dependent');

            // Assert - activation overrides resolver but dependencies still checked
            expect(Feature::active('dependent'))->toBeFalse();
        });

        test('dependencies work with activateForEveryone', function (): void {
            // Arrange
            Feature::define('base', false);
            Feature::define('depends-on-base')
                ->requires('base')
                ->resolver(fn (): true => true);

            // Act
            Feature::activateForEveryone('base');

            // Assert
            expect(Feature::for('user-1')->active('depends-on-base'))->toBeTrue();
            expect(Feature::for('user-2')->active('depends-on-base'))->toBeTrue();
        });
    });
});
