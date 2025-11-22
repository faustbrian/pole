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
 * Thrown when Pennant migration operations fail.
 *
 * This exception is raised during data migration processes when migrating
 * feature flag contexts from one storage backend to another. Indicates that
 * the migration failed to successfully transfer any context data.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PennantMigrationException extends RuntimeException
{
    /**
     * Create exception when no contexts were successfully migrated.
     *
     * @return self The exception instance
     */
    public static function noContextsMigrated(): self
    {
        return new self('No contexts were successfully migrated');
    }
}
