<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Strategies;

use Cline\Toggl\Contracts\Strategy;
use Closure;
use ReflectionFunction;

/**
 * Strategy that evaluates feature flags based on a custom closure condition.
 *
 * This strategy allows for flexible feature flag evaluation by accepting a closure
 * that determines the feature state based on the given context. It automatically
 * detects whether the closure can handle null contexts using reflection.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class ConditionalStrategy implements Strategy
{
    /**
     * Indicates if the condition closure can handle null contexts.
     *
     * Automatically determined at construction via reflection by checking if the
     * closure's first parameter accepts null values. This enables proper validation
     * of feature flag usage with global contexts.
     */
    private bool $canHandleNull;

    /**
     * Create a new conditional strategy instance.
     *
     * Uses reflection to analyze the closure's signature and determine if it can
     * handle null contexts. This allows for flexible feature flag logic while
     * maintaining type safety and proper context validation.
     *
     * @param Closure $condition The condition closure to evaluate. Should accept a context
     *                           parameter (e.g., User model, ID) and return a truthy/falsy
     *                           value indicating whether the feature should be enabled.
     */
    public function __construct(
        private Closure $condition,
    ) {
        $reflection = new ReflectionFunction($this->condition);
        $parameters = $reflection->getParameters();

        // Determine if the condition can handle null contexts by inspecting the first parameter
        // Null is allowed if: no parameters, untyped parameter, or parameter explicitly allows null
        $this->canHandleNull = empty($parameters)
            || !$parameters[0]->hasType()
            || ($parameters[0]->getType()?->allowsNull() ?? true);
    }

    /**
     * Resolve the feature value by executing the condition closure.
     *
     * Passes the context to the closure and returns its result. The closure can
     * return any value, though typically returns boolean for feature flags. The
     * context parameter is available but not passed to the closure.
     *
     * @param  mixed $context The context to evaluate (e.g., User model, Team, ID)
     * @return mixed The result of the condition evaluation (typically boolean)
     */
    public function resolve(mixed $context, mixed $meta = null): mixed
    {
        return ($this->condition)($context);
    }

    /**
     * Determine if this strategy can handle null contexts.
     *
     * This value is determined at construction time via reflection of the
     * closure's parameter types.
     *
     * @return bool True if null contexts are allowed, false otherwise
     */
    public function canHandleNullContext(): bool
    {
        return $this->canHandleNull;
    }
}
