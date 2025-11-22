<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Strategies\ConditionalStrategy;

/**
 * Test suite for ConditionalStrategy functionality.
 *
 * Validates closure-based feature flag resolution with dynamic logic. Tests
 * closure execution with various return types, null context handling detection
 * via reflection, parameter type analysis for nullable/typed parameters, and
 * complex conditional logic. Supports arbitrary resolution logic based on context
 * properties, external variables, and multi-condition evaluations.
 */
describe('ConditionalStrategy', function (): void {
    describe('Happy Path', function (): void {
        test('resolves using closure that returns boolean', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($context): bool => $context === 'admin');

            // Act
            $resultForAdmin = $strategy->resolve('admin');
            $resultForUser = $strategy->resolve('user');

            // Assert
            expect($resultForAdmin)->toBeTrue();
            expect($resultForUser)->toBeFalse();
        });

        test('resolves using closure that returns non-boolean values', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($context): string => 'result-'.$context);

            // Act
            $result = $strategy->resolve('test');

            // Assert
            expect($result)->toBe('result-test');
        });

        test('detects when closure can handle null context with no parameters', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn (): true => true);

            // Act
            $canHandle = $strategy->canHandleNullContext();

            // Assert
            expect($canHandle)->toBeTrue();
        });

        test('detects when closure can handle null context with nullable parameter', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn (?string $context): bool => $context !== null);

            // Act
            $canHandle = $strategy->canHandleNullContext();

            // Assert
            expect($canHandle)->toBeTrue();
        });

        test('resolves with closure that has no type hint', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($context): bool => $context === null);

            // Act
            $canHandle = $strategy->canHandleNullContext();
            $result = $strategy->resolve(null);

            // Assert
            expect($canHandle)->toBeTrue();
            expect($result)->toBeTrue();
        });
    });

    describe('Sad Path', function (): void {
        test('detects when closure cannot handle null context with non-nullable typed parameter', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn (string $context): bool => $context === 'admin');

            // Act
            $canHandle = $strategy->canHandleNullContext();

            // Assert
            expect($canHandle)->toBeFalse();
        });

        test('detects when closure cannot handle null context with object type', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn (stdClass $context): true => true);

            // Act
            $canHandle = $strategy->canHandleNullContext();

            // Assert
            expect($canHandle)->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('resolves with closure returning null', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($context): null => null);

            // Act
            $result = $strategy->resolve('test');

            // Assert
            expect($result)->toBeNull();
        });

        test('resolves with closure returning array', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($context): array => ['context' => $context]);

            // Act
            $result = $strategy->resolve('user');

            // Assert
            expect($result)->toBe(['context' => 'user']);
        });

        test('resolves with closure returning object', function (): void {
            // Arrange
            $expected = new stdClass();
            $strategy = new ConditionalStrategy(fn ($context): stdClass => $expected);

            // Act
            $result = $strategy->resolve('test');

            // Assert
            expect($result)->toBe($expected);
        });

        test('handles closure with mixed type parameter', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn (mixed $context): mixed => $context);

            // Act
            $canHandle = $strategy->canHandleNullContext();

            // Assert
            expect($canHandle)->toBeTrue();
        });

        test('resolves with complex condition logic', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(function ($context): bool {
                if (is_string($context)) {
                    return str_starts_with($context, 'admin');
                }

                if (is_numeric($context)) {
                    return $context > 100;
                }

                return false;
            });

            // Act & Assert
            expect($strategy->resolve('admin-user'))->toBeTrue();
            expect($strategy->resolve('user'))->toBeFalse();
            expect($strategy->resolve(150))->toBeTrue();
            expect($strategy->resolve(50))->toBeFalse();
        });

        test('handles closure accessing external variables', function (): void {
            // Arrange
            $allowedUsers = ['admin', 'moderator'];
            $strategy = new ConditionalStrategy(fn ($context): bool => in_array($context, $allowedUsers, true));

            // Act & Assert
            expect($strategy->resolve('admin'))->toBeTrue();
            expect($strategy->resolve('moderator'))->toBeTrue();
            expect($strategy->resolve('user'))->toBeFalse();
        });

        test('resolves with closure that checks null explicitly', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($context): string => $context === null ? 'null-context' : 'has-context');

            // Act
            $nullResult = $strategy->resolve(null);
            $contextdResult = $strategy->resolve('user');

            // Assert
            expect($nullResult)->toBe('null-context');
            expect($contextdResult)->toBe('has-context');
        });
    });
});
