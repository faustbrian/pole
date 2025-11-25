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
 * Thrown when a percentage value falls outside the valid 0-100 range.
 *
 * This exception is raised during percentage-based feature flag rollouts
 * when specified percentage values do not fall within the valid 0-100 range.
 * Percentages control gradual feature rollout rates and must be mathematically
 * valid for hash-based user bucketing calculations.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidPercentageException extends RuntimeException
{
    /**
     * Create exception for percentage value outside valid range.
     *
     * Percentage-based rollouts use values between 0 (disabled for all) and
     * 100 (enabled for all) to determine feature availability through
     * consistent hash-based bucketing of context identifiers.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function outOfRange(): self
    {
        return new self('Percentage must be between 0 and 100.');
    }
}
