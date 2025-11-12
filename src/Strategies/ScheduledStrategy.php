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
 * Strategy for time-based feature flag activation and deactivation.
 *
 * This strategy enables features based on a schedule, allowing you to automatically
 * activate features at a specific time and optionally deactivate them later. This is
 * useful for time-limited features, beta releases, or scheduled rollouts.
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
     * @param null|CarbonInterface $activateAt   The time when the feature becomes active, or null if already active
     * @param null|CarbonInterface $deactivateAt The time when the feature becomes inactive, or null if never deactivating
     */
    public function __construct(
        private ?CarbonInterface $activateAt = null,
        private ?CarbonInterface $deactivateAt = null,
    ) {}

    /**
     * Resolve the feature value based on the current time.
     *
     * Returns true if the current time is between the activation and deactivation times.
     * Returns false if before activation time or after deactivation time.
     *
     * @param  mixed $scope The scope (not used by this strategy)
     * @return bool  True if the feature is currently active based on the schedule
     */
    public function resolve(mixed $scope): bool
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
     * Determine if this strategy can handle null scopes.
     *
     * This strategy is scope-independent and works with any scope including null.
     *
     * @return bool Always returns true
     */
    public function canHandleNullScope(): bool
    {
        return true;
    }
}
