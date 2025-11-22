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
 * Thrown when attempting operations that don't support multiple contexts.
 *
 * This exception prevents ambiguous operations when working with multiple contexts
 * simultaneously. Certain feature operations like retrieving values or variants
 * require a single, specific context and cannot be performed on multiple contexts
 * at once.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MultipleContextException extends RuntimeException
{
    /**
     * Create exception when attempting to retrieve values for multiple contexts.
     *
     * @return self The exception instance
     */
    public static function cannotRetrieveValues(): self
    {
        return new self('It is not possible to retrieve the values for multiple contexts.');
    }

    /**
     * Create exception when attempting to retrieve variants for multiple contexts.
     *
     * @return self The exception instance
     */
    public static function cannotRetrieveVariants(): self
    {
        return new self('It is not possible to retrieve variants for multiple contexts.');
    }
}
