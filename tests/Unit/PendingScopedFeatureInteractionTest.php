<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Feature;

describe('PendingScopedFeatureInteraction', function (): void {
    describe('Happy Path', function (): void {
        test('value returns single feature value for single scope', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): string => 'test-value');

            // Act
            $result = Feature::for('user1')->value('test-feature');

            // Assert
            expect($result)->toBe('test-value');
        });

        test('values returns multiple feature values for single scope', function (): void {
            // Arrange
            Feature::define('feature1', fn (): string => 'value1');
            Feature::define('feature2', fn (): string => 'value2');

            // Act
            $result = Feature::for('user1')->values(['feature1', 'feature2']);

            // Assert
            expect($result)->toHaveKey('feature1', 'value1');
            expect($result)->toHaveKey('feature2', 'value2');
        });

        test('when executes callback when feature is active', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): true => true);
            $executed = false;

            // Act
            Feature::for('user1')->when('test-feature', function ($value, $interaction) use (&$executed): string {
                $executed = true;

                return 'success';
            });

            // Assert
            expect($executed)->toBeTrue();
        });

        test('when executes inactive callback when feature is inactive', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): false => false);
            $executed = false;

            // Act
            $result = Feature::for('user1')->when(
                'test-feature',
                fn (): string => 'active',
                function () use (&$executed): string {
                    $executed = true;

                    return 'inactive';
                },
            );

            // Assert
            expect($executed)->toBeTrue();
            expect($result)->toBe('inactive'); // Line 264
        });

        test('unless executes callback when feature is inactive', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): false => false);
            $executed = false;

            // Act
            Feature::for('user1')->unless('test-feature', function () use (&$executed): void {
                $executed = true;
            });

            // Assert
            expect($executed)->toBeTrue();
        });

        test('activateGroup activates all features in group for specified scopes', function (): void {
            // Arrange
            config(['pole.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Feature::define('feature1', fn (): false => false);
            Feature::define('feature2', fn (): false => false);

            // Act
            Feature::for('user1')->activateGroup('test-group');

            // Assert
            expect(Feature::for('user1')->active('feature1'))->toBeTrue();
            expect(Feature::for('user1')->active('feature2'))->toBeTrue();
        });

        test('deactivateGroup deactivates all features in group for specified scopes', function (): void {
            // Arrange
            config(['pole.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Feature::define('feature1', fn (): true => true);
            Feature::define('feature2', fn (): true => true);
            Feature::for('user1')->activateGroup('test-group');

            // Act
            Feature::for('user1')->deactivateGroup('test-group');

            // Assert
            expect(Feature::for('user1')->active('feature1'))->toBeFalse();
            expect(Feature::for('user1')->active('feature2'))->toBeFalse();
        });

        test('activeInGroup returns true when empty group', function (): void {
            // Arrange
            config(['pole.groups.empty-group' => ['features' => []]]);

            // Act & Assert
            expect(Feature::for('user1')->activeInGroup('empty-group'))->toBeTrue(); // Line 365
        });

        test('activeInGroup returns true when all features are active', function (): void {
            // Arrange
            config(['pole.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Feature::define('feature1', fn (): true => true);
            Feature::define('feature2', fn (): true => true);

            // Act & Assert
            expect(Feature::for('user1')->activeInGroup('test-group'))->toBeTrue();
        });

        test('activeInGroup returns false when some features are inactive', function (): void {
            // Arrange
            config(['pole.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Feature::define('feature1', fn (): true => true);
            Feature::define('feature2', fn (): false => false);

            // Act & Assert
            expect(Feature::for('user1')->activeInGroup('test-group'))->toBeFalse();
        });

        test('someActiveInGroup returns false when empty group', function (): void {
            // Arrange
            config(['pole.groups.empty-group' => ['features' => []]]);

            // Act & Assert
            expect(Feature::for('user1')->someActiveInGroup('empty-group'))->toBeFalse(); // Line 386-396
        });

        test('someActiveInGroup returns true when at least one feature is active', function (): void {
            // Arrange
            config(['pole.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Feature::define('feature1', fn (): true => true);
            Feature::define('feature2', fn (): false => false);

            // Act & Assert
            expect(Feature::for('user1')->someActiveInGroup('test-group'))->toBeTrue();
        });

        test('someActiveInGroup returns false when all features are inactive', function (): void {
            // Arrange
            config(['pole.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Feature::define('feature1', fn (): false => false);
            Feature::define('feature2', fn (): false => false);

            // Act & Assert
            expect(Feature::for('user1')->someActiveInGroup('test-group'))->toBeFalse();
        });

        test('variant returns null when no variants defined', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): true => true);

            // Act
            $result = Feature::for('user1')->variant('test-feature');

            // Assert
            expect($result)->toBeNull(); // Line 430
        });

        test('variant returns assigned variant based on scope', function (): void {
            // Arrange
            Feature::defineVariant('ab-test', ['control' => 50, 'treatment' => 50]);

            // Act
            $result = Feature::for('user1')->variant('ab-test');

            // Assert
            expect($result)->toBeIn(['control', 'treatment']);
        });

        test('variant returns consistent value for same scope', function (): void {
            // Arrange
            Feature::defineVariant('ab-test', ['control' => 50, 'treatment' => 50]);

            // Act
            $result1 = Feature::for('user1')->variant('ab-test');
            $result2 = Feature::for('user1')->variant('ab-test');

            // Assert
            expect($result1)->toBe($result2);
        });

        test('someAreActive returns true when at least one feature active for all scopes', function (): void {
            // Arrange
            Feature::define('feature1', fn ($scope): bool => $scope === 'user1');
            Feature::define('feature2', fn (): true => true);

            // Act
            $result = Feature::for(['user1', 'user2'])->someAreActive(['feature1', 'feature2']);

            // Assert
            expect($result)->toBeTrue(); // Line 239-243
        });

        test('someAreInactive returns true when at least one feature inactive for all scopes', function (): void {
            // Arrange
            Feature::define('feature1', fn (): true => true);
            Feature::define('feature2', fn (): false => false);

            // Act
            $result = Feature::for(['user1', 'user2'])->someAreInactive(['feature1', 'feature2']);

            // Assert
            expect($result)->toBeTrue(); // Line 239-243
        });
    });

    describe('Sad Path', function (): void {
        test('value throws exception for multiple scopes', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): true => true);

            // Act & Assert
            expect(fn () => Feature::for(['user1', 'user2'])->value('test-feature'))
                ->toThrow(RuntimeException::class, 'It is not possible to retrieve the values for multiple scopes.'); // Line 132
        });

        test('values throws exception for multiple scopes', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): true => true);

            // Act & Assert
            expect(fn () => Feature::for(['user1', 'user2'])->values(['test-feature']))
                ->toThrow(RuntimeException::class, 'It is not possible to retrieve the values for multiple scopes.');
        });

        test('all throws exception for multiple scopes', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): true => true);

            // Act & Assert
            expect(fn () => Feature::for(['user1', 'user2'])->all())
                ->toThrow(RuntimeException::class, 'It is not possible to retrieve the values for multiple scopes.'); // Line 153
        });

        test('variant throws exception for multiple scopes', function (): void {
            // Arrange
            Feature::defineVariant('ab-test', ['control' => 50, 'treatment' => 50]);

            // Act & Assert
            expect(fn () => Feature::for(['user1', 'user2'])->variant('ab-test'))
                ->toThrow(RuntimeException::class, 'It is not possible to retrieve variants for multiple scopes.'); // Line 414
        });
    });

    describe('Edge Cases', function (): void {
        test('for merges multiple scope calls', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): true => true);

            // Act
            $result = Feature::for('user1')->for('user2')->allAreActive(['test-feature']);

            // Assert
            expect($result)->toBeTrue();
        });

        test('when returns null when inactive and no inactive callback', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): false => false);

            // Act
            $result = Feature::for('user1')->when('test-feature', fn (): string => 'active');

            // Assert
            expect($result)->toBeNull(); // Line 264
        });

        test('load preloads features into cache', function (): void {
            // Arrange
            Feature::define('feature1', fn (): string => 'value1');
            Feature::define('feature2', fn (): string => 'value2');

            // Act
            $result = Feature::for('user1')->load(['feature1', 'feature2']);

            // Assert
            expect($result)->toHaveKey('feature1');
            expect($result)->toHaveKey('feature2');
            expect($result['feature1'][0])->toBe('value1');
            expect($result['feature2'][0])->toBe('value2');
        });

        test('loadMissing only loads uncached features', function (): void {
            // Arrange
            $callCount = 0;
            Feature::define('test-feature', function () use (&$callCount): string {
                ++$callCount;

                return 'value';
            });

            // Pre-load the feature
            Feature::for('user1')->load(['test-feature']);

            // Act
            Feature::for('user1')->loadMissing(['test-feature']);

            // Assert - Should only be called once (during load, not loadMissing)
            expect($callCount)->toBe(1);
        });

        test('activate can activate multiple features', function (): void {
            // Arrange
            Feature::define('feature1', fn (): false => false);
            Feature::define('feature2', fn (): false => false);

            // Act
            Feature::for('user1')->activate(['feature1', 'feature2']);

            // Assert
            expect(Feature::for('user1')->active('feature1'))->toBeTrue();
            expect(Feature::for('user1')->active('feature2'))->toBeTrue();
        });

        test('deactivate can deactivate multiple features', function (): void {
            // Arrange
            Feature::define('feature1', fn (): true => true);
            Feature::define('feature2', fn (): true => true);

            // Act
            Feature::for('user1')->deactivate(['feature1', 'feature2']);

            // Assert
            expect(Feature::for('user1')->active('feature1'))->toBeFalse();
            expect(Feature::for('user1')->active('feature2'))->toBeFalse();
        });

        test('forget can forget multiple features', function (): void {
            // Arrange
            Feature::define('feature1', fn (): true => true);
            Feature::define('feature2', fn (): true => true);
            Feature::for('user1')->activate(['feature1', 'feature2']);

            // Act
            Feature::for('user1')->forget(['feature1', 'feature2']);

            // Redefine to test they're truly forgotten
            $callCount = 0;
            Feature::define('feature1', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });

            Feature::for('user1')->active('feature1');

            // Assert - Should call resolver since cache was cleared
            expect($callCount)->toBe(1);
        });

        test('all returns all defined features for single scope', function (): void {
            // Arrange
            Feature::define('feature1', fn (): string => 'value1');
            Feature::define('feature2', fn (): string => 'value2');

            // Act
            $result = Feature::for('user1')->all();

            // Assert
            expect($result)->toHaveKey('feature1');
            expect($result)->toHaveKey('feature2');
        });

        test('variant calculates consistently using hash', function (): void {
            // Arrange
            Feature::defineVariant('ab-test', ['control' => 100, 'treatment' => 0]);

            // Act
            $result = Feature::for('test-user')->variant('ab-test');

            // Assert - With 100% weight on control, should always get control
            expect($result)->toBe('control'); // Line 476
        });

        test('variant fallback to last variant when weights edge case', function (): void {
            // Arrange
            Feature::defineVariant('fallback-test', ['first' => 0, 'second' => 0, 'last' => 100]);

            // Act - Line 476: Fallback when no match found (edge case in cumulative calculation)
            $result = Feature::for('edge-case-user')->variant('fallback-test');

            // Assert - Should fall back to last variant
            expect($result)->toBe('last');
        });
    });
});
