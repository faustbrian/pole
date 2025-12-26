<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use function sprintf;

/**
 * Thrown when strategy data must be an array but received a different type.
 *
 * Complex strategies like variant distribution, conditional rollouts, or
 * allowlist targeting require array configuration data to specify rules,
 * weights, or target identifiers. Non-array types lack the structure needed
 * to express these multi-value configurations.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class StrategyDataMustBeArrayException extends InvalidStrategyDataException
{
    /**
     * Create exception for non-array data in array-based strategies.
     *
     * @param  string $strategy The name of the strategy requiring array data
     * @return self   Exception instance with descriptive error message
     */
    public static function forStrategy(string $strategy): self
    {
        return new self(
            sprintf('Strategy data must be an array for %s strategy', $strategy),
        );
    }
}
