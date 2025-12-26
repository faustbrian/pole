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
 * Base exception for feature group-related errors.
 *
 * Feature groups organize related features together for batch operations and
 * management. This abstract exception serves as the base for all feature group
 * related exceptions in the system.
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class FeatureGroupNotFoundException extends InvalidArgumentException implements TogglException {}
