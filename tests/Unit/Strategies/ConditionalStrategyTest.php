<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Strategies\ConditionalStrategy;

describe('ConditionalStrategy', function (): void {
    describe('Happy Path', function (): void {
        test('resolves using closure that returns boolean', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($scope): bool => $scope === 'admin');

            // Act
            $resultForAdmin = $strategy->resolve('admin');
            $resultForUser = $strategy->resolve('user');

            // Assert
            expect($resultForAdmin)->toBeTrue();
            expect($resultForUser)->toBeFalse();
        });

        test('resolves using closure that returns non-boolean values', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($scope): string => 'result-'.$scope);

            // Act
            $result = $strategy->resolve('test');

            // Assert
            expect($result)->toBe('result-test');
        });

        test('detects when closure can handle null scope with no parameters', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn (): true => true);

            // Act
            $canHandle = $strategy->canHandleNullScope();

            // Assert
            expect($canHandle)->toBeTrue();
        });

        test('detects when closure can handle null scope with nullable parameter', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn (?string $scope): bool => $scope !== null);

            // Act
            $canHandle = $strategy->canHandleNullScope();

            // Assert
            expect($canHandle)->toBeTrue();
        });

        test('resolves with closure that has no type hint', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($scope): bool => $scope === null);

            // Act
            $canHandle = $strategy->canHandleNullScope();
            $result = $strategy->resolve(null);

            // Assert
            expect($canHandle)->toBeTrue();
            expect($result)->toBeTrue();
        });
    });

    describe('Sad Path', function (): void {
        test('detects when closure cannot handle null scope with non-nullable typed parameter', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn (string $scope): bool => $scope === 'admin');

            // Act
            $canHandle = $strategy->canHandleNullScope();

            // Assert
            expect($canHandle)->toBeFalse();
        });

        test('detects when closure cannot handle null scope with object type', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn (stdClass $scope): true => true);

            // Act
            $canHandle = $strategy->canHandleNullScope();

            // Assert
            expect($canHandle)->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('resolves with closure returning null', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($scope): null => null);

            // Act
            $result = $strategy->resolve('test');

            // Assert
            expect($result)->toBeNull();
        });

        test('resolves with closure returning array', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($scope): array => ['scope' => $scope]);

            // Act
            $result = $strategy->resolve('user');

            // Assert
            expect($result)->toBe(['scope' => 'user']);
        });

        test('resolves with closure returning object', function (): void {
            // Arrange
            $expected = new stdClass();
            $strategy = new ConditionalStrategy(fn ($scope): stdClass => $expected);

            // Act
            $result = $strategy->resolve('test');

            // Assert
            expect($result)->toBe($expected);
        });

        test('handles closure with mixed type parameter', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn (mixed $scope): mixed => $scope);

            // Act
            $canHandle = $strategy->canHandleNullScope();

            // Assert
            expect($canHandle)->toBeTrue();
        });

        test('resolves with complex condition logic', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(function ($scope): bool {
                if (is_string($scope)) {
                    return str_starts_with($scope, 'admin');
                }

                if (is_numeric($scope)) {
                    return $scope > 100;
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
            $strategy = new ConditionalStrategy(fn ($scope): bool => in_array($scope, $allowedUsers, true));

            // Act & Assert
            expect($strategy->resolve('admin'))->toBeTrue();
            expect($strategy->resolve('moderator'))->toBeTrue();
            expect($strategy->resolve('user'))->toBeFalse();
        });

        test('resolves with closure that checks null explicitly', function (): void {
            // Arrange
            $strategy = new ConditionalStrategy(fn ($scope): string => $scope === null ? 'null-scope' : 'has-scope');

            // Act
            $nullResult = $strategy->resolve(null);
            $scopedResult = $strategy->resolve('user');

            // Assert
            expect($nullResult)->toBe('null-scope');
            expect($scopedResult)->toBe('has-scope');
        });
    });
});
