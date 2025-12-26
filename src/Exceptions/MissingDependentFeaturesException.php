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
 * Thrown when managing feature flag lifecycle hooks without specifying affected features.
 *
 * This exception is raised when attempting to configure activation or deactivation
 * hooks for feature flags without specifying which features should be affected by
 * the lifecycle event. Lifecycle hooks enable cascading feature activations or
 * coordinated rollouts across multiple related features.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MissingDependentFeaturesException extends RuntimeException implements TogglException
{
    /**
     * Create exception for missing dependent features in lifecycle hooks.
     *
     * Feature flag lifecycle management allows triggering activation or
     * deactivation of related features automatically. Use the activating()
     * or deactivating() methods to specify which features should be affected
     * when the current feature changes state, enabling coordinated rollouts
     * or cascade deactivations.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function notSpecified(): self
    {
        return new self('No dependent features specified. Use activating() or deactivating().');
    }
}
