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
 * Thrown when percentage-based feature strategy requirements are violated.
 *
 * This exception is raised when using percentage-based rollout strategies
 * that require a valid context for consistent hashing. A null context cannot
 * be used because the hashing algorithm needs a stable identifier to ensure
 * the same user always gets the same variant/activation state.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PercentageStrategyException extends RuntimeException
{
    /**
     * Create exception when a null context is provided to percentage strategy.
     *
     * Percentage-based strategies use consistent hashing to ensure deterministic
     * feature rollout, which requires a non-null context identifier.
     *
     * @return self The exception instance
     */
    public static function requiresNonNullContext(): self
    {
        return new self('Percentage strategy requires a non-null context for consistent hashing.');
    }
}
