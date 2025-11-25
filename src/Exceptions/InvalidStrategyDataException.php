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
 * Thrown when strategy data does not match the expected type for a feature flag strategy.
 *
 * This exception is raised when feature flag activation strategies receive data
 * in an incompatible format. Different strategies require specific data types:
 * percentage strategies need integer values (0-100), while complex strategies
 * like variant or conditional rollouts require array configuration data.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidStrategyDataException extends InvalidArgumentException
{
    /**
     * Create exception for non-integer data in percentage strategy.
     *
     * Percentage-based rollout strategies require integer values between 0
     * and 100 to perform hash-based user bucketing. Non-integer types cannot
     * be used in the modulo calculations that determine feature availability.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function mustBeInteger(): self
    {
        return new self('Strategy data must be an integer for percentage strategy');
    }

    /**
     * Create exception for non-array data in array-based strategies.
     *
     * Complex strategies like variant distribution, conditional rollouts, or
     * allowlist targeting require array configuration data to specify rules,
     * weights, or target identifiers. Non-array types lack the structure needed
     * to express these multi-value configurations.
     *
     * @param  string $strategy The name of the strategy requiring array data
     * @return self   Exception instance with descriptive error message
     */
    public static function mustBeArray(string $strategy): self
    {
        return new self(
            sprintf('Strategy data must be an array for %s strategy', $strategy),
        );
    }
}
