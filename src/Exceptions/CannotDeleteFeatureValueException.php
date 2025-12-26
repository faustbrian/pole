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
 * Exception thrown when attempting to delete feature values from an unsupported driver.
 *
 * The Gate driver manages features through Laravel's authorization gate system
 * rather than persisting values to storage. Since gates are defined in code,
 * they cannot be deleted programmatically. This exception prevents attempts
 * to delete feature values when using the Gate driver.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CannotDeleteFeatureValueException extends RuntimeException implements TogglException
{
    /**
     * Create an exception for the Gate driver's inability to delete feature values.
     *
     * The Gate driver does not support persistence operations because it relies
     * on programmatically defined authorization gates. To remove a feature when
     * using the Gate driver, remove or modify the gate definition in your
     * AuthServiceProvider instead of attempting to delete stored values.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function forGateDriver(): self
    {
        return new self('The Gate driver does not support deleting feature values. Remove gate definitions instead.');
    }
}
