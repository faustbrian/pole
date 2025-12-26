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
 * Exception thrown when attempting to set feature values in an unsupported driver.
 *
 * The Gate driver manages features through Laravel's authorization gate system
 * rather than persisting values to storage. Since gates are defined in code,
 * feature values cannot be set dynamically. This exception prevents attempts
 * to programmatically set feature values when using the Gate driver.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CannotSetFeatureValueException extends RuntimeException implements TogglException
{
    /**
     * Create an exception for the Gate driver's inability to set feature values.
     *
     * The Gate driver does not support dynamic value setting because it relies
     * on programmatically defined authorization gates. To configure a feature
     * when using the Gate driver, define or modify the gate in your
     * AuthServiceProvider instead of attempting to set runtime values.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function forGateDriver(): self
    {
        return new self('The Gate driver does not support setting feature values. Define gates instead.');
    }
}
