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
 * Base exception for invalid context type errors.
 *
 * This abstract exception serves as the parent for specific context type
 * validation failures in Toggl feature flag operations.
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class InvalidContextTypeException extends RuntimeException implements TogglException {}
