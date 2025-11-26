<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Conductors\LazyEvaluationConductor;
use Cline\Toggl\Support\LazyEvaluation;
use Tests\Fixtures\TestFeature;

describe('LazyEvaluationConductor', function (): void {
    describe('Happy Path', function (): void {
        test('creates LazyEvaluation with string feature', function (): void {
            // Arrange
            $conductor = new LazyEvaluationConductor('premium');
            $context = (object) ['id' => 1];

            // Act
            $evaluation = $conductor->for($context);

            // Assert
            expect($evaluation)->toBeInstanceOf(LazyEvaluation::class);
            expect($evaluation->feature)->toBe('premium');
            expect($evaluation->context)->toBe($context);
        });

        test('creates LazyEvaluation with BackedEnum feature', function (): void {
            // Arrange
            $conductor = new LazyEvaluationConductor(TestFeature::Premium);
            $context = ['user_id' => 42];

            // Act
            $evaluation = $conductor->for($context);

            // Assert
            expect($evaluation)->toBeInstanceOf(LazyEvaluation::class);
            expect($evaluation->feature)->toBe('premium');
            expect($evaluation->context)->toBe($context);
        });

        test('handles hyphenated feature names from enums', function (): void {
            // Arrange
            $conductor = new LazyEvaluationConductor(TestFeature::Beta);

            // Act
            $evaluation = $conductor->for('user-1');

            // Assert
            expect($evaluation->feature)->toBe('beta-features');
        });

        test('creates multiple evaluations from same conductor', function (): void {
            // Arrange
            $conductor = new LazyEvaluationConductor('premium');
            $context1 = (object) ['id' => 1];
            $context2 = (object) ['id' => 2];

            // Act
            $eval1 = $conductor->for($context1);
            $eval2 = $conductor->for($context2);

            // Assert
            expect($eval1->context)->toBe($context1);
            expect($eval2->context)->toBe($context2);
            expect($eval1)->not->toBe($eval2);
        });
    });

    describe('Edge Cases', function (): void {
        test('handles null context', function (): void {
            // Arrange
            $conductor = new LazyEvaluationConductor('global-feature');

            // Act
            $evaluation = $conductor->for(null);

            // Assert
            expect($evaluation->context)->toBeNull();
        });

        test('handles integer context', function (): void {
            // Arrange
            $conductor = new LazyEvaluationConductor('premium');

            // Act
            $evaluation = $conductor->for(42);

            // Assert
            expect($evaluation->context)->toBe(42);
        });

        test('handles string context', function (): void {
            // Arrange
            $conductor = new LazyEvaluationConductor('premium');

            // Act
            $evaluation = $conductor->for('user-uuid-123');

            // Assert
            expect($evaluation->context)->toBe('user-uuid-123');
        });

        test('handles complex object context', function (): void {
            // Arrange
            $conductor = new LazyEvaluationConductor('premium');
            $context = new class()
            {
                public int $id = 1;

                public string $type = 'user';
            };

            // Act
            $evaluation = $conductor->for($context);

            // Assert
            expect($evaluation->context)->toBe($context);
            expect($evaluation->context->id)->toBe(1);
            expect($evaluation->context->type)->toBe('user');
        });
    });

    describe('Immutability', function (): void {
        test('is readonly and immutable', function (): void {
            // Arrange
            $conductor = new LazyEvaluationConductor('premium');

            // Assert
            $reflection = new ReflectionClass($conductor);
            expect($reflection->isReadOnly())->toBeTrue();
        });

        test('each for() call creates new LazyEvaluation instance', function (): void {
            // Arrange
            $conductor = new LazyEvaluationConductor('premium');
            $context = (object) ['id' => 1];

            // Act
            $eval1 = $conductor->for($context);
            $eval2 = $conductor->for($context);

            // Assert - same values but different instances
            expect($eval1->feature)->toBe($eval2->feature);
            expect($eval1->context)->toBe($eval2->context);
            expect(spl_object_id($eval1))->not->toBe(spl_object_id($eval2));
        });
    });
});
