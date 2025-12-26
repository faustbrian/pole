<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use function get_debug_type;
use function sprintf;

/**
 * Thrown when an Eloquent Model is required but not provided.
 *
 * The database driver requires context to be an Eloquent Model instance
 * to access model properties and relationships for feature flag storage
 * and retrieval operations.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class EloquentModelRequiredException extends InvalidContextTypeException
{
    /**
     * Create exception for non-Eloquent Model context with database driver.
     *
     * @param  mixed $context The invalid context value that was provided
     * @return self  Exception instance with descriptive error message
     */
    public static function forContext(mixed $context): self
    {
        return new self(
            sprintf(
                'Context must be an Eloquent Model instance for database driver. Received: %s',
                get_debug_type($context),
            ),
        );
    }
}
