<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * Basic Feature Flags Test Suite
 *
 * Tests fundamental feature flag operations including definition, activation,
 * deactivation, scoping, and multi-feature checks. Covers boolean flags,
 * closure-based resolvers, contextual features, and batch operations across
 * happy path, sad path, and edge case scenarios.
 */
describe('Basic Feature Flags', function (): void {
    describe('Happy Path', function (): void {
        test('can define a simple boolean feature', function (): void {
            // Arrange
            Toggl::define('simple-feature', true);

            // Act
            $isActive = Toggl::for(TogglContext::simple(1, 'test'))->active('simple-feature');

            // Assert
            expect($isActive)->toBeTrue();
        });

        test('can define a feature with a closure', function (): void {
            // Arrange
            Toggl::define('closure-feature', fn (): true => true);

            // Act
            $isActive = Toggl::for(TogglContext::simple(1, 'test'))->active('closure-feature');

            // Assert
            expect($isActive)->toBeTrue();
        });

        test('can check if feature is inactive', function (): void {
            // Arrange
            Toggl::define('inactive-feature', false);

            // Act
            $isInactive = Toggl::for(TogglContext::simple(1, 'test'))->inactive('inactive-feature');

            // Assert
            expect($isInactive)->toBeTrue();
        });

        test('can get feature value', function (): void {
            // Arrange
            Toggl::define('valued-feature', 'premium');

            // Act
            $value = Toggl::for(TogglContext::simple(1, 'test'))->value('valued-feature');

            // Assert
            expect($value)->toBe('premium');
        });

        test('can activate a feature', function (): void {
            // Arrange
            Toggl::define('toggleable-feature', false);

            // Act
            Toggl::activateForEveryone('toggleable-feature');

            // Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('toggleable-feature'))->toBeTrue();
        });

        test('can deactivate a feature', function (): void {
            // Arrange
            Toggl::define('active-feature', true);

            // Act
            Toggl::deactivateForEveryone('active-feature');

            // Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->inactive('active-feature'))->toBeTrue();
        });

        test('can use contextual features', function (): void {
            // Arrange
            $admin = TogglContext::simple('admin', 'user');
            $user = TogglContext::simple('user', 'user');
            Toggl::define('contextual-feature', fn (TogglContext $context): bool => $context->id === 'admin');

            // Act
            $isActiveForAdmin = Toggl::for($admin)->active('contextual-feature');
            $isActiveForUser = Toggl::for($user)->active('contextual-feature');

            // Assert
            expect($isActiveForAdmin)->toBeTrue();
            expect($isActiveForUser)->toBeFalse();
        });

        test('can activate feature for specific context', function (): void {
            // Arrange
            Toggl::define('user-feature', fn ($user): false => false);

            // Act
            Toggl::for(TogglContext::simple('user-123', 'test'))->activate('user-feature');

            // Assert
            expect(Toggl::for(TogglContext::simple('user-123', 'test'))->active('user-feature'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple('user-456', 'test'))->active('user-feature'))->toBeFalse();
        });

        test('can activate feature for everyone', function (): void {
            // Arrange
            Toggl::define('global-feature', false);

            // Act
            Toggl::activateForEveryone('global-feature');

            // Assert
            expect(Toggl::for(TogglContext::simple('user-1', 'test'))->active('global-feature'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple('user-2', 'test'))->active('global-feature'))->toBeTrue();
        });

        test('can deactivate feature for everyone', function (): void {
            // Arrange
            Toggl::define('global-active', true);
            Toggl::for(TogglContext::simple('user-1', 'test'))->activate('global-active');
            Toggl::for(TogglContext::simple('user-2', 'test'))->activate('global-active');

            // Act
            Toggl::deactivateForEveryone('global-active');

            // Assert
            expect(Toggl::for(TogglContext::simple('user-1', 'test'))->active('global-active'))->toBeFalse();
            expect(Toggl::for(TogglContext::simple('user-2', 'test'))->active('global-active'))->toBeFalse();
        });

        test('can check multiple features with allAreActive', function (): void {
            // Arrange
            Toggl::define('feature-a', true);
            Toggl::define('feature-b', true);
            Toggl::define('feature-c', false);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->allAreActive(['feature-a', 'feature-b']))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->allAreActive(['feature-a', 'feature-c']))->toBeFalse();
        });

        test('can check multiple features with someAreActive', function (): void {
            // Arrange
            Toggl::define('feature-x', true);
            Toggl::define('feature-y', false);
            Toggl::define('feature-z', false);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->someAreActive(['feature-x', 'feature-y']))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->someAreActive(['feature-y', 'feature-z']))->toBeFalse();
        });

        test('can forget feature value', function (): void {
            // Arrange
            Toggl::define('forgettable', fn (): true => true);
            Toggl::for(TogglContext::simple('user', 'test'))->activate('forgettable', 'custom-value');

            // Act
            Toggl::for(TogglContext::simple('user', 'test'))->forget('forgettable');
            $value = Toggl::for(TogglContext::simple('user', 'test'))->value('forgettable');

            // Assert
            expect($value)->toBeTrue(); // Returns resolver value after forgetting
        });

        test('can load features into memory', function (): void {
            // Arrange
            Toggl::define('loadable-1', true);
            Toggl::define('loadable-2', false);

            // Act
            $loaded = Toggl::for(TogglContext::simple(1, 'test'))->load(['loadable-1', 'loadable-2']);

            // Assert
            expect($loaded)->toHaveKey('loadable-1');
            expect($loaded)->toHaveKey('loadable-2');
        });

        test('can use when callback for active features', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('conditional', true);
            $executed = false;

            // Act
            Toggl::for($user)->when('conditional', function () use (&$executed): void {
                $executed = true;
            });

            // Assert
            expect($executed)->toBeTrue();
        });

        test('can use unless callback for inactive features', function (): void {
            // Arrange
            Toggl::define('disabled', false);
            $executed = false;

            // Act
            Toggl::for(TogglContext::simple(1, 'test'))->unless('disabled', function () use (&$executed): void {
                $executed = true;
            });

            // Assert
            expect($executed)->toBeTrue();
        });
    });

    describe('Sad Path', function (): void {
        test('returns false for undefined features', function (): void {
            // Act
            $isActive = Toggl::for(TogglContext::simple(1, 'test'))->active('undefined-feature');

            // Assert
            expect($isActive)->toBeFalse();
        });

        test('throws exception for null context', function (): void {
            // Arrange
            Toggl::define('null-context-feature', fn (): true => true);

            // Act & Assert
            expect(fn () => Toggl::for(null)->active('null-context-feature'))
                ->toThrow(RuntimeException::class, 'No context set for feature check');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles empty feature names', function (): void {
            // Arrange
            Toggl::define('', true);

            // Act
            $isActive = Toggl::for(TogglContext::simple(1, 'test'))->active('');

            // Assert
            expect($isActive)->toBeTrue();
        });

        test('handles numeric feature values', function (): void {
            // Arrange
            Toggl::define('numeric-feature', 42);

            // Act
            $value = Toggl::for(TogglContext::simple(1, 'test'))->value('numeric-feature');

            // Assert
            expect($value)->toBe(42);
        });

        test('handles array feature values', function (): void {
            // Arrange
            $config = ['option1' => true, 'option2' => false];
            Toggl::define('array-feature', $config);

            // Act
            $value = Toggl::for(TogglContext::simple(1, 'test'))->value('array-feature');

            // Assert
            expect($value)->toBe($config);
        });

        test('handles multiple contexts for same feature', function (): void {
            // Arrange
            Toggl::define('multi-context', fn (TogglContext $context): bool => $context->id > 100);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(50, 'test'))->active('multi-context'))->toBeFalse();
            expect(Toggl::for(TogglContext::simple(150, 'test'))->active('multi-context'))->toBeTrue();
        });

        test('can activate multiple features at once', function (): void {
            // Arrange
            Toggl::define('batch-1', false);
            Toggl::define('batch-2', false);

            // Act
            Toggl::activateForEveryone(['batch-1', 'batch-2']);

            // Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('batch-1'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('batch-2'))->toBeTrue();
        });

        test('can deactivate multiple features at once', function (): void {
            // Arrange
            Toggl::define('multi-1', true);
            Toggl::define('multi-2', true);

            // Act
            Toggl::deactivateForEveryone(['multi-1', 'multi-2']);

            // Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->inactive('multi-1'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->inactive('multi-2'))->toBeTrue();
        });
    });
});
