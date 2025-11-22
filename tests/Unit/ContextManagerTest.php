<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\ContextManager;

/**
 * Test suite for ContextManager functionality.
 *
 * Validates context context management for feature flags including setting, retrieving,
 * clearing, and checking context presence. Tests the fluent interface pattern and
 * support for various context types (strings, integers, arrays, objects). The context
 * manager provides a centralized way to track the current feature resolution context.
 */
describe('ContextManager', function (): void {
    describe('Happy Path', function (): void {
        test('can set and get context', function (): void {
            // Arrange
            $manager = new ContextManager();

            // Act
            $manager->to('team-123');

            // Assert
            expect($manager->current())->toBe('team-123');
        });

        test('returns null when no context is set', function (): void {
            // Arrange
            $manager = new ContextManager();

            // Assert
            expect($manager->current())->toBeNull();
        });

        test('can check if context is set', function (): void {
            // Arrange
            $manager = new ContextManager();

            // Act & Assert
            expect($manager->hasContext())->toBeFalse();

            $manager->to('account-456');
            expect($manager->hasContext())->toBeTrue();
        });

        test('can clear context', function (): void {
            // Arrange
            $manager = new ContextManager();
            $manager->to('team-789');

            // Act
            $manager->clear();

            // Assert
            expect($manager->current())->toBeNull();
            expect($manager->hasContext())->toBeFalse();
        });

        test('returns self for fluent interface on to()', function (): void {
            // Arrange
            $manager = new ContextManager();

            // Act
            $result = $manager->to('context');

            // Assert
            expect($result)->toBe($manager);
        });

        test('returns self for fluent interface on clear()', function (): void {
            // Arrange
            $manager = new ContextManager();

            // Act
            $result = $manager->clear();

            // Assert
            expect($result)->toBe($manager);
        });

        test('can overwrite existing context', function (): void {
            // Arrange
            $manager = new ContextManager();
            $manager->to('team-1');

            // Act
            $manager->to('team-2');

            // Assert
            expect($manager->current())->toBe('team-2');
        });
    });

    describe('Edge Cases', function (): void {
        test('can set numeric context', function (): void {
            // Arrange
            $manager = new ContextManager();

            // Act
            $manager->to(123);

            // Assert
            expect($manager->current())->toBe(123);
        });

        test('can set array context', function (): void {
            // Arrange
            $manager = new ContextManager();
            $context = ['team_id' => 5, 'account_id' => 10];

            // Act
            $manager->to($context);

            // Assert
            expect($manager->current())->toBe($context);
        });

        test('can set object context', function (): void {
            // Arrange
            $manager = new ContextManager();
            $context = (object) ['id' => 42];

            // Act
            $manager->to($context);

            // Assert
            expect($manager->current())->toBe($context);
        });

        test('hasContext returns false after clearing', function (): void {
            // Arrange
            $manager = new ContextManager();
            $manager->to('context');

            // Act
            $manager->clear();

            // Assert
            expect($manager->hasContext())->toBeFalse();
        });
    });
});
