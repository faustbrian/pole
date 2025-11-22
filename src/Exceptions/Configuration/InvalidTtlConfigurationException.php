<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions\Configuration;

use InvalidArgumentException;

use function gettype;
use function sprintf;

/**
 * Exception thrown when TTL configuration contains an invalid type or value.
 *
 * Feature flag time-to-live (TTL) configuration must be either numeric (representing
 * seconds) or null (for no expiration). This exception prevents invalid configuration
 * types from being used, ensuring cache expiration behaves predictably.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidTtlConfigurationException extends InvalidArgumentException
{
    /**
     * Create an exception for an invalid TTL configuration type.
     *
     * The TTL value in the configuration must be a numeric value representing
     * the number of seconds before cached feature values expire, or null to
     * indicate no expiration. This validation prevents configuration errors
     * that would cause unexpected caching behavior.
     *
     * @param  mixed $value The invalid TTL value that was provided
     * @return self  Exception instance with type-specific error message
     */
    public static function invalidType(mixed $value): self
    {
        return new self(
            sprintf('TTL configuration must be numeric or null, got: %s', gettype($value)),
        );
    }
}
