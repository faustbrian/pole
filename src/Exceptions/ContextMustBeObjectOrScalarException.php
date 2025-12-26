<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

/**
 * Exception thrown when a feature context is neither an object nor a scalar value.
 *
 * Feature evaluation requires contexts to be simple scalar values (string, int, bool)
 * or objects that can be serialized and identified. Arrays and other complex types are
 * not supported as direct contexts. Use an object wrapper or scalar identifier instead.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ContextMustBeObjectOrScalarException extends InvalidContextException
{
    /**
     * Create an exception for contexts that are neither objects nor scalars.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function create(): self
    {
        return new self('Context must be an object or scalar value');
    }
}
