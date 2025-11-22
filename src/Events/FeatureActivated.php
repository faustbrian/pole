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
 * Event fired when a feature is activated.
 *
 * Dispatched whenever a feature is set to any value other than false for a specific
 * context. Useful for auditing feature changes, triggering side effects, or logging
 * feature activation patterns.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class FeatureActivated
{
    /**
     * Create a new Feature Activated event.
     *
     * @param string       $feature The name of the feature that was activated
     * @param mixed        $value   The value the feature was set to (typically true, but can be any non-false value)
     * @param TogglContext $context The context (user, team, etc.) for which the feature was activated
     */
    public function __construct(
        public string $feature,
        public mixed $value,
        public TogglContext $context,
    ) {}
}
