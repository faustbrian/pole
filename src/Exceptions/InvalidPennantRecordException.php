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
 * Base exception for invalid Pennant feature flag records.
 *
 * This abstract exception serves as the parent for specific validation errors
 * that occur when database records storing feature flag states do not conform
 * to the expected schema. Valid records must include both a context property
 * (identifying the scope) and a value property (storing the flag state or variant).
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class InvalidPennantRecordException extends RuntimeException implements TogglException {}
