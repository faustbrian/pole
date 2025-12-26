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
 * Thrown when attempting to access an undefined feature flag store.
 *
 * This exception is raised when requesting a feature store by name that
 * has not been configured in the application's feature flag configuration.
 * Stores must be defined in the 'toggl.stores' configuration array before
 * they can be used.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class UndefinedFeatureStoreException extends InvalidArgumentException implements TogglException
{
    /**
     * Create exception for an undefined store name.
     *
     * @param  string $name The store name that was not found in configuration
     * @return self   The exception instance
     */
    public static function forName(string $name): self
    {
        return new self(
            sprintf('Feature flag store [%s] is not defined.', $name),
        );
    }
}
