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
 * Exception thrown when a context identifier cannot be determined for feature evaluation.
 *
 * The percentage rollout strategy requires a unique, stable identifier from the
 * context to calculate consistent hash-based percentages. This exception occurs
 * when the context does not provide a usable identifier, preventing the percentage
 * calculation from executing reliably.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CannotDetermineContextIdentifierException extends RuntimeException implements TogglException
{
    /**
     * Create an exception for missing context identifier in percentage strategy.
     *
     * The percentage-based rollout strategy uses context identifiers to ensure
     * consistent feature evaluation across requests. When a context object does
     * not implement the necessary identifier method or provides an invalid value,
     * this exception prevents unpredictable percentage calculations.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function forPercentageStrategy(): self
    {
        return new self('Unable to determine context identifier for percentage strategy.');
    }
}
