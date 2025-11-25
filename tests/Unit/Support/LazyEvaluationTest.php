<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\LazyEvaluation;
use Tests\Fixtures\TestFeature;

describe('LazyEvaluation', function (): void {
    describe('Happy Path', function (): void {
        test('creates evaluation with string feature and context', function (): void {
            // Arrange
            $context = new stdClass();
            $context->id = 1;

            // Act
            $evaluation = new LazyEvaluation('premium', $context);

            // Assert
            expect($evaluation->feature)->toBe('premium');
            expect($evaluation->context)->toBe($context);
        });

        test('creates evaluation with BackedEnum feature', function (): void {
            // Arrange
            $context = ['user_id' => 42];

            // Act
            $evaluation = new LazyEvaluation(TestFeature::Premium, $context);

            // Assert
            expect($evaluation->feature)->toBe('premium');
            expect($evaluation->context)->toBe($context);
        });

        test('generates unique key for feature-context combination', function (): void {
            // Arrange
            $context = new stdClass();
            $context->id = 1;

            $evaluation = new LazyEvaluation('premium', $context);
            $serializer = fn (mixed $ctx): string => 'user|'.$ctx->id;

            // Act
            $key = $evaluation->getKey($serializer);

            // Assert
            expect($key)->toBe('premium|user|1');
        });

        test('different contexts produce different keys', function (): void {
            // Arrange
            $context1 = (object) ['id' => 1];
            $context2 = (object) ['id' => 2];
            $serializer = fn (mixed $ctx): string => 'user|'.$ctx->id;

            $eval1 = new LazyEvaluation('premium', $context1);
            $eval2 = new LazyEvaluation('premium', $context2);

            // Act & Assert
            expect($eval1->getKey($serializer))->toBe('premium|user|1');
            expect($eval2->getKey($serializer))->toBe('premium|user|2');
            expect($eval1->getKey($serializer))->not->toBe($eval2->getKey($serializer));
        });

        test('different features produce different keys', function (): void {
            // Arrange
            $context = (object) ['id' => 1];
            $serializer = fn (mixed $ctx): string => 'user|'.$ctx->id;

            $eval1 = new LazyEvaluation('premium', $context);
            $eval2 = new LazyEvaluation('analytics', $context);

            // Act & Assert
            expect($eval1->getKey($serializer))->toBe('premium|user|1');
            expect($eval2->getKey($serializer))->toBe('analytics|user|1');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles null context', function (): void {
            // Act
            $evaluation = new LazyEvaluation('premium', null);

            // Assert
            expect($evaluation->context)->toBeNull();
        });

        test('handles integer context', function (): void {
            // Arrange
            $serializer = fn (mixed $ctx): string => 'id|'.$ctx;

            // Act
            $evaluation = new LazyEvaluation('premium', 42);

            // Assert
            expect($evaluation->context)->toBe(42);
            expect($evaluation->getKey($serializer))->toBe('premium|id|42');
        });

        test('handles string context', function (): void {
            // Arrange
            $serializer = fn (mixed $ctx): string => 'key|'.$ctx;

            // Act
            $evaluation = new LazyEvaluation('premium', 'user-uuid-123');

            // Assert
            expect($evaluation->context)->toBe('user-uuid-123');
            expect($evaluation->getKey($serializer))->toBe('premium|key|user-uuid-123');
        });

        test('handles array context', function (): void {
            // Arrange
            $context = ['type' => 'team', 'id' => 5];
            $serializer = fn (mixed $ctx): string => $ctx['type'].'|'.$ctx['id'];

            // Act
            $evaluation = new LazyEvaluation('premium', $context);

            // Assert
            expect($evaluation->context)->toBe($context);
            expect($evaluation->getKey($serializer))->toBe('premium|team|5');
        });

        test('normalizes BackedEnum with non-string-like value', function (): void {
            // Act
            $evaluation = new LazyEvaluation(TestFeature::Analytics, 'context');

            // Assert
            expect($evaluation->feature)->toBe('analytics');
        });
    });

    describe('Immutability', function (): void {
        test('is readonly and immutable', function (): void {
            // Arrange
            $evaluation = new LazyEvaluation('premium', 'context');

            // Assert - readonly class properties cannot be modified
            $reflection = new ReflectionClass($evaluation);
            expect($reflection->isReadOnly())->toBeTrue();
        });
    });
});
