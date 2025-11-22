<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Strategies;

use Carbon\CarbonInterface;
use Cline\Toggl\Contracts\Strategy;

use function now;

/**
 * Enables features based on scheduled activation and deactivation times.
 *
 * This strategy provides time-based feature flag control by comparing the current
 * time against configurable activation and deactivation timestamps. Features can
 * be scheduled to automatically enable at a future time and optionally disable
 * after a specific duration, making it ideal for:
 *
 * - Scheduled feature releases (launch at midnight)
 * - Time-limited promotions or campaigns
 * - Beta testing windows with automatic start/end
 * - Gradual rollouts with controlled timing
 * - Temporary feature toggles for events or seasons
 *
 * ```php
 * // Feature activates at midnight and deactivates after 24 hours
 * $strategy = new ScheduledStrategy(
 *     activateAt: now()->addDay()->startOfDay(),
 *     deactivateAt: now()->addDays(2)->startOfDay()
 * );
 *
 * // Feature is already active, will deactivate at end of month
 * $strategy = new ScheduledStrategy(
 *     activateAt: null,
 *     deactivateAt: now()->endOfMonth()
 * );
 *
 * // Feature activates next Monday, never deactivates
 * $strategy = new ScheduledStrategy(
 *     activateAt: now()->next('Monday')
 * );
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class ScheduledStrategy implements Strategy
{
    /**
     * Create a new scheduled strategy instance.
     *
     * @param null|CarbonInterface $activateAt   Timestamp when the feature becomes active.
     *                                           If null, the feature is considered active from the start.
     *                                           When set, the feature remains inactive until this time is reached.
     *                                           Used to schedule future feature launches or delayed rollouts.
     * @param null|CarbonInterface $deactivateAt Timestamp when the feature becomes inactive.
     *                                           If null, the feature remains active indefinitely once activated.
     *                                           When set, the feature automatically disables after this time.
     *                                           Used for time-limited features, campaigns, or testing windows.
     */
    public function __construct(
        private ?CarbonInterface $activateAt = null,
        private ?CarbonInterface $deactivateAt = null,
    ) {}

    /**
     * Determine if the feature is active based on the current time.
     *
     * Evaluates whether the current timestamp falls within the configured schedule:
     * - Returns false if the activation time has not yet been reached
     * - Returns false if the deactivation time has already passed
     * - Returns true if currently within the active time window
     *
     * This method is context-independent and makes decisions based solely on time.
     *
     * @param  mixed $context The context (ignored by this time-based strategy)
     * @return bool  True if the feature is currently active based on schedule, false otherwise
     */
    public function resolve(mixed $context, mixed $meta = null): bool
    {
        $now = now();

        // Feature is not yet active
        if ($this->activateAt instanceof CarbonInterface && $now->isBefore($this->activateAt)) {
            return false;
        }

        // Feature has been deactivated
        return !($this->deactivateAt instanceof CarbonInterface && $now->isAfter($this->deactivateAt));
    }

    /**
     * Indicate whether this strategy can operate without a context.
     *
     * Scheduled strategies are time-based and do not depend on context,
     * making them compatible with null contexts and global feature flag checks.
     *
     * @return bool Always returns true as scheduling is context-independent
     */
    public function canHandleNullContext(): bool
    {
        return true;
    }
}
