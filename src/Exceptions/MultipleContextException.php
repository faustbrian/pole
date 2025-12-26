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
 * Base exception for operations that don't support multiple contexts.
 *
 * This exception prevents ambiguous operations when working with multiple contexts
 * simultaneously. Certain feature operations like retrieving values or variants
 * require a single, specific context and cannot be performed on multiple contexts
 * at once.
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class MultipleContextException extends RuntimeException implements TogglException {}
