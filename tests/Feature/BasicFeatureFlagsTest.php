<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Feature;

describe('Basic Feature Flags', function (): void {
    describe('Happy Path', function (): void {
        test('can define a simple boolean feature', function (): void {
            // Arrange
            Feature::define('simple-feature', true);

            // Act
            $isActive = Feature::active('simple-feature');

            // Assert
            expect($isActive)->toBeTrue();
        });

        test('can define a feature with a closure', function (): void {
            // Arrange
            Feature::define('closure-feature', fn (): true => true);

            // Act
            $isActive = Feature::active('closure-feature');

            // Assert
            expect($isActive)->toBeTrue();
        });

        test('can check if feature is inactive', function (): void {
            // Arrange
            Feature::define('inactive-feature', false);

            // Act
            $isInactive = Feature::inactive('inactive-feature');

            // Assert
            expect($isInactive)->toBeTrue();
        });

        test('can get feature value', function (): void {
            // Arrange
            Feature::define('valued-feature', 'premium');

            // Act
            $value = Feature::value('valued-feature');

            // Assert
            expect($value)->toBe('premium');
        });

        test('can activate a feature', function (): void {
            // Arrange
            Feature::define('toggleable-feature', false);

            // Act
            Feature::activate('toggleable-feature');

            // Assert
            expect(Feature::active('toggleable-feature'))->toBeTrue();
        });

        test('can deactivate a feature', function (): void {
            // Arrange
            Feature::define('active-feature', true);

            // Act
            Feature::deactivate('active-feature');

            // Assert
            expect(Feature::inactive('active-feature'))->toBeTrue();
        });

        test('can use scoped features', function (): void {
            // Arrange
            Feature::define('scoped-feature', fn ($user): bool => $user === 'admin');

            // Act
            $isActiveForAdmin = Feature::for('admin')->active('scoped-feature');
            $isActiveForUser = Feature::for('user')->active('scoped-feature');

            // Assert
            expect($isActiveForAdmin)->toBeTrue();
            expect($isActiveForUser)->toBeFalse();
        });

        test('can activate feature for specific scope', function (): void {
            // Arrange
            Feature::define('user-feature', fn ($user): false => false);

            // Act
            Feature::for('user-123')->activate('user-feature');

            // Assert
            expect(Feature::for('user-123')->active('user-feature'))->toBeTrue();
            expect(Feature::for('user-456')->active('user-feature'))->toBeFalse();
        });

        test('can activate feature for everyone', function (): void {
            // Arrange
            Feature::define('global-feature', false);

            // Act
            Feature::activateForEveryone('global-feature');

            // Assert
            expect(Feature::for('user-1')->active('global-feature'))->toBeTrue();
            expect(Feature::for('user-2')->active('global-feature'))->toBeTrue();
        });

        test('can deactivate feature for everyone', function (): void {
            // Arrange
            Feature::define('global-active', true);
            Feature::for('user-1')->activate('global-active');
            Feature::for('user-2')->activate('global-active');

            // Act
            Feature::deactivateForEveryone('global-active');

            // Assert
            expect(Feature::for('user-1')->active('global-active'))->toBeFalse();
            expect(Feature::for('user-2')->active('global-active'))->toBeFalse();
        });

        test('can check multiple features with allAreActive', function (): void {
            // Arrange
            Feature::define('feature-a', true);
            Feature::define('feature-b', true);
            Feature::define('feature-c', false);

            // Act & Assert
            expect(Feature::allAreActive(['feature-a', 'feature-b']))->toBeTrue();
            expect(Feature::allAreActive(['feature-a', 'feature-c']))->toBeFalse();
        });

        test('can check multiple features with someAreActive', function (): void {
            // Arrange
            Feature::define('feature-x', true);
            Feature::define('feature-y', false);
            Feature::define('feature-z', false);

            // Act & Assert
            expect(Feature::someAreActive(['feature-x', 'feature-y']))->toBeTrue();
            expect(Feature::someAreActive(['feature-y', 'feature-z']))->toBeFalse();
        });

        test('can forget feature value', function (): void {
            // Arrange
            Feature::define('forgettable', fn (): true => true);
            Feature::for('user')->activate('forgettable', 'custom-value');

            // Act
            Feature::for('user')->forget('forgettable');
            $value = Feature::for('user')->value('forgettable');

            // Assert
            expect($value)->toBeTrue(); // Returns resolver value after forgetting
        });

        test('can load features into memory', function (): void {
            // Arrange
            Feature::define('loadable-1', true);
            Feature::define('loadable-2', false);

            // Act
            $loaded = Feature::load(['loadable-1', 'loadable-2']);

            // Assert
            expect($loaded)->toHaveKey('loadable-1');
            expect($loaded)->toHaveKey('loadable-2');
        });

        test('can use when callback for active features', function (): void {
            // Arrange
            Feature::define('conditional', true);
            $executed = false;

            // Act
            Feature::when('conditional', function () use (&$executed): void {
                $executed = true;
            });

            // Assert
            expect($executed)->toBeTrue();
        });

        test('can use unless callback for inactive features', function (): void {
            // Arrange
            Feature::define('disabled', false);
            $executed = false;

            // Act
            Feature::unless('disabled', function () use (&$executed): void {
                $executed = true;
            });

            // Assert
            expect($executed)->toBeTrue();
        });
    });

    describe('Sad Path', function (): void {
        test('returns false for undefined features', function (): void {
            // Act
            $isActive = Feature::active('undefined-feature');

            // Assert
            expect($isActive)->toBeFalse();
        });

        test('handles null scope gracefully', function (): void {
            // Arrange
            Feature::define('null-scope-feature', fn (): true => true);

            // Act
            $isActive = Feature::for(null)->active('null-scope-feature');

            // Assert
            expect($isActive)->toBeTrue();
        });
    });

    describe('Edge Cases', function (): void {
        test('handles empty feature names', function (): void {
            // Arrange
            Feature::define('', true);

            // Act
            $isActive = Feature::active('');

            // Assert
            expect($isActive)->toBeTrue();
        });

        test('handles numeric feature values', function (): void {
            // Arrange
            Feature::define('numeric-feature', 42);

            // Act
            $value = Feature::value('numeric-feature');

            // Assert
            expect($value)->toBe(42);
        });

        test('handles array feature values', function (): void {
            // Arrange
            $config = ['option1' => true, 'option2' => false];
            Feature::define('array-feature', $config);

            // Act
            $value = Feature::value('array-feature');

            // Assert
            expect($value)->toBe($config);
        });

        test('handles multiple scopes for same feature', function (): void {
            // Arrange
            Feature::define('multi-scope', fn ($scope): bool => $scope > 100);

            // Act & Assert
            expect(Feature::for(50)->active('multi-scope'))->toBeFalse();
            expect(Feature::for(150)->active('multi-scope'))->toBeTrue();
        });

        test('can activate multiple features at once', function (): void {
            // Arrange
            Feature::define('batch-1', false);
            Feature::define('batch-2', false);

            // Act
            Feature::activate(['batch-1', 'batch-2']);

            // Assert
            expect(Feature::active('batch-1'))->toBeTrue();
            expect(Feature::active('batch-2'))->toBeTrue();
        });

        test('can deactivate multiple features at once', function (): void {
            // Arrange
            Feature::define('multi-1', true);
            Feature::define('multi-2', true);

            // Act
            Feature::deactivate(['multi-1', 'multi-2']);

            // Assert
            expect(Feature::inactive('multi-1'))->toBeTrue();
            expect(Feature::inactive('multi-2'))->toBeTrue();
        });
    });
});
