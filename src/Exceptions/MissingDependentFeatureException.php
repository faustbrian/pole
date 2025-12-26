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
 * Thrown when checking feature flag dependencies without specifying the dependent feature.
 *
 * This exception is raised when attempting to verify feature flag ordering or
 * dependency relationships without specifying which feature must be activated
 * first. Feature dependencies ensure that prerequisite features are enabled
 * before dependent features become available.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MissingDependentFeatureException extends RuntimeException implements TogglException
{
    /**
     * Create exception for missing dependent feature specification.
     *
     * Feature flag dependency checks verify that prerequisite features are
     * activated before allowing dependent features to enable. Use the before()
     * method to specify which feature must be active as a prerequisite before
     * the current feature can be enabled.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function notSpecified(): self
    {
        return new self('Dependent feature not specified. Use before() to set it.');
    }
}
