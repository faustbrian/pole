<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\EvaluationEntry;

describe('EvaluationEntry', function (): void {
    describe('Happy Path', function (): void {
        test('creates entry with all properties', function (): void {
            // Arrange
            $context = (object) ['id' => 1];

            // Act
            $entry = new EvaluationEntry(
                feature: 'premium',
                contextKey: 'user|1',
                context: $context,
                value: true,
            );

            // Assert
            expect($entry->feature)->toBe('premium');
            expect($entry->contextKey)->toBe('user|1');
            expect($entry->context)->toBe($context);
            expect($entry->value)->toBeTrue();
        });

        test('isActive returns true for truthy value', function (): void {
            // Arrange
            $entry = new EvaluationEntry('premium', 'user|1', null, true);

            // Act & Assert
            expect($entry->isActive())->toBeTrue();
            expect($entry->isInactive())->toBeFalse();
        });

        test('isActive returns true for truthy non-boolean values', function (): void {
            // Arrange
            $entries = [
                new EvaluationEntry('f1', 'k1', null, 1),
                new EvaluationEntry('f2', 'k2', null, 'enabled'),
                new EvaluationEntry('f3', 'k3', null, ['data']),
                new EvaluationEntry('f4', 'k4', null, (object) ['active' => true]),
            ];

            // Act & Assert
            foreach ($entries as $entry) {
                expect($entry->isActive())->toBeTrue();
                expect($entry->isInactive())->toBeFalse();
            }
        });

        test('isInactive returns true for falsy value', function (): void {
            // Arrange
            $entry = new EvaluationEntry('premium', 'user|1', null, false);

            // Act & Assert
            expect($entry->isInactive())->toBeTrue();
            expect($entry->isActive())->toBeFalse();
        });

        test('isInactive returns true for falsy non-boolean values', function (): void {
            // Arrange
            $entries = [
                new EvaluationEntry('f1', 'k1', null, 0),
                new EvaluationEntry('f2', 'k2', null, ''),
                new EvaluationEntry('f3', 'k3', null, []),
                new EvaluationEntry('f4', 'k4', null, null),
            ];

            // Act & Assert
            foreach ($entries as $entry) {
                expect($entry->isInactive())->toBeTrue();
                expect($entry->isActive())->toBeFalse();
            }
        });
    });

    describe('Edge Cases', function (): void {
        test('handles null context', function (): void {
            // Act
            $entry = new EvaluationEntry('premium', 'global', null, true);

            // Assert
            expect($entry->context)->toBeNull();
        });

        test('handles complex context objects', function (): void {
            // Arrange
            $context = new class()
            {
                public int $id = 42;

                public string $name = 'Test User';
            };

            // Act
            $entry = new EvaluationEntry('premium', 'user|42', $context, true);

            // Assert
            expect($entry->context->id)->toBe(42);
            expect($entry->context->name)->toBe('Test User');
        });

        test('handles array context', function (): void {
            // Arrange
            $context = ['id' => 1, 'type' => 'team'];

            // Act
            $entry = new EvaluationEntry('premium', 'team|1', $context, false);

            // Assert
            expect($entry->context)->toBe($context);
        });

        test('handles non-boolean values', function (): void {
            // Arrange - value could be variant string, config object, etc.
            $entry = new EvaluationEntry('theme', 'user|1', null, 'dark-mode');

            // Assert
            expect($entry->value)->toBe('dark-mode');
            expect($entry->isActive())->toBeTrue(); // truthy string
        });

        test('handles zero as inactive', function (): void {
            // Arrange
            $entry = new EvaluationEntry('rollout-percentage', 'user|1', null, 0);

            // Assert
            expect($entry->value)->toBe(0);
            expect($entry->isActive())->toBeFalse();
            expect($entry->isInactive())->toBeTrue();
        });
    });

    describe('Immutability', function (): void {
        test('is readonly and immutable', function (): void {
            // Arrange
            $entry = new EvaluationEntry('premium', 'user|1', null, true);

            // Assert
            $reflection = new ReflectionClass($entry);
            expect($reflection->isReadOnly())->toBeTrue();
        });
    });
});
