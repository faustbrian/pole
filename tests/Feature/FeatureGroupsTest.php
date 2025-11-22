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
 * Feature Groups Test Suite
 *
 * Tests feature grouping functionality that allows batch operations on
 * collections of related features. Covers group definition, batch activation
 * and deactivation, status checking (all/some active), contextual group operations,
 * config-based group loading, and exception handling for undefined groups.
 */
describe('Feature Groups', function (): void {
    describe('Happy Path', function (): void {
        test('can define a feature group', function (): void {
            // Arrange & Act
            Toggl::defineGroup('beta', ['new-api', 'dark-mode', 'ai-chat']);

            // Assert
            expect(Toggl::getGroup('beta'))->toBe(['new-api', 'dark-mode', 'ai-chat']);
        });

        test('can activate all features in a group', function (): void {
            // Arrange
            Toggl::define('new-api', false);
            Toggl::define('dark-mode', false);
            Toggl::define('ai-chat', false);
            Toggl::defineGroup('beta', ['new-api', 'dark-mode', 'ai-chat']);

            // Act
            Toggl::activateGroupForEveryone('beta');

            // Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('new-api'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('dark-mode'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('ai-chat'))->toBeTrue();
        });

        test('can deactivate all features in a group', function (): void {
            // Arrange
            Toggl::define('new-api', true);
            Toggl::define('dark-mode', true);
            Toggl::define('ai-chat', true);
            Toggl::defineGroup('beta', ['new-api', 'dark-mode', 'ai-chat']);

            // Act
            Toggl::deactivateGroupForEveryone('beta');

            // Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->inactive('new-api'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->inactive('dark-mode'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->inactive('ai-chat'))->toBeTrue();
        });

        test('can check if all features in group are active', function (): void {
            // Arrange
            Toggl::define('feature-1', true);
            Toggl::define('feature-2', true);
            Toggl::define('feature-3', false);
            Toggl::defineGroup('complete', ['feature-1', 'feature-2']);
            Toggl::defineGroup('incomplete', ['feature-1', 'feature-3']);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->activeInGroup('complete'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->activeInGroup('incomplete'))->toBeFalse();
        });

        test('can check if any features in group are active', function (): void {
            // Arrange
            Toggl::define('feature-a', true);
            Toggl::define('feature-b', false);
            Toggl::define('feature-c', false);
            Toggl::defineGroup('mixed', ['feature-a', 'feature-b']);
            Toggl::defineGroup('all-inactive', ['feature-b', 'feature-c']);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->someActiveInGroup('mixed'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->someActiveInGroup('all-inactive'))->toBeFalse();
        });

        test('can check group status with contextual features', function (): void {
            // Arrange
            Toggl::define('premium-export', fn (TogglContext $context): bool => $context->id === 'premium');
            Toggl::define('priority-support', fn (TogglContext $context): bool => $context->id === 'premium');
            Toggl::defineGroup('premium', ['premium-export', 'priority-support']);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple('premium', 'test'))->activeInGroup('premium'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple('basic', 'test'))->activeInGroup('premium'))->toBeFalse();
        });

        test('can activate group for specific context', function (): void {
            // Arrange
            Toggl::define('feature-x', fn (): false => false);
            Toggl::define('feature-y', fn (): false => false);
            Toggl::defineGroup('user-group', ['feature-x', 'feature-y']);

            // Act
            Toggl::for(TogglContext::simple('user-123', 'test'))->activateGroup('user-group');

            // Assert
            expect(Toggl::for(TogglContext::simple('user-123', 'test'))->active('feature-x'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple('user-123', 'test'))->active('feature-y'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple('user-456', 'test'))->active('feature-x'))->toBeFalse();
        });

        test('can get all defined groups', function (): void {
            // Arrange
            Toggl::defineGroup('group-1', ['a', 'b']);
            Toggl::defineGroup('group-2', ['c', 'd']);

            // Act
            $groups = Toggl::allGroups();

            // Assert
            expect($groups)->toHaveKey('group-1');
            expect($groups)->toHaveKey('group-2');
            expect($groups['group-1'])->toBe(['a', 'b']);
        });

        test('can activate group for everyone', function (): void {
            // Arrange
            Toggl::define('global-1', false);
            Toggl::define('global-2', false);
            Toggl::defineGroup('global-group', ['global-1', 'global-2']);

            // Act
            Toggl::activateGroupForEveryone('global-group');

            // Assert
            expect(Toggl::for(TogglContext::simple('user-1', 'test'))->active('global-1'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple('user-2', 'test'))->active('global-2'))->toBeTrue();
        });

        test('can deactivate group for everyone', function (): void {
            // Arrange
            Toggl::define('wide-1', true);
            Toggl::define('wide-2', true);
            Toggl::defineGroup('wide-group', ['wide-1', 'wide-2']);

            // Act
            Toggl::deactivateGroupForEveryone('wide-group');

            // Assert
            expect(Toggl::for(TogglContext::simple('user-1', 'test'))->active('wide-1'))->toBeFalse();
            expect(Toggl::for(TogglContext::simple('user-2', 'test'))->active('wide-2'))->toBeFalse();
        });
    });

    describe('Sad Path', function (): void {
        test('throws exception for undefined group', function (): void {
            // Act & Assert
            expect(fn () => Toggl::activateGroupForEveryone('non-existent'))
                ->toThrow(InvalidArgumentException::class, 'Feature group [non-existent] is not defined');
        });

        test('throws exception when checking undefined group status', function (): void {
            // Act & Assert
            expect(fn () => Toggl::for(TogglContext::simple(1, 'test'))->activeInGroup('undefined'))
                ->toThrow(InvalidArgumentException::class, 'Feature group [undefined] is not defined');
        });

        test('throws exception when deactivating undefined group', function (): void {
            // Act & Assert
            expect(fn () => Toggl::deactivateGroupForEveryone('undefined'))
                ->toThrow(InvalidArgumentException::class, 'Feature group [undefined] is not defined');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles empty groups', function (): void {
            // Arrange
            Toggl::defineGroup('empty', []);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->activeInGroup('empty'))->toBeTrue(); // All zero features are "active"
            expect(Toggl::for(TogglContext::simple(1, 'test'))->someActiveInGroup('empty'))->toBeFalse(); // No features to be active
        });

        test('handles group with undefined features', function (): void {
            // Arrange
            Toggl::defineGroup('mixed', ['defined-feature', 'undefined-feature']);
            Toggl::define('defined-feature', true);

            // Act
            Toggl::activateGroupForEveryone('mixed');

            // Assert - undefined features get activated
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('defined-feature'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('undefined-feature'))->toBeTrue();
        });

        test('can redefine existing group', function (): void {
            // Arrange
            Toggl::defineGroup('mutable', ['a', 'b']);

            // Act
            Toggl::defineGroup('mutable', ['c', 'd']);

            // Assert
            expect(Toggl::getGroup('mutable'))->toBe(['c', 'd']);
        });

        test('handles nested group operations', function (): void {
            // Arrange
            Toggl::define('shared', false);
            Toggl::defineGroup('group-a', ['shared', 'unique-a']);
            Toggl::defineGroup('group-b', ['shared', 'unique-b']);

            // Act
            Toggl::activateGroupForEveryone('group-a');
            Toggl::activateGroupForEveryone('group-b');

            // Assert - shared feature should be active, all should be active
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('shared'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('unique-a'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('unique-b'))->toBeTrue();
        });

        test('can load groups from config', function (): void {
            // Arrange
            config()->set('toggl.groups', [
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
            Toggl::loadGroupsFromConfig();
            $groups = Toggl::allGroups();

            // Assert
            expect($groups)->toHaveKey('experimental');
            expect($groups)->toHaveKey('production');
            expect($groups['experimental'])->toBe(['exp-1', 'exp-2']);
        });
    });
});
