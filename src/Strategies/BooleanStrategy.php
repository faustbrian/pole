<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Strategies;

use Cline\Pole\Contracts\Strategy;

/**
 * A simple strategy that returns a fixed boolean value.
 *
 * This strategy always returns the same boolean value regardless of scope,
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
     * @param bool $value The boolean value to always return
     */
    public function __construct(
        private bool $value,
    ) {}

    /**
     * Resolve the feature value for the given scope.
     *
     * Always returns the configured boolean value, ignoring the scope.
     *
     * @param  mixed $scope The scope to resolve for (ignored)
     * @return bool  The configured boolean value
     */
    public function resolve(mixed $scope): bool
    {
        return $this->value;
    }

    /**
     * Determine if this strategy can handle null scope.
     *
     * @return bool Always true, as boolean values work without scope
     */
    public function canHandleNullScope(): bool
    {
        return true;
    }
}
