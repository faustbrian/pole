<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Strategies;

use Carbon\CarbonInterface;
use Cline\Pole\Contracts\Strategy;

use function now;

/**
 * A strategy that enables features within a specific time range.
 *
 * This strategy activates features between start and end times, making it
 * useful for scheduled feature releases, limited-time features, or time-based
 * experiments. The feature is active only when the current time falls within
 * the configured range (inclusive).
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class TimeBasedStrategy implements Strategy
{
    /**
     * Create a new Time-Based Strategy instance.
     *
     * @param CarbonInterface $start The start time when the feature becomes active
     * @param CarbonInterface $end   The end time when the feature becomes inactive
     */
    public function __construct(
        private CarbonInterface $start,
        private CarbonInterface $end,
    ) {}

    /**
     * Resolve the feature value for the given scope.
     *
     * Returns true if the current time is between start and end times (inclusive).
     * The scope parameter is ignored as time-based features are global.
     *
     * @param  mixed $scope The scope to resolve for (ignored)
     * @return bool  True if current time is within the configured range
     */
    public function resolve(mixed $scope): bool
    {
        $now = now();

        return $now->between($this->start, $this->end);
    }

    /**
     * Determine if this strategy can handle null scope.
     *
     * @return bool Always true, as time-based features work without scope
     */
    public function canHandleNullScope(): bool
    {
        return true;
    }
}
