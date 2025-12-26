<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use InvalidArgumentException;

/**
 * Base exception for invalid feature context errors.
 *
 * Feature contexts must meet specific type constraints depending on the driver
 * being used. This abstract base class allows consumers to catch all context-related
 * validation errors while specific subclasses provide detailed error information.
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class InvalidContextException extends InvalidArgumentException implements TogglException
{
    // Abstract base class - no methods required
}
