<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Database\Concerns;

use Cline\Toggl\Enums\PrimaryKeyType;
use Cline\Toggl\Exceptions\InvalidPrimaryKeyValueException;
use Cline\Toggl\Support\PrimaryKeyGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

use function in_array;
use function is_string;

/**
 * Configures model primary keys based on Toggl package configuration.
 *
 * This trait enables automatic primary key type detection and generation based
 * on the `toggl.primary_key_type` configuration. It supports standard auto-incrementing
 * IDs, UUIDs, and ULIDs, automatically generating values during model creation.
 *
 * The trait overrides Eloquent's key behavior to match the configured primary key type,
 * ensuring proper incrementing flags and key types are set automatically.
 *
 * @author Brian Faust <brian@cline.sh>
 */
trait HasTogglPrimaryKey
{
    /**
     * Determine if the model's primary key is auto-incrementing.
     *
     * Returns false when using UUID or ULID keys, true for standard integer IDs.
     *
     * @return bool False for UUID/ULID keys, true otherwise
     */
    public function getIncrementing(): bool
    {
        if (in_array($this->getKeyName(), $this->uniqueIds(), true)) {
            return false;
        }

        return $this->incrementing;
    }

    /**
     * Get the data type of the model's primary key.
     *
     * Returns 'string' for UUID/ULID keys, otherwise returns the model's configured type.
     *
     * @return string The key type ('string' for UUID/ULID, 'int' for standard IDs)
     */
    public function getKeyType(): string
    {
        if (in_array($this->getKeyName(), $this->uniqueIds(), true)) {
            return 'string';
        }

        return $this->keyType;
    }

    /**
     * Generate a new unique identifier for the model.
     *
     * Creates a UUID or ULID based on the configured primary key type.
     * Only called when the model uses non-incrementing unique IDs.
     *
     * @return null|string The generated unique identifier, or null for auto-incrementing IDs
     */
    public function newUniqueId(): ?string
    {
        return PrimaryKeyGenerator::generate()->value;
    }

    /**
     * Get the columns that should use unique identifiers.
     *
     * Returns an array containing the primary key name when using UUID or ULID,
     * or an empty array for standard auto-incrementing IDs.
     *
     * @return list<string> Array of column names requiring unique IDs
     */
    public function uniqueIds(): array
    {
        /** @var int|string $configValue */
        $configValue = Config::get('toggl.primary_key_type', 'id');
        $primaryKeyType = PrimaryKeyType::tryFrom($configValue) ?? PrimaryKeyType::ID;

        return match ($primaryKeyType) {
            PrimaryKeyType::ULID, PrimaryKeyType::UUID => [$this->getKeyName()],
            PrimaryKeyType::ID => [],
        };
    }

    /**
     * Boot the trait and register model event listeners.
     *
     * Automatically generates and assigns primary key values during model creation
     * when using UUID or ULID. Validates that manually set values are strings
     * when required, throwing an exception for invalid types.
     *
     * @throws InvalidPrimaryKeyValueException When a UUID/ULID value is not a string
     */
    protected static function bootHasTogglPrimaryKey(): void
    {
        static::creating(function (Model $model): void {
            $primaryKey = PrimaryKeyGenerator::generate();

            // Skip auto-generation for standard auto-incrementing IDs
            if ($primaryKey->isAutoIncrementing()) {
                return;
            }

            $keyName = $model->getKeyName();
            $existingValue = $model->getAttribute($keyName);

            // Auto-generate if no value was manually set
            if (!$existingValue) {
                $model->setAttribute($keyName, $primaryKey->value);

                return;
            }

            // Validate manually set UUID values are strings
            if ($primaryKey->type === PrimaryKeyType::UUID && !is_string($existingValue)) {
                throw InvalidPrimaryKeyValueException::nonStringUuid($existingValue);
            }

            // Validate manually set ULID values are strings
            if ($primaryKey->type === PrimaryKeyType::ULID && !is_string($existingValue)) {
                throw InvalidPrimaryKeyValueException::nonStringUlid($existingValue);
            }
        });
    }
}
