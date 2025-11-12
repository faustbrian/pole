<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Feature;

describe('Feature Groups', function (): void {
    describe('Happy Path', function (): void {
        test('can define a feature group', function (): void {
            // Arrange & Act
            Feature::defineGroup('beta', ['new-api', 'dark-mode', 'ai-chat']);

            // Assert
            expect(Feature::getGroup('beta'))->toBe(['new-api', 'dark-mode', 'ai-chat']);
        });

        test('can activate all features in a group', function (): void {
            // Arrange
            Feature::define('new-api', false);
            Feature::define('dark-mode', false);
            Feature::define('ai-chat', false);
            Feature::defineGroup('beta', ['new-api', 'dark-mode', 'ai-chat']);

            // Act
            Feature::activateGroup('beta');

            // Assert
            expect(Feature::active('new-api'))->toBeTrue();
            expect(Feature::active('dark-mode'))->toBeTrue();
            expect(Feature::active('ai-chat'))->toBeTrue();
        });

        test('can deactivate all features in a group', function (): void {
            // Arrange
            Feature::define('new-api', true);
            Feature::define('dark-mode', true);
            Feature::define('ai-chat', true);
            Feature::defineGroup('beta', ['new-api', 'dark-mode', 'ai-chat']);

            // Act
            Feature::deactivateGroup('beta');

            // Assert
            expect(Feature::inactive('new-api'))->toBeTrue();
            expect(Feature::inactive('dark-mode'))->toBeTrue();
            expect(Feature::inactive('ai-chat'))->toBeTrue();
        });

        test('can check if all features in group are active', function (): void {
            // Arrange
            Feature::define('feature-1', true);
            Feature::define('feature-2', true);
            Feature::define('feature-3', false);
            Feature::defineGroup('complete', ['feature-1', 'feature-2']);
            Feature::defineGroup('incomplete', ['feature-1', 'feature-3']);

            // Act & Assert
            expect(Feature::activeInGroup('complete'))->toBeTrue();
            expect(Feature::activeInGroup('incomplete'))->toBeFalse();
        });

        test('can check if any features in group are active', function (): void {
            // Arrange
            Feature::define('feature-a', true);
            Feature::define('feature-b', false);
            Feature::define('feature-c', false);
            Feature::defineGroup('mixed', ['feature-a', 'feature-b']);
            Feature::defineGroup('all-inactive', ['feature-b', 'feature-c']);

            // Act & Assert
            expect(Feature::someActiveInGroup('mixed'))->toBeTrue();
            expect(Feature::someActiveInGroup('all-inactive'))->toBeFalse();
        });

        test('can check group status with scoped features', function (): void {
            // Arrange
            Feature::define('premium-export', fn ($user): bool => $user === 'premium');
            Feature::define('priority-support', fn ($user): bool => $user === 'premium');
            Feature::defineGroup('premium', ['premium-export', 'priority-support']);

            // Act & Assert
            expect(Feature::for('premium')->activeInGroup('premium'))->toBeTrue();
            expect(Feature::for('basic')->activeInGroup('premium'))->toBeFalse();
        });

        test('can activate group for specific scope', function (): void {
            // Arrange
            Feature::define('feature-x', fn (): false => false);
            Feature::define('feature-y', fn (): false => false);
            Feature::defineGroup('user-group', ['feature-x', 'feature-y']);

            // Act
            Feature::for('user-123')->activateGroup('user-group');

            // Assert
            expect(Feature::for('user-123')->active('feature-x'))->toBeTrue();
            expect(Feature::for('user-123')->active('feature-y'))->toBeTrue();
            expect(Feature::for('user-456')->active('feature-x'))->toBeFalse();
        });

        test('can get all defined groups', function (): void {
            // Arrange
            Feature::defineGroup('group-1', ['a', 'b']);
            Feature::defineGroup('group-2', ['c', 'd']);

            // Act
            $groups = Feature::groups();

            // Assert
            expect($groups)->toHaveKey('group-1');
            expect($groups)->toHaveKey('group-2');
            expect($groups['group-1'])->toBe(['a', 'b']);
        });

        test('can activate group for everyone', function (): void {
            // Arrange
            Feature::define('global-1', false);
            Feature::define('global-2', false);
            Feature::defineGroup('global-group', ['global-1', 'global-2']);

            // Act
            Feature::activateGroupForEveryone('global-group');

            // Assert
            expect(Feature::for('user-1')->active('global-1'))->toBeTrue();
            expect(Feature::for('user-2')->active('global-2'))->toBeTrue();
        });

        test('can deactivate group for everyone', function (): void {
            // Arrange
            Feature::define('wide-1', true);
            Feature::define('wide-2', true);
            Feature::defineGroup('wide-group', ['wide-1', 'wide-2']);

            // Act
            Feature::deactivateGroupForEveryone('wide-group');

            // Assert
            expect(Feature::for('user-1')->active('wide-1'))->toBeFalse();
            expect(Feature::for('user-2')->active('wide-2'))->toBeFalse();
        });
    });

    describe('Sad Path', function (): void {
        test('throws exception for undefined group', function (): void {
            // Act & Assert
            expect(fn () => Feature::activateGroup('non-existent'))
                ->toThrow(InvalidArgumentException::class, 'Feature group [non-existent] is not defined');
        });

        test('throws exception when checking undefined group status', function (): void {
            // Act & Assert
            expect(fn () => Feature::activeInGroup('undefined'))
                ->toThrow(InvalidArgumentException::class, 'Feature group [undefined] is not defined');
        });

        test('throws exception when deactivating undefined group', function (): void {
            // Act & Assert
            expect(fn () => Feature::deactivateGroup('undefined'))
                ->toThrow(InvalidArgumentException::class, 'Feature group [undefined] is not defined');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles empty groups', function (): void {
            // Arrange
            Feature::defineGroup('empty', []);

            // Act & Assert
            expect(Feature::activeInGroup('empty'))->toBeTrue(); // All zero features are "active"
            expect(Feature::someActiveInGroup('empty'))->toBeFalse(); // No features to be active
        });

        test('handles group with undefined features', function (): void {
            // Arrange
            Feature::defineGroup('mixed', ['defined-feature', 'undefined-feature']);
            Feature::define('defined-feature', true);

            // Act
            Feature::activateGroup('mixed');

            // Assert - undefined features get activated
            expect(Feature::active('defined-feature'))->toBeTrue();
            expect(Feature::active('undefined-feature'))->toBeTrue();
        });

        test('can redefine existing group', function (): void {
            // Arrange
            Feature::defineGroup('mutable', ['a', 'b']);

            // Act
            Feature::defineGroup('mutable', ['c', 'd']);

            // Assert
            expect(Feature::getGroup('mutable'))->toBe(['c', 'd']);
        });

        test('handles nested group operations', function (): void {
            // Arrange
            Feature::define('shared', false);
            Feature::defineGroup('group-a', ['shared', 'unique-a']);
            Feature::defineGroup('group-b', ['shared', 'unique-b']);

            // Act
            Feature::activateGroup('group-a');
            Feature::activateGroup('group-b');

            // Assert - shared feature should be active, all should be active
            expect(Feature::active('shared'))->toBeTrue();
            expect(Feature::active('unique-a'))->toBeTrue();
            expect(Feature::active('unique-b'))->toBeTrue();
        });

        test('can load groups from config', function (): void {
            // Arrange
            config()->set('pole.groups', [
                'experimental' => [
                    'features' => ['exp-1', 'exp-2'],
                    'description' => 'Experimental features',
                ],
                'production' => [
                    'features' => ['prod-1'],
                    'description' => 'Production ready',
                ],
            ]);

            // Act
            Feature::loadGroupsFromConfig();
            $groups = Feature::groups();

            // Assert
            expect($groups)->toHaveKey('experimental');
            expect($groups)->toHaveKey('production');
            expect($groups['experimental'])->toBe(['exp-1', 'exp-2']);
        });
    });
});
