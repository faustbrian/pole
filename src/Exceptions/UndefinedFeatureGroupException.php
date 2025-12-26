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
 * Exception thrown when attempting to access a feature group that is not defined.
 *
 * Thrown when attempting to access a feature group by name that has not been
 * registered in the application's feature flag configuration. Feature groups
 * must be defined before they can be used to organize and manage features.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class UndefinedFeatureGroupException extends FeatureGroupNotFoundException
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
}
