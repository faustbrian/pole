<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use function get_debug_type;
use function sprintf;

/**
 * Thrown when an unsupported context type is provided.
 *
 * Feature flag operations require context to implement TogglContext or
 * TogglContextable interfaces, or be an Eloquent Model instance. This
 * ensures proper identification and serialization of feature flag scopes.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class UnsupportedContextTypeException extends InvalidContextTypeException
{
    /**
     * Create exception for unsupported context type.
     *
     * @param  mixed $context The invalid context value that was provided
     * @return self  Exception instance with descriptive error message
     */
    public static function forContext(mixed $context): self
    {
        return new self(
            sprintf(
                'Context must be TogglContext, TogglContextable, or Eloquent Model. Got: %s',
                get_debug_type($context),
            ),
        );
    }
}
