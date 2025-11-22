<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Enums\PrimaryKeyType;
use Cline\Toggl\Support\PrimaryKeyValue;

describe('PrimaryKeyValue', function (): void {
    describe('isAutoIncrementing', function (): void {
        test('returns true for ID type', function (): void {
            // Arrange
            $primaryKey = new PrimaryKeyValue(PrimaryKeyType::ID, null);

            // Act
            $result = $primaryKey->isAutoIncrementing();

            // Assert
            expect($result)->toBeTrue();
        });

        test('returns false for UUID type', function (): void {
            // Arrange
            $primaryKey = new PrimaryKeyValue(PrimaryKeyType::UUID, 'test-uuid');

            // Act
            $result = $primaryKey->isAutoIncrementing();

            // Assert
            expect($result)->toBeFalse();
        });

        test('returns false for ULID type', function (): void {
            // Arrange
            $primaryKey = new PrimaryKeyValue(PrimaryKeyType::ULID, 'test-ulid');

            // Act
            $result = $primaryKey->isAutoIncrementing();

            // Assert
            expect($result)->toBeFalse();
        });
    });

    describe('requiresValue', function (): void {
        test('returns false for ID type', function (): void {
            // Arrange
            $primaryKey = new PrimaryKeyValue(PrimaryKeyType::ID, null);

            // Act
            $result = $primaryKey->requiresValue();

            // Assert
            expect($result)->toBeFalse();
        });

        test('returns true for UUID type', function (): void {
            // Arrange
            $primaryKey = new PrimaryKeyValue(PrimaryKeyType::UUID, 'test-uuid');

            // Act
            $result = $primaryKey->requiresValue();

            // Assert
            expect($result)->toBeTrue();
        });

        test('returns true for ULID type', function (): void {
            // Arrange
            $primaryKey = new PrimaryKeyValue(PrimaryKeyType::ULID, 'test-ulid');

            // Act
            $result = $primaryKey->requiresValue();

            // Assert
            expect($result)->toBeTrue();
        });
    });
});
