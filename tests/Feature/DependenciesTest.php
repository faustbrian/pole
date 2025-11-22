<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;

/**
 * Feature Dependencies Test Suite
 *
 * Tests feature dependency relationships where features can require other
 * features to be active. Covers single and multiple dependencies, transitive
 * dependency chains, circular dependency handling, dependency checking with
 * contextual features, and interactions with time-based expiration.
 */
describe('Feature Dependencies', function (): void {
    describe('Happy Path', function (): void {
        test('can define a feature with a single dependency', function (): void {
            // Arrange
            Toggl::define('basic-analytics', fn (): true => true);
            Toggl::define('advanced-analytics')
                ->requires('basic-analytics')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Toggl::getDependencies('advanced-analytics'))->toBe(['basic-analytics']);
        });

        test('feature is active when dependency is met', function (): void {
            // Arrange
            Toggl::define('user-auth', true);
            Toggl::define('premium-dashboard')
                ->requires('user-auth')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('premium-dashboard'))->toBeTrue();
        });

        test('feature is inactive when dependency is not met', function (): void {
            // Arrange
            Toggl::define('user-auth', false);
            Toggl::define('premium-dashboard')
                ->requires('user-auth')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('premium-dashboard'))->toBeFalse();
        });

        test('can define feature with multiple dependencies', function (): void {
            // Arrange
            Toggl::define('basic-feature', true);
            Toggl::define('intermediate-feature', true);
            Toggl::define('advanced-feature')
                ->requires(['basic-feature', 'intermediate-feature'])
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Toggl::getDependencies('advanced-feature'))
                ->toBe(['basic-feature', 'intermediate-feature']);
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('advanced-feature'))->toBeTrue();
        });

        test('feature is inactive when any dependency is not met', function (): void {
            // Arrange
            Toggl::define('feature-1', true);
            Toggl::define('feature-2', false);
            Toggl::define('feature-3', true);
            Toggl::define('requires-all')
                ->requires(['feature-1', 'feature-2', 'feature-3'])
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('requires-all'))->toBeFalse();
        });

        test('dependencies work with contextual features', function (): void {
            // Arrange
            Toggl::define('subscription', fn (TogglContext $context): bool => $context->id === 'premium');
            Toggl::define('premium-support')
                ->requires('subscription')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple('premium', 'test'))->active('premium-support'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple('basic', 'test'))->active('premium-support'))->toBeFalse();
        });

        test('can check if dependencies are met', function (): void {
            // Arrange
            $context = TogglContext::simple(1, 'test');
            Toggl::define('dep-1', true);
            Toggl::define('dep-2', false);
            Toggl::define('with-met-deps')
                ->requires('dep-1')
                ->resolver(fn (): true => true);
            Toggl::define('with-unmet-deps')
                ->requires('dep-2')
                ->resolver(fn (): true => true);

            // Act - activate dep-1 for context
            Toggl::activate('dep-1')->for($context);

            // Assert - feature with met deps is active, feature with unmet deps is not
            expect(Toggl::for($context)->active('with-met-deps'))->toBeTrue();
            expect(Toggl::for($context)->active('with-unmet-deps'))->toBeFalse();
        });

        test('feature without dependencies always has dependencies met', function (): void {
            // Arrange
            Toggl::define('independent', fn (): true => true);

            // Act & Assert
            expect(Toggl::getDependencies('independent'))->toBeEmpty();
            expect(Toggl::dependenciesMet('independent'))->toBeTrue();
        });
    });

    describe('Sad Path', function (): void {
        test('returns empty array for dependencies of undefined feature', function (): void {
            // Act & Assert
            expect(Toggl::getDependencies('undefined'))->toBeEmpty();
        });

        test('returns true for dependenciesMet of undefined feature', function (): void {
            // Act & Assert - undefined feature has no dependencies, so they're "met"
            expect(Toggl::dependenciesMet('undefined'))->toBeTrue();
        });

        test('feature with undefined dependency is inactive', function (): void {
            // Arrange
            Toggl::define('depends-on-nothing')
                ->requires('non-existent-feature')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('depends-on-nothing'))->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('circular dependencies are handled correctly', function (): void {
            // Arrange
            Toggl::define('feature-a')
                ->requires('feature-b')
                ->resolver(fn (): true => true);

            Toggl::define('feature-b')
                ->requires('feature-a')
                ->resolver(fn (): true => true);

            // Act & Assert - both should be false due to circular dependency
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('feature-a'))->toBeFalse();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('feature-b'))->toBeFalse();
        });

        test('transitive dependencies work correctly', function (): void {
            // Arrange
            Toggl::define('level-1', true);
            Toggl::define('level-2')
                ->requires('level-1')
                ->resolver(fn (): true => true);
            Toggl::define('level-3')
                ->requires('level-2')
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('level-3'))->toBeTrue();

            // Now disable level-1
            Toggl::deactivateForEveryone('level-1');

            // All should be false now
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('level-1'))->toBeFalse();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('level-2'))->toBeFalse();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('level-3'))->toBeFalse();
        });

        test('dependencies combined with expiration', function (): void {
            // Arrange
            Toggl::define('base-feature', true);
            Toggl::define('expiring-dependent')
                ->requires('base-feature')
                ->expiresAt(now()->addDays(1))
                ->resolver(fn (): true => true);

            // Act & Assert - should be true (dependency met and not expired)
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('expiring-dependent'))->toBeTrue();

            // Disable base feature
            Toggl::deactivateForEveryone('base-feature');

            // Should be false (dependency not met)
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('expiring-dependent'))->toBeFalse();
        });

        test('can activate feature even when dependency is not met', function (): void {
            // Arrange
            Toggl::define('dependency', false);
            Toggl::define('dependent')
                ->requires('dependency')
                ->resolver(fn (): false => false);

            // Act - explicitly activate
            Toggl::activateForEveryone('dependent');

            // Assert - activation overrides resolver but dependencies still checked
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('dependent'))->toBeFalse();
        });

        test('dependencies work with activateForEveryone', function (): void {
            // Arrange
            Toggl::define('base', false);
            Toggl::define('depends-on-base')
                ->requires('base')
                ->resolver(fn (): true => true);

            // Act
            Toggl::activateForEveryone('base');

            // Assert
            expect(Toggl::for(TogglContext::simple('user-1', 'test'))->active('depends-on-base'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple('user-2', 'test'))->active('depends-on-base'))->toBeTrue();
        });
    });
});
