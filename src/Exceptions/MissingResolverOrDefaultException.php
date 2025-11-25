<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * Thrown when a feature is defined without a resolver or default value.
 *
 * This exception ensures that all features have a mechanism to determine their
 * state. Every feature must either have a resolver callback via resolvedBy() or
 * a default value via defaultTo() to be properly configured.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MissingResolverOrDefaultException extends RuntimeException
{
    /**
     * Create exception for a feature without resolver or default.
     *
     * @param  string $featureName The feature name that is missing configuration
     * @return self   The exception instance
     */
    public static function forFeature(string $featureName): self
    {
        return new self(
            sprintf("Feature '%s' must have a resolver or default value. Use resolvedBy() or defaultTo().", $featureName),
        );
    }
}
