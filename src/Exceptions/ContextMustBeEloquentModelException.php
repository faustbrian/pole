<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

/**
 * Exception thrown when a context is not an Eloquent model instance for database driver.
 *
 * The database driver requires contexts to be Eloquent model instances with valid IDs
 * for proper polymorphic relationship storage. This ensures feature values can be
 * correctly associated with database records and retrieved efficiently through
 * Eloquent relationships.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ContextMustBeEloquentModelException extends InvalidContextException
{
    /**
     * Create an exception for non-Eloquent model contexts with database driver.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function forDatabaseDriver(): self
    {
        return new self('Context must be an Eloquent Model instance for database driver');
    }
}
