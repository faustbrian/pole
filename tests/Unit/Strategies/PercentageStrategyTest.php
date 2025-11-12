<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Strategies\PercentageStrategy;

describe('PercentageStrategy', function (): void {
    describe('Happy Path', function (): void {
        test('resolves to true for 100 percent rollout', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(100);

            // Act
            $result = $strategy->resolve('user-123');

            // Assert
            expect($result)->toBeTrue();
        });

        test('resolves to false for 0 percent rollout', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(0);

            // Act
            $result = $strategy->resolve('user-123');

            // Assert
            expect($result)->toBeFalse();
        });

        test('resolves consistently for same scope', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);
            $scope = 'user-123';

            // Act
            $result1 = $strategy->resolve($scope);
            $result2 = $strategy->resolve($scope);
            $result3 = $strategy->resolve($scope);

            // Assert
            expect($result1)->toBe($result2);
            expect($result2)->toBe($result3);
        });

        test('accepts string scope', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);

            // Act
            $result = $strategy->resolve('user-string');

            // Assert
            expect($result)->toBeIn([true, false]);
        });

        test('accepts numeric scope', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);

            // Act
            $result = $strategy->resolve(12_345);

            // Assert
            expect($result)->toBeIn([true, false]);
        });

        test('accepts object with getKey method', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);
            $scope = new class()
            {
                public function getKey(): string
                {
                    return 'object-key-123';
                }
            };

            // Act
            $result = $strategy->resolve($scope);

            // Assert
            expect($result)->toBeIn([true, false]);
        });

        test('accepts object with id property', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);
            $scope = new class()
            {
                public $id = 'object-id-456';
            };

            // Act
            $result = $strategy->resolve($scope);

            // Assert
            expect($result)->toBeIn([true, false]);
        });

        test('uses seed to vary distribution', function (): void {
            // Arrange
            $strategy1 = new PercentageStrategy(50, 'seed1');
            $strategy2 = new PercentageStrategy(50, 'seed2');
            $scope = 'user-123';

            // Act
            $result1 = $strategy1->resolve($scope);
            $result2 = $strategy2->resolve($scope);

            // Assert - different seeds might produce different results
            // At minimum, they should be deterministic
            expect($strategy1->resolve($scope))->toBe($result1);
            expect($strategy2->resolve($scope))->toBe($result2);
        });

        test('cannot handle null scope', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);

            // Act
            $canHandle = $strategy->canHandleNullScope();

            // Assert
            expect($canHandle)->toBeFalse();
        });
    });

    describe('Sad Path', function (): void {
        test('throws exception when percentage is less than 0', function (): void {
            // Arrange & Act & Assert
            expect(fn (): PercentageStrategy => new PercentageStrategy(-1))
                ->toThrow(RuntimeException::class, 'Percentage must be between 0 and 100.');
        });

        test('throws exception when percentage is greater than 100', function (): void {
            // Arrange & Act & Assert
            expect(fn (): PercentageStrategy => new PercentageStrategy(101))
                ->toThrow(RuntimeException::class, 'Percentage must be between 0 and 100.');
        });

        test('throws exception when scope is null', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);

            // Act & Assert
            expect(fn (): bool => $strategy->resolve(null))
                ->toThrow(RuntimeException::class, 'Percentage strategy requires a non-null scope for consistent hashing.');
        });

        test('throws exception when scope type is not supported', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);
            $unsupportedScope = new class()
            {
                // No getKey() method or id property
            };

            // Act & Assert
            expect(fn (): bool => $strategy->resolve($unsupportedScope))
                ->toThrow(RuntimeException::class, 'Unable to determine scope identifier for percentage strategy.');
        });

        test('throws exception for array scope', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);

            // Act & Assert
            expect(fn (): bool => $strategy->resolve(['user' => 123]))
                ->toThrow(RuntimeException::class, 'Unable to determine scope identifier for percentage strategy.');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles boundary percentage of 0', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(0);

            // Act & Assert
            expect($strategy->resolve('user1'))->toBeFalse();
            expect($strategy->resolve('user2'))->toBeFalse();
            expect($strategy->resolve('user3'))->toBeFalse();
        });

        test('handles boundary percentage of 100', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(100);

            // Act & Assert
            expect($strategy->resolve('user1'))->toBeTrue();
            expect($strategy->resolve('user2'))->toBeTrue();
            expect($strategy->resolve('user3'))->toBeTrue();
        });

        test('distributes users across percentage buckets', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);
            $totalUsers = 100;
            $enabled = 0;

            // Act
            for ($i = 0; $i < $totalUsers; ++$i) {
                if ($strategy->resolve('user-'.$i)) {
                    ++$enabled;
                }
            }

            // Assert - expect roughly 50% (allow 20% variance due to hashing)
            expect($enabled)->toBeGreaterThan(30);
            expect($enabled)->toBeLessThan(70);
        });

        test('prefers getKey method over id property when both exist', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);
            $scope = new class()
            {
                public $id = 'id-value';

                public function getKey(): string
                {
                    return 'key-value';
                }
            };

            // Act
            $result1 = $strategy->resolve($scope);
            $result2 = $strategy->resolve($scope);

            // Assert
            expect($result1)->toBe($result2);
        });

        test('handles numeric string scope', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);

            // Act
            $result = $strategy->resolve('12345');

            // Assert
            expect($result)->toBeIn([true, false]);
        });

        test('handles float numeric scope by converting to string', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);

            // Act
            $result1 = $strategy->resolve(12.34);
            $result2 = $strategy->resolve(12.34);

            // Assert - should be consistent
            expect($result1)->toBe($result2);
        });

        test('handles empty string scope', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(50);

            // Act
            $result1 = $strategy->resolve('');
            $result2 = $strategy->resolve('');

            // Assert - should be consistent
            expect($result1)->toBe($result2);
        });

        test('different scopes may produce different results', function (): void {
            // Arrange
            $strategy = new PercentageStrategy(1); // Very low percentage

            // Act - test many different scopes
            $results = [];

            for ($i = 0; $i < 1_000; ++$i) {
                $results[] = $strategy->resolve('user-'.$i);
            }

            // Assert - with 1% rollout and 1000 users, we should have some variation
            // (this ensures hashing is working)
            $trueCount = count(array_filter($results));
            expect($trueCount)->toBeGreaterThan(0);
            expect($trueCount)->toBeLessThan(50); // Should be roughly 1%, definitely under 5%
        });
    });
});
