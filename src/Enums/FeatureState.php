<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Enums;

/**
 * Feature flag state enum.
 *
 * Represents the three possible states of a feature flag:
 * - Active: Feature is explicitly enabled
 * - Inactive: Feature is explicitly disabled/forbidden
 * - Undefined: Feature has never been set
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum FeatureState
{
    case Active;

    case Inactive;

    case Undefined;
}
