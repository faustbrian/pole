<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Strategies\BooleanStrategy;

/**
 * Test suite for BooleanStrategy functionality.
 *
 * Validates simple boolean feature flag resolution where features are either
 * globally enabled or disabled regardless of context. Tests context-independence,
 * null context handling, and consistent resolution across multiple invocations.
 * This strategy is the simplest form of feature flagging for binary on/off states.
 */
describe('BooleanStrategy', function (): void {
    describe('Happy Path', function (): void {
        test('resolves to true when initialized with true', function (): void {
            // Arrange
            $strategy = new BooleanStrategy(true);

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeTrue();
        });

        test('resolves to false when initialized with false', function (): void {
            // Arrange
            $strategy = new BooleanStrategy(false);

            // Act
            $result = $strategy->resolve(null);

            // Assert
            expect($result)->toBeFalse();
        });

        test('ignores context parameter and always returns configured value', function (): void {
            // Arrange
            $strategy = new BooleanStrategy(true);
            $contexts = ['user', 123, null, new stdClass(), ['array']];

            // Act & Assert
            foreach ($contexts as $context) {
                expect($strategy->resolve($context))->toBeTrue();
            }
        });

        test('can handle null context', function (): void {
            // Arrange
            $strategy = new BooleanStrategy(true);

            // Act
            $canHandle = $strategy->canHandleNullContext();

            // Assert
            expect($canHandle)->toBeTrue();
        });
    });

    describe('Edge Cases', function (): void {
        test('returns same value across multiple resolve calls', function (): void {
            // Arrange
            $strategy = new BooleanStrategy(true);

            // Act
            $result1 = $strategy->resolve('context1');
            $result2 = $strategy->resolve('context2');
            $result3 = $strategy->resolve(null);

            // Assert
            expect($result1)->toBe($result2);
            expect($result2)->toBe($result3);
            expect($result1)->toBeTrue();
        });

        test('works with different context types', function (): void {
            // Arrange
            $strategy = new BooleanStrategy(false);

            // Act & Assert
            expect($strategy->resolve('string'))->toBeFalse();
            expect($strategy->resolve(123))->toBeFalse();
            expect($strategy->resolve(12.34))->toBeFalse();
            expect($strategy->resolve(true))->toBeFalse();
            expect($strategy->resolve(false))->toBeFalse();
            expect($strategy->resolve([]))->toBeFalse();
            expect($strategy->resolve(
                new stdClass(),
            ))->toBeFalse();
        });
    });
});
