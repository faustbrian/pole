<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Exceptions\InvalidPrimaryKeyValueException;

/**
 * InvalidPrimaryKeyValueException Test Suite
 *
 * Tests the InvalidPrimaryKeyValueException class's static factory methods:
 * - nonStringUuid: Creates exception for non-string UUID values
 * - nonStringUlid: Creates exception for non-string ULID values
 *
 * Verifies that:
 * - Exception is properly instantiated
 * - Exception extends InvalidArgumentException
 * - Error messages include the correct type information
 * - Works with various PHP types (int, float, array, object, null, bool)
 */
describe('InvalidPrimaryKeyValueException', function (): void {
    describe('nonStringUuid', function (): void {
        describe('Happy Path', function (): void {
            test('creates exception with integer value', function (): void {
                // Arrange
                $value = 123;

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUuid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception)->toBeInstanceOf(InvalidArgumentException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to UUID primary key. Got: integer');
            });

            test('creates exception with array value', function (): void {
                // Arrange
                $value = ['uuid' => '123'];

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUuid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to UUID primary key. Got: array');
            });

            test('creates exception with object value', function (): void {
                // Arrange
                $value = new stdClass();

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUuid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to UUID primary key. Got: object');
            });

            test('creates exception with null value', function (): void {
                // Arrange
                $value = null;

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUuid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to UUID primary key. Got: NULL');
            });

            test('creates exception with boolean value', function (): void {
                // Arrange
                $value = true;

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUuid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to UUID primary key. Got: boolean');
            });

            test('creates exception with float value', function (): void {
                // Arrange
                $value = 123.45;

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUuid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to UUID primary key. Got: double');
            });
        });

        describe('Edge Cases', function (): void {
            test('creates exception with resource value', function (): void {
                // Arrange
                $value = fopen('php://memory', 'rb');

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUuid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to UUID primary key. Got: resource');

                // Cleanup
                fclose($value);
            });

            test('creates exception with empty array', function (): void {
                // Arrange
                $value = [];

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUuid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to UUID primary key. Got: array');
            });

            test('creates exception with callable value', function (): void {
                // Arrange
                $value = function (): void {};

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUuid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to UUID primary key. Got: object');
            });
        });
    });

    describe('nonStringUlid', function (): void {
        describe('Happy Path', function (): void {
            test('creates exception with integer value', function (): void {
                // Arrange
                $value = 123;

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUlid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception)->toBeInstanceOf(InvalidArgumentException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to ULID primary key. Got: integer');
            });

            test('creates exception with array value', function (): void {
                // Arrange
                $value = ['ulid' => '123'];

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUlid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to ULID primary key. Got: array');
            });

            test('creates exception with object value', function (): void {
                // Arrange
                $value = new stdClass();

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUlid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to ULID primary key. Got: object');
            });

            test('creates exception with null value', function (): void {
                // Arrange
                $value = null;

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUlid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to ULID primary key. Got: NULL');
            });

            test('creates exception with boolean value', function (): void {
                // Arrange
                $value = false;

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUlid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to ULID primary key. Got: boolean');
            });

            test('creates exception with float value', function (): void {
                // Arrange
                $value = 678.90;

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUlid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to ULID primary key. Got: double');
            });
        });

        describe('Edge Cases', function (): void {
            test('creates exception with resource value', function (): void {
                // Arrange
                $value = fopen('php://memory', 'rb');

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUlid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to ULID primary key. Got: resource');

                // Cleanup
                fclose($value);
            });

            test('creates exception with empty array', function (): void {
                // Arrange
                $value = [];

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUlid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to ULID primary key. Got: array');
            });

            test('creates exception with callable value', function (): void {
                // Arrange
                $value = function (): void {};

                // Act
                $exception = InvalidPrimaryKeyValueException::nonStringUlid($value);

                // Assert
                expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot assign non-string value to ULID primary key. Got: object');
            });
        });
    });

    describe('Inheritance', function (): void {
        test('both methods return instances that extend InvalidArgumentException', function (): void {
            // Arrange & Act
            $uuidException = InvalidPrimaryKeyValueException::nonStringUuid(123);
            $ulidException = InvalidPrimaryKeyValueException::nonStringUlid(456);

            // Assert
            expect($uuidException)->toBeInstanceOf(InvalidArgumentException::class);
            expect($ulidException)->toBeInstanceOf(InvalidArgumentException::class);
        });

        test('both methods return instances of InvalidPrimaryKeyValueException', function (): void {
            // Arrange & Act
            $uuidException = InvalidPrimaryKeyValueException::nonStringUuid(123);
            $ulidException = InvalidPrimaryKeyValueException::nonStringUlid(456);

            // Assert
            expect($uuidException)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
            expect($ulidException)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
        });
    });

    describe('Message Format', function (): void {
        test('UUID exception message follows expected format', function (): void {
            // Arrange
            $value = 123;
            $expectedFormat = 'Cannot assign non-string value to UUID primary key. Got: integer';

            // Act
            $exception = InvalidPrimaryKeyValueException::nonStringUuid($value);

            // Assert
            expect($exception->getMessage())->toBe($expectedFormat);
        });

        test('ULID exception message follows expected format', function (): void {
            // Arrange
            $value = 456;
            $expectedFormat = 'Cannot assign non-string value to ULID primary key. Got: integer';

            // Act
            $exception = InvalidPrimaryKeyValueException::nonStringUlid($value);

            // Assert
            expect($exception->getMessage())->toBe($expectedFormat);
        });

        test('messages differ between UUID and ULID variants', function (): void {
            // Arrange & Act
            $uuidException = InvalidPrimaryKeyValueException::nonStringUuid(123);
            $ulidException = InvalidPrimaryKeyValueException::nonStringUlid(123);

            // Assert
            expect($uuidException->getMessage())->not->toBe($ulidException->getMessage());
            expect($uuidException->getMessage())->toContain('UUID');
            expect($ulidException->getMessage())->toContain('ULID');
        });
    });
});
