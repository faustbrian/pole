<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Exceptions\InvalidPrimaryKeyValueException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\Fixtures\UlidModel;
use Tests\Fixtures\User;
use Tests\Fixtures\UuidModel;

uses(RefreshDatabase::class);

describe('HasTogglPrimaryKey', function (): void {
    describe('Happy Paths', function (): void {
        test('getIncrementing returns true for ID primary key type', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'id');
            $model = new User();

            // Act
            $result = $model->getIncrementing();

            // Assert
            expect($result)->toBe(true);
        });

        test('getIncrementing returns false for UUID primary key type', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');
            $model = new UuidModel();

            // Act
            $result = $model->getIncrementing();

            // Assert
            expect($result)->toBe(false);
        });

        test('getIncrementing returns false for ULID primary key type', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');
            $model = new UlidModel();

            // Act
            $result = $model->getIncrementing();

            // Assert
            expect($result)->toBe(false);
        });

        test('getKeyType returns int for ID primary key type', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'id');
            $model = new User();

            // Act
            $result = $model->getKeyType();

            // Assert
            expect($result)->toBe('int');
        });

        test('getKeyType returns string for UUID primary key type', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');
            $model = new UuidModel();

            // Act
            $result = $model->getKeyType();

            // Assert
            expect($result)->toBe('string');
        });

        test('getKeyType returns string for ULID primary key type', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');
            $model = new UlidModel();

            // Act
            $result = $model->getKeyType();

            // Assert
            expect($result)->toBe('string');
        });

        test('newUniqueId generates UUID when configured', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');
            $model = new UuidModel();

            // Act
            $result = $model->newUniqueId();

            // Assert
            expect($result)->not->toBeNull()
                ->and($result)->toBeString()
                ->and(Str::isUuid($result))->toBe(true)
                ->and($result)->toMatch('/^[a-f0-9-]{36}$/');
        });

        test('newUniqueId generates ULID when configured', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');
            $model = new UlidModel();

            // Act
            $result = $model->newUniqueId();

            // Assert
            expect($result)->not->toBeNull()
                ->and($result)->toBeString()
                ->and(Str::isUlid($result))->toBe(true)
                ->and($result)->toMatch('/^[0123456789abcdefghjkmnpqrstvwxyz]{26}$/');
        });

        test('newUniqueId returns null for ID primary key type', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'id');
            $model = new User();

            // Act
            $result = $model->newUniqueId();

            // Assert
            expect($result)->toBeNull();
        });

        test('uniqueIds returns empty array for ID primary key type', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'id');
            $model = new User();

            // Act
            $result = $model->uniqueIds();

            // Assert
            expect($result)->toBe([]);
        });

        test('uniqueIds returns key name for UUID primary key type', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');
            $model = new UuidModel();

            // Act
            $result = $model->uniqueIds();

            // Assert
            expect($result)->toBe(['id']);
        });

        test('uniqueIds returns key name for ULID primary key type', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');
            $model = new UlidModel();

            // Act
            $result = $model->uniqueIds();

            // Assert
            expect($result)->toBe(['id']);
        });

        test('bootHasTogglPrimaryKey does not set ID for auto-incrementing keys', function (): void {
            // Skip if database schema uses ULID/UUID columns (not auto-increment)
            if (in_array(env('TOGGL_PRIMARY_KEY_TYPE', 'id'), ['ulid', 'uuid'], true)) {
                $this->markTestSkipped('Database schema uses ULID/UUID columns, not auto-increment');
            }

            // Arrange
            Config::set('toggl.primary_key_type', 'id');

            // Act
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Assert
            expect($user->id)->not->toBeNull()
                ->and($user->id)->toBeInt();
        });

        test('bootHasTogglPrimaryKey auto-generates UUID when not provided', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');

            // Act
            $model = UuidModel::query()->create(['name' => 'Test']);

            // Assert
            expect($model->id)->not->toBeNull()
                ->and($model->id)->toBeString()
                ->and(Str::isUuid($model->id))->toBe(true);
        });

        test('bootHasTogglPrimaryKey auto-generates ULID when not provided', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');

            // Act
            $model = UlidModel::query()->create(['name' => 'Test']);

            // Assert
            expect($model->id)->not->toBeNull()
                ->and($model->id)->toBeString()
                ->and(Str::isUlid($model->id))->toBe(true);
        });

        test('bootHasTogglPrimaryKey accepts existing valid UUID', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');
            $existingUuid = Str::lower((string) Str::uuid());

            // Act
            $model = UuidModel::query()->create(['id' => $existingUuid, 'name' => 'Test']);

            // Assert
            expect($model->id)->toBe($existingUuid);
        });

        test('bootHasTogglPrimaryKey accepts existing valid ULID', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');
            $existingUlid = Str::lower((string) Str::ulid());

            // Act
            $model = UlidModel::query()->create(['id' => $existingUlid, 'name' => 'Test']);

            // Assert
            expect($model->id)->toBe($existingUlid);
        });
    });

    describe('Sad Paths', function (): void {
        test('bootHasTogglPrimaryKey throws exception for non-string UUID value', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');

            // Act & Assert
            expect(fn () => UuidModel::query()->create(['id' => 12_345, 'name' => 'Test']))
                ->toThrow(InvalidPrimaryKeyValueException::class, 'Cannot assign non-string value to UUID primary key. Got: integer');
        });

        test('bootHasTogglPrimaryKey throws exception for non-string ULID value', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');

            // Act & Assert
            expect(fn () => UlidModel::query()->create(['id' => 12_345, 'name' => 'Test']))
                ->toThrow(InvalidPrimaryKeyValueException::class, 'Cannot assign non-string value to ULID primary key. Got: integer');
        });

        test('bootHasTogglPrimaryKey throws exception for array UUID value', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');

            // Act & Assert
            expect(fn () => UuidModel::query()->create(['id' => ['invalid'], 'name' => 'Test']))
                ->toThrow(InvalidPrimaryKeyValueException::class, 'Cannot assign non-string value to UUID primary key. Got: array');
        });

        test('bootHasTogglPrimaryKey throws exception for array ULID value', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');

            // Act & Assert
            expect(fn () => UlidModel::query()->create(['id' => ['invalid'], 'name' => 'Test']))
                ->toThrow(InvalidPrimaryKeyValueException::class, 'Cannot assign non-string value to ULID primary key. Got: array');
        });

        test('bootHasTogglPrimaryKey throws exception for boolean UUID value', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');

            // Act & Assert
            expect(fn () => UuidModel::query()->create(['id' => true, 'name' => 'Test']))
                ->toThrow(InvalidPrimaryKeyValueException::class, 'Cannot assign non-string value to UUID primary key. Got: boolean');
        });

        test('bootHasTogglPrimaryKey throws exception for boolean ULID value', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');

            // Act & Assert
            expect(fn () => UlidModel::query()->create(['id' => true, 'name' => 'Test']))
                ->toThrow(InvalidPrimaryKeyValueException::class, 'Cannot assign non-string value to ULID primary key. Got: boolean');
        });

        test('bootHasTogglPrimaryKey throws exception for object UUID value', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');

            // Act & Assert
            expect(fn () => UuidModel::query()->create(['id' => (object) ['key' => 'value'], 'name' => 'Test']))
                ->toThrow(InvalidPrimaryKeyValueException::class, 'Cannot assign non-string value to UUID primary key. Got: object');
        });

        test('bootHasTogglPrimaryKey throws exception for object ULID value', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');

            // Act & Assert
            expect(fn () => UlidModel::query()->create(['id' => (object) ['key' => 'value'], 'name' => 'Test']))
                ->toThrow(InvalidPrimaryKeyValueException::class, 'Cannot assign non-string value to ULID primary key. Got: object');
        });

        test('bootHasTogglPrimaryKey throws exception for float UUID value', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');

            // Act & Assert
            expect(fn () => UuidModel::query()->create(['id' => 123.45, 'name' => 'Test']))
                ->toThrow(InvalidPrimaryKeyValueException::class, 'Cannot assign non-string value to UUID primary key. Got: double');
        });

        test('bootHasTogglPrimaryKey throws exception for float ULID value', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');

            // Act & Assert
            expect(fn () => UlidModel::query()->create(['id' => 123.45, 'name' => 'Test']))
                ->toThrow(InvalidPrimaryKeyValueException::class, 'Cannot assign non-string value to ULID primary key. Got: double');
        });
    });

    describe('Edge Cases', function (): void {
        test('getIncrementing respects custom incrementing property for ID type', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'id');
            $model = new User();
            $model->incrementing = false;

            // Act
            $result = $model->getIncrementing();

            // Assert
            expect($result)->toBe(false);
        });

        test('getKeyType returns original keyType when not in uniqueIds', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'id');
            $model = new User();

            // Act
            $result = $model->getKeyType();

            // Assert - For ID type, should return the model's default keyType (int)
            expect($result)->toBe('int');
        });

        test('newUniqueId generates unique values on multiple calls for UUID', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');
            $model = new UuidModel();

            // Act
            $id1 = $model->newUniqueId();
            $id2 = $model->newUniqueId();
            $id3 = $model->newUniqueId();

            // Assert
            expect($id1)->not->toBe($id2)
                ->and($id2)->not->toBe($id3)
                ->and($id1)->not->toBe($id3)
                ->and(Str::isUuid($id1))->toBe(true)
                ->and(Str::isUuid($id2))->toBe(true)
                ->and(Str::isUuid($id3))->toBe(true);
        });

        test('newUniqueId generates unique values on multiple calls for ULID', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');
            $model = new UlidModel();

            // Act
            $id1 = $model->newUniqueId();
            $id2 = $model->newUniqueId();
            $id3 = $model->newUniqueId();

            // Assert
            expect($id1)->not->toBe($id2)
                ->and($id2)->not->toBe($id3)
                ->and($id1)->not->toBe($id3)
                ->and(Str::isUlid($id1))->toBe(true)
                ->and(Str::isUlid($id2))->toBe(true)
                ->and(Str::isUlid($id3))->toBe(true);
        });

        test('uniqueIds handles invalid config value gracefully', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'invalid-type');
            $model = new User();

            // Act
            $result = $model->uniqueIds();

            // Assert
            expect($result)->toBe([]);
        });

        test('bootHasTogglPrimaryKey handles empty string as no value for UUID', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');

            // Act
            $model = UuidModel::query()->create(['id' => '', 'name' => 'Test']);

            // Assert - empty string should trigger auto-generation
            expect($model->id)->not->toBe('')
                ->and($model->id)->toBeString()
                ->and(Str::isUuid($model->id))->toBe(true);
        });

        test('bootHasTogglPrimaryKey handles empty string as no value for ULID', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');

            // Act
            $model = UlidModel::query()->create(['id' => '', 'name' => 'Test']);

            // Assert - empty string should trigger auto-generation
            expect($model->id)->not->toBe('')
                ->and($model->id)->toBeString()
                ->and(Str::isUlid($model->id))->toBe(true);
        });

        test('bootHasTogglPrimaryKey handles null value for UUID', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');

            // Act
            $model = UuidModel::query()->create(['id' => null, 'name' => 'Test']);

            // Assert
            expect($model->id)->not->toBeNull()
                ->and($model->id)->toBeString()
                ->and(Str::isUuid($model->id))->toBe(true);
        });

        test('bootHasTogglPrimaryKey handles null value for ULID', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');

            // Act
            $model = UlidModel::query()->create(['id' => null, 'name' => 'Test']);

            // Assert
            expect($model->id)->not->toBeNull()
                ->and($model->id)->toBeString()
                ->and(Str::isUlid($model->id))->toBe(true);
        });

        test('newUniqueId generates lowercase UUIDs', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'uuid');
            $model = new UuidModel();

            // Act
            $result = $model->newUniqueId();

            // Assert
            expect($result)->toBe(mb_strtolower($result))
                ->and($result)->toMatch('/^[a-f0-9-]{36}$/');
        });

        test('newUniqueId generates lowercase ULIDs', function (): void {
            // Arrange
            Config::set('toggl.primary_key_type', 'ulid');
            $model = new UlidModel();

            // Act
            $result = $model->newUniqueId();

            // Assert
            expect($result)->toBe(mb_strtolower($result))
                ->and($result)->toMatch('/^[0123456789abcdefghjkmnpqrstvwxyz]{26}$/');
        });
    });
});
