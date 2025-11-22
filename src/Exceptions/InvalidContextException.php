<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use InvalidArgumentException;

/**
 * Exception thrown when a feature context does not meet required type constraints.
 *
 * Feature contexts must be either scalar values (string, int, bool) for simple
 * identification or objects (like Eloquent models) for complex scenarios. This
 * exception enforces type safety and ensures contexts are compatible with the
 * configured driver's requirements.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidContextException extends InvalidArgumentException
{
    /**
     * Create an exception for contexts that are neither objects nor scalars.
     *
     * Feature evaluation requires contexts to be simple scalar values or objects
     * that can be serialized and identified. Arrays and other complex types are
     * not supported as direct contexts. Use an object wrapper or scalar identifier
     * instead.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function mustBeObjectOrScalar(): self
    {
        return new self('Context must be an object or scalar value');
    }

    /**
     * Create an exception for non-Eloquent model contexts with database driver.
     *
     * The database driver requires contexts to be Eloquent model instances with
     * valid IDs for proper polymorphic relationship storage. This ensures feature
     * values can be correctly associated with database records and retrieved
     * efficiently through Eloquent relationships.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function mustBeEloquentModelWithId(): self
    {
        return new self('Context must be an Eloquent Model instance for database driver');
    }
}
