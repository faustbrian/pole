<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use BackedEnum;
use Cline\Toggl\FeatureManager;
use Closure;

/**
 * Context-aware query conductor for executing conditional callbacks based on feature activation status.
 *
 * Represents the terminal stage of the query pattern where feature status is checked against
 * a specific context and callbacks are executed accordingly. Provides then() and otherwise()
 * methods for elegant conditional logic without traditional if/else statements.
 *
 * ```php
 * Toggl::when('premium')->for($user)
 *     ->then(fn() => $this->enableAdvancedFeatures())
 *     ->otherwise(fn() => $this->showUpgradePrompt());
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class QueryContextualConductor
{
    /**
     * Create a new contextual query conductor instance.
     *
     * Initializes an immutable conductor bound to a specific feature and context combination.
     * This allows subsequent then() and otherwise() calls to check activation status and
     * execute appropriate callbacks. Typically created by QueryConductor rather than directly.
     *
     * @param FeatureManager    $manager the feature manager instance that will be used to check
     *                                   whether the feature is active for the specified context
     * @param BackedEnum|string $feature The feature identifier to evaluate activation status for.
     *                                   Can be a string name or BackedEnum for type safety.
     * @param mixed             $context The context entity (user, team, tenant, etc.) to evaluate
     *                                   feature activation against. Can be any object or value
     *                                   supported by the feature manager's drivers.
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum $feature,
        private mixed $context,
    ) {}

    /**
     * Execute callback when the feature is active for the context.
     *
     * Checks if the feature is activated for the bound context and executes the provided
     * callback if true. Returns the conductor instance to allow chaining an otherwise()
     * callback for the negative case. The callback receives no parameters.
     *
     * @param  Closure $callback Closure to execute if feature is active for the context.
     *                           Receives no parameters. Use for side effects or return values.
     * @return static  returns this conductor instance to enable chaining otherwise()
     *                 for handling the inactive case
     */
    public function then(Closure $callback): static
    {
        if ($this->manager->for($this->context)->active($this->feature)) {
            $callback();
        }

        return $this;
    }

    /**
     * Execute callback when the feature is inactive for the context.
     *
     * Terminal method that checks if the feature is deactivated for the bound context
     * and executes the provided callback if true. Typically chained after then() to
     * provide complete if/else-like conditional logic in a fluent interface.
     *
     * @param Closure $callback Closure to execute if feature is inactive for the context.
     *                          Receives no parameters. Use for fallback logic or alternative
     *                          behavior when the feature is not enabled.
     */
    public function otherwise(Closure $callback): void
    {
        if (!$this->manager->for($this->context)->active($this->feature)) {
            $callback();
        }
    }
}
