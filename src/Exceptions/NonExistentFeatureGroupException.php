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
 * Exception thrown when attempting to reference a feature group that does not exist.
 *
 * Thrown when attempting to reference a feature group that was previously
 * expected to exist but cannot be found. This may occur during lookups or
 * when validating feature group membership for features.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class NonExistentFeatureGroupException extends FeatureGroupNotFoundException
{
    /**
     * Create an exception for a feature group that does not exist.
     *
     * Thrown when attempting to reference a feature group that was previously
     * expected to exist but cannot be found. This may occur during lookups or
     * when validating feature group membership for features.
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
