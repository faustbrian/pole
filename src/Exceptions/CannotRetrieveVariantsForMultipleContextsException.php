<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

/**
 * Thrown when attempting to retrieve variants for multiple contexts.
 *
 * This exception is raised when calling variant retrieval operations on a feature
 * interaction with multiple contexts set. Variant retrieval requires a single,
 * specific context to determine the correct feature variant.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CannotRetrieveVariantsForMultipleContextsException extends MultipleContextException
{
    /**
     * Create exception instance.
     *
     * @return self The exception instance
     */
    public static function create(): self
    {
        return new self('It is not possible to retrieve variants for multiple contexts.');
    }
}
