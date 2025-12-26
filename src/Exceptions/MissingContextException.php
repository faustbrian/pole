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
 * Thrown when attempting to check feature flags without specifying a context.
 *
 * This exception is raised when calling feature flag methods that require a
 * context (user, team, organization, etc.) without first setting one. Most
 * feature flag operations are context-specific and need a scope to determine
 * which flags apply and what state they should have for that particular entity.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MissingContextException extends RuntimeException implements TogglException
{
    /**
     * Create exception for feature check without context.
     *
     * Feature flag checks are scoped to specific contexts to enable targeted
     * rollouts, user-specific flags, or team-based features. Use the for()
     * method to specify which context (user, team, etc.) the check applies to
     * before calling active() or other state-checking methods.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function notSet(): self
    {
        return new self('No context set for feature check. Use Toggl::for($context)->active() instead of Toggl::active().');
    }
}
