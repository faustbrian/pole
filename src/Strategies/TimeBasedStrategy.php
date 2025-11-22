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
 * Activates features only within a defined time window.
 *
 * This strategy enables features exclusively when the current time falls between
 * configured start and end boundaries (inclusive). Unlike ScheduledStrategy which
 * handles optional activation/deactivation, this strategy enforces a strict time
 * window where both boundaries are always required. Common use cases include:
 *
 * - Business hours restrictions (9 AM to 5 PM)
 * - Maintenance windows with defined start and end
 * - Flash sales or limited-time promotions
 * - Seasonal feature availability
 * - Time-boxed A/B tests or experiments
 * - Holiday or event-specific functionality
 *
 * ```php
 * // Feature only active during business hours
 * $strategy = new TimeBasedStrategy(
 *     start: today()->setTime(9, 0),
 *     end: today()->setTime(17, 0)
 * );
 *
 * // Weekend-only feature
 * $strategy = new TimeBasedStrategy(
 *     start: now()->next('Saturday')->startOfDay(),
 *     end: now()->next('Sunday')->endOfDay()
 * );
 *
 * // Holiday promotion (Black Friday to Cyber Monday)
 * $strategy = new TimeBasedStrategy(
 *     start: Carbon::parse('2024-11-29 00:00:00'),
 *     end: Carbon::parse('2024-12-02 23:59:59')
 * );
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class TimeBasedStrategy implements Strategy
{
    /**
     * Create a new time-based strategy instance.
     *
     * @param CarbonInterface $start The start boundary of the active time window.
     *                               The feature becomes active when the current time
     *                               reaches or exceeds this timestamp. Must be earlier
     *                               than the end time to create a valid time window.
     * @param CarbonInterface $end   The end boundary of the active time window.
     *                               The feature becomes inactive when the current time
     *                               exceeds this timestamp. Must be later than the start
     *                               time to create a valid time window.
     */
    public function __construct(
        private CarbonInterface $start,
        private CarbonInterface $end,
    ) {}

    /**
     * Determine if the feature is active within the time window.
     *
     * Uses Carbon's between() method to check if the current timestamp falls
     * within the configured start and end boundaries (inclusive on both ends).
     * The evaluation is purely time-based and does not consider context.
     *
     * @param  mixed $context The context (ignored by this time-based strategy)
     * @return bool  True if current time is within the window (start <= now <= end), false otherwise
     */
    public function resolve(mixed $context, mixed $meta = null): bool
    {
        $now = now();

        return $now->between($this->start, $this->end);
    }

    /**
     * Indicate whether this strategy can operate without a context.
     *
     * Time-based strategies evaluate features based solely on the current time
     * and do not require context, making them compatible with null contexts
     * and global feature flag evaluations.
     *
     * @return bool Always returns true as time windows are context-independent
     */
    public function canHandleNullContext(): bool
    {
        return true;
    }
}
