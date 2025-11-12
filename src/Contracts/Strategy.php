<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Contracts;

/**
 * Interface for feature flag resolution strategies.
 *
 * Strategies determine how feature flags are resolved for a given scope.
 * They can implement various logic such as boolean values, percentage-based
 * rollouts, time-based activation, conditional logic, or custom rules.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Strategy
{
    /**
     * Resolve the feature value for the given scope.
     *
     * This method contains the core logic for determining if a feature is active
     * and what value it should return. The scope parameter allows for per-user,
     * per-team, or other contextual feature activation.
     *
     * @param  mixed $scope The scope to resolve for (e.g., User model, ID, or null for global)
     * @return mixed The resolved feature value (typically bool, but can be string for variants)
     */
    public function resolve(mixed $scope): mixed;

    /**
     * Determine if this strategy can handle null scope.
     *
     * Some strategies (like time-based or simple boolean) don't need a scope
     * to function. Others (like percentage-based per user) require a scope.
     *
     * @return bool True if this strategy can work without a scope
     */
    public function canHandleNullScope(): bool;
}
