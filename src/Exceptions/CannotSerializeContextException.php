<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a feature context cannot be serialized to a string.
 *
 * Feature contexts must be serializable for caching, storage, and percentage
 * calculation purposes. This exception occurs when a context object does not
 * implement the required Serializable contract or cannot be converted to a
 * string representation for hashing and persistence operations.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CannotSerializeContextException extends RuntimeException implements TogglException
{
    /**
     * Create an exception for a non-serializable context object.
     *
     * When using complex context objects with feature flags, they must implement
     * the Serializable contract to provide a stable string representation. This
     * is essential for percentage-based rollouts and caching mechanisms that
     * require consistent context identification across requests.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function notSerializable(): self
    {
        return new self('Unable to serialize the feature context to a string. You should implement the Serializable contract.');
    }
}
