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
 * Base exception for variant weight configuration errors in A/B testing.
 *
 * This abstract base class represents all exceptions related to invalid
 * variant weight arrays used for A/B testing and multivariate feature
 * rollouts. Weights must meet mathematical requirements to ensure proper
 * probability distribution across all variants.
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class InvalidVariantWeightsException extends InvalidArgumentException implements TogglException
{
    // Abstract base class - see concrete implementations
}
