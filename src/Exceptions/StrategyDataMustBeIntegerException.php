<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

/**
 * Thrown when strategy data must be an integer but received a different type.
 *
 * Percentage-based rollout strategies require integer values between 0
 * and 100 to perform hash-based user bucketing. Non-integer types cannot
 * be used in the modulo calculations that determine feature availability.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class StrategyDataMustBeIntegerException extends InvalidStrategyDataException
{
    /**
     * Create exception for non-integer data in percentage strategy.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function create(): self
    {
        return new self('Strategy data must be an integer for percentage strategy');
    }
}
