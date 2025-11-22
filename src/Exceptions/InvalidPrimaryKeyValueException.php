<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use InvalidArgumentException;

use function gettype;
use function sprintf;

/**
 * Thrown when attempting to assign invalid value types to string-based primary keys.
 *
 * This exception is raised when non-string values are assigned to model primary
 * keys that require string types, such as UUIDs (Universally Unique Identifiers)
 * or ULIDs (Universally Unique Lexicographically Sortable Identifiers). These
 * identifier formats require string representation for proper storage and querying.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidPrimaryKeyValueException extends InvalidArgumentException
{
    /**
     * Create exception for non-string value assigned to UUID primary key.
     *
     * UUID primary keys require string values in the canonical 8-4-4-4-12
     * hexadecimal format (e.g., "550e8400-e29b-41d4-a716-446655440000").
     * Non-string types cannot be properly validated or stored as UUIDs.
     *
     * @param  mixed $value The invalid value that was provided
     * @return self  Exception instance with descriptive error message
     */
    public static function nonStringUuid(mixed $value): self
    {
        return new self(
            sprintf(
                'Cannot assign non-string value to UUID primary key. Got: %s',
                gettype($value),
            ),
        );
    }

    /**
     * Create exception for non-string value assigned to ULID primary key.
     *
     * ULID primary keys require string values in the 26-character Crockford
     * base32 format (e.g., "01ARZ3NDEKTSV4RRFFQ69G5FAV"). Non-string types
     * cannot maintain the lexicographic sorting and time-ordering properties
     * that make ULIDs useful for distributed systems.
     *
     * @param  mixed $value The invalid value that was provided
     * @return self  Exception instance with descriptive error message
     */
    public static function nonStringUlid(mixed $value): self
    {
        return new self(
            sprintf(
                'Cannot assign non-string value to ULID primary key. Got: %s',
                gettype($value),
            ),
        );
    }
}
