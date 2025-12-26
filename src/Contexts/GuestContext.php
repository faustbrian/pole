<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contexts;

use Cline\Toggl\Contracts\Serializable;
use Cline\Toggl\Contracts\TogglContextable;
use Cline\Toggl\Support\TogglContext;

/**
 * Represents an unauthenticated guest user context for feature flags.
 *
 * This context is automatically used when checking feature flags without an
 * authenticated user. It provides a consistent, serializable context for
 * guest users, enabling feature flags to work on public/guest routes.
 *
 * All guest users share the same context identifier, meaning feature flags
 * for guests are evaluated globally rather than per-session or per-visitor.
 * For visitor-specific feature flags, consider using session IDs or cookies
 * as custom contexts instead.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class GuestContext implements Serializable, TogglContextable
{
    private const string GUEST_IDENTIFIER = '__toggl_guest__';

    /**
     * Serialize the guest context to a unique identifier.
     *
     * Returns a fixed string identifier that represents all unauthenticated
     * users. This allows feature flags to be consistently evaluated for
     * guests across requests and sessions.
     *
     * @return string The guest context identifier
     */
    public function serialize(): string
    {
        return self::GUEST_IDENTIFIER;
    }

    /**
     * Convert the guest context to a TogglContext for feature operations.
     *
     * Returns a unified context representation with a fixed identifier and
     * type, allowing guest contexts to be used consistently across all
     * feature flag operations.
     *
     * @return TogglContext The context representation for feature operations
     */
    public function toTogglContext(): TogglContext
    {
        return TogglContext::simple(self::GUEST_IDENTIFIER, self::class);
    }
}
