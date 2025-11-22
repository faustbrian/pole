<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Strategies;

use Cline\Toggl\Contracts\Strategy;

/**
 * A simple strategy that returns a fixed boolean value.
 *
 * This strategy always returns the same boolean value regardless of context,
 * making it useful for features that are globally enabled or disabled.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class BooleanStrategy implements Strategy
{
    /**
     * Create a new Boolean Strategy instance.
     *
     * @param bool $value The boolean value to always return when the feature is resolved.
     *                    True enables the feature globally, false disables it globally.
     */
    public function __construct(
        private bool $value,
    ) {}

    /**
     * Resolve the feature value for the given context.
     *
     * Always returns the configured boolean value, ignoring the context
     * parameters entirely. This makes it ideal for global feature toggles that should
     * be uniformly enabled or disabled for all users without any conditional logic.
     *
     * @param  mixed $context The context to resolve for (ignored - not used in resolution)
     * @return bool  The configured boolean value
     */
    public function resolve(mixed $context, mixed $meta = null): bool
    {
        return $this->value;
    }

    /**
     * Determine if this strategy can handle null context.
     *
     * Boolean strategies work without any context since they return a fixed value.
     *
     * @return bool Always returns true
     */
    public function canHandleNullContext(): bool
    {
        return true;
    }
}
