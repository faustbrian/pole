<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * Exception thrown when attempting to list stored features from an unsupported driver.
 *
 * Some feature flag drivers do not support querying or enumerating all stored
 * features, either due to architectural limitations or intentional design choices.
 * This exception prevents attempts to list features when the underlying driver
 * does not provide this capability.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CannotListStoredFeaturesException extends RuntimeException
{
    /**
     * Create an exception for a driver that cannot list stored features.
     *
     * Drivers like the Gate driver manage features in code rather than storage,
     * making it impossible to enumerate all defined features programmatically.
     * This exception indicates that the current driver does not support the
     * feature listing operation.
     *
     * @param  string $driver The name of the driver that does not support listing
     * @return self   Exception instance with driver-specific error message
     */
    public static function forDriver(string $driver): self
    {
        return new self(
            sprintf('The [%s] driver does not support listing stored features.', $driver),
        );
    }
}
