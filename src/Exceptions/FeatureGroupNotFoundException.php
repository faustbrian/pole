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
 * Exception thrown when attempting to access an undefined feature group.
 *
 * Feature groups organize related features together for batch operations and
 * management. This exception occurs when code references a feature group name
 * that has not been defined in the configuration or does not exist in the system.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class FeatureGroupNotFoundException extends InvalidArgumentException
{
    /**
     * Create an exception for a feature group that is not defined.
     *
     * Thrown when attempting to access a feature group by name that has not been
     * registered in the application's feature flag configuration. Feature groups
     * must be defined before they can be used to organize and manage features.
     *
     * @param  string $name The name of the undefined feature group
     * @return self   Exception instance with group-specific error message
     */
    public static function forName(string $name): self
    {
        return new self(
            sprintf('Feature group [%s] is not defined.', $name),
        );
    }

    /**
     * Create an exception for a feature group that does not exist.
     *
     * Thrown when attempting to reference a feature group that was previously
     * expected to exist but cannot be found. This may occur during lookups or
     * when validating group membership for features.
     *
     * @param  string $name The name of the non-existent feature group
     * @return self   Exception instance with group-specific error message
     */
    public static function doesNotExist(string $name): self
    {
        return new self(
            sprintf('Feature group [%s] does not exist.', $name),
        );
    }
}
