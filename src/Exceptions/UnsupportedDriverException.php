<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use InvalidArgumentException;

use function sprintf;

/**
 * Thrown when attempting to use an unsupported feature flag driver.
 *
 * This exception is raised when a feature store configuration references
 * a driver type that doesn't have a corresponding creator method or custom
 * creator registered. Supported built-in drivers include array, database,
 * cache, and gate.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class UnsupportedDriverException extends InvalidArgumentException
{
    /**
     * Create exception for an unsupported driver type.
     *
     * @param  string $driver The driver type that is not supported
     * @return self   The exception instance
     */
    public static function forDriver(string $driver): self
    {
        return new self(
            sprintf('Driver [%s] is not supported.', $driver),
        );
    }
}
