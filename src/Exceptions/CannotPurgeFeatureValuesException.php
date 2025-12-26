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
 * Exception thrown when attempting to purge all feature values from an unsupported driver.
 *
 * The Gate driver manages features through Laravel's authorization gate system
 * rather than persisting values to storage. Since gates are defined in code,
 * they cannot be purged programmatically. This exception prevents attempts
 * to purge all feature values when using the Gate driver.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CannotPurgeFeatureValuesException extends RuntimeException implements TogglException
{
    /**
     * Create an exception for the Gate driver's inability to purge feature values.
     *
     * The Gate driver does not support bulk persistence operations because it relies
     * on programmatically defined authorization gates. To remove all features when
     * using the Gate driver, remove or clear the gate definitions in your
     * AuthServiceProvider instead of attempting to purge stored values.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function forGateDriver(): self
    {
        return new self('The Gate driver does not support purging feature values. Remove gate definitions instead.');
    }
}
