<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Events;

use Cline\Toggl\Support\TogglContext;

/**
 * Event fired when a feature is deactivated.
 *
 * Dispatched whenever a feature is explicitly set to false for a specific context.
 * Useful for auditing feature changes, revoking access, or logging feature deactivation
 * patterns for compliance and monitoring purposes.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class FeatureDeactivated
{
    /**
     * Create a new Feature Deactivated event.
     *
     * @param string       $feature The name of the feature that was deactivated
     * @param TogglContext $context The context (user, team, etc.) for which the feature was deactivated
     */
    public function __construct(
        public string $feature,
        public TogglContext $context,
    ) {}
}
