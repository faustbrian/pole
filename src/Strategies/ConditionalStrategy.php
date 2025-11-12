<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Strategies;

use Cline\Pole\Contracts\Strategy;
use Closure;
use ReflectionFunction;

/**
 * Strategy that evaluates feature flags based on a custom closure condition.
 *
 * This strategy allows for flexible feature flag evaluation by accepting a closure
 * that determines the feature state based on the given scope. It automatically
 * detects whether the closure can handle null scopes using reflection.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class ConditionalStrategy implements Strategy
{
    /**
     * Indicates if the condition can handle null scopes.
     */
    private bool $canHandleNull;

    /**
     * Create a new conditional strategy instance.
     *
     * @param Closure $condition The condition closure to evaluate
     */
    public function __construct(
        private Closure $condition,
    ) {
        $reflection = new ReflectionFunction($this->condition);
        $parameters = $reflection->getParameters();

        // Determine if the condition can handle null scopes by inspecting the first parameter
        $this->canHandleNull = empty($parameters)
            || !$parameters[0]->hasType()
            || ($parameters[0]->getType()?->allowsNull() ?? true);
    }

    /**
     * Resolve the feature value by executing the condition closure.
     *
     * @param  mixed $scope The scope to evaluate
     * @return mixed The result of the condition evaluation
     */
    public function resolve(mixed $scope): mixed
    {
        return ($this->condition)($scope);
    }

    /**
     * Determine if this strategy can handle null scopes.
     *
     * @return bool True if null scopes are allowed, false otherwise
     */
    public function canHandleNullScope(): bool
    {
        return $this->canHandleNull;
    }
}
