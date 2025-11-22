<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use RuntimeException;

use function get_debug_type;
use function sprintf;

/**
 * Thrown when an invalid context type is provided to feature flag operations.
 *
 * This exception is raised when context objects passed to Toggl feature flag
 * methods do not match the expected type requirements. The database driver
 * requires Eloquent Model instances, while other operations accept TogglContext,
 * TogglContextable, or Eloquent Model types.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidContextTypeException extends RuntimeException
{
    /**
     * Create exception for non-Eloquent Model context with database driver.
     *
     * The database driver requires context to be an Eloquent Model instance
     * to access model properties and relationships for feature flag storage
     * and retrieval operations.
     *
     * @param  mixed $context The invalid context value that was provided
     * @return self  Exception instance with descriptive error message
     */
    public static function mustBeEloquentModel(mixed $context): self
    {
        return new self(
            sprintf(
                'Context must be an Eloquent Model instance for database driver. Received: %s',
                get_debug_type($context),
            ),
        );
    }

    /**
     * Create exception for unsupported context type.
     *
     * Feature flag operations require context to implement TogglContext or
     * TogglContextable interfaces, or be an Eloquent Model instance. This
     * ensures proper identification and serialization of feature flag scopes.
     *
     * @param  mixed $context The invalid context value that was provided
     * @return self  Exception instance with descriptive error message
     */
    public static function unsupportedType(mixed $context): self
    {
        return new self(
            sprintf(
                'Context must be TogglContext, TogglContextable, or Eloquent Model. Got: %s',
                get_debug_type($context),
            ),
        );
    }
}
