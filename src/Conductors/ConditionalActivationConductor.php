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
 * Conductor for runtime conditional feature activation with chainable predicates.
 *
 * Enables feature activation only when runtime conditions are satisfied, supporting
 * both positive conditions (onlyIf) that must evaluate true and negative conditions
 * (unless) that must evaluate false. Conditions are evaluated lazily at activation
 * time, receiving the target context as context for decision-making.
 *
 * Multiple conditions can be chained with AND semantics: all onlyIf conditions must
 * pass and all unless conditions must fail for activation to proceed. Enables dynamic,
 * context-aware feature rollouts based on user attributes, time, or system state.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class ConditionalActivationConductor
{
    /**
     * Create a new conditional activation conductor instance.
     *
     * @param FeatureManager                  $manager    Core feature manager instance for executing feature
     *                                                    activation operations and managing feature state
     * @param array<string>|BackedEnum|string $feature    Feature(s) to activate when all conditions pass. Supports
     *                                                    single feature string, BackedEnum, or array of features
     * @param mixed                           $value      Value to assign upon successful activation. Defaults to
     *                                                    boolean true for simple flags, but accepts any type for
     *                                                    configuration-based features
     * @param array<int, Closure>             $conditions Positive condition predicates that must all evaluate to true.
     *                                                    Each closure receives the context and returns boolean
     * @param array<int, Closure>             $negations  Negative condition predicates that must all evaluate to false
     *                                                    (guard pattern). Each closure receives the context and returns boolean
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum|array $feature,
        private mixed $value = true,
        private array $conditions = [],
        private array $negations = [],
    ) {}

    /**
     * Add a positive condition that must evaluate to true.
     *
     * Chains an additional requirement that must be satisfied for activation to proceed.
     * Conditions are evaluated with AND semantics: all must pass. Condition receives
     * the context at activation time, enabling dynamic context-aware decisions.
     *
     * ```php
     * Toggl::activate('premium_dashboard')
     *     ->onlyIf(fn($user) => $user->role === 'admin')
     *     ->onlyIf(fn($user) => $user->email_verified)
     *     ->for($user);
     * ```
     *
     * @param  Closure $condition Predicate receiving context and returning boolean, where true
     *                            allows activation to continue evaluating other conditions
     * @return self    New immutable conductor instance with condition added to evaluation chain
     */
    public function onlyIf(Closure $condition): self
    {
        return new self(
            $this->manager,
            $this->feature,
            $this->value,
            [...$this->conditions, $condition],
            $this->negations,
        );
    }

    /**
     * Add a negative condition that must evaluate to false.
     *
     * Chains an exclusion guard that blocks activation when predicate returns true.
     * Implements guard patterns for opt-outs, blacklists, or exclusion criteria.
     * Negations are evaluated with AND semantics: all must fail (return false).
     *
     * ```php
     * Toggl::activate('beta_features')
     *     ->unless(fn($user) => $user->opted_out_beta)
     *     ->unless(fn($user) => $user->account_suspended)
     *     ->for($user);
     * ```
     *
     * @param  Closure $condition Predicate receiving context and returning boolean, where true
     *                            blocks activation and false allows evaluation to continue
     * @return self    New immutable conductor instance with negation added to evaluation chain
     */
    public function unless(Closure $condition): self
    {
        return new self(
            $this->manager,
            $this->feature,
            $this->value,
            $this->conditions,
            [...$this->negations, $condition],
        );
    }

    /**
     * Apply conditional activation to the specified context.
     *
     * Terminal method evaluating all configured conditions before proceeding with
     * activation. Executes onlyIf conditions first (must all return true), then
     * unless conditions (must all return false). Short-circuits on first failure,
     * skipping activation silently when conditions not met.
     *
     * ```php
     * Toggl::activate('api_v2')
     *     ->onlyIf(fn($org) => $org->plan === 'enterprise')
     *     ->unless(fn($org) => $org->legacy_mode)
     *     ->for($organization);
     * ```
     *
     * @param mixed $context Target context for activation and condition evaluation. Context is
     *                       passed to all condition callbacks for context-aware decision making
     */
    public function for(mixed $context): void
    {
        // Check all onlyIf conditions
        foreach ($this->conditions as $condition) {
            if (!$condition($context)) {
                return;
            }
        }

        // Check all unless conditions
        foreach ($this->negations as $negation) {
            if ($negation($context)) {
                return;
            }
        }

        // All conditions met, activate
        $this->manager->for($context)->activate($this->feature, $this->value);
    }

    /**
     * Get the feature being conditionally activated.
     *
     * @return array<string>|BackedEnum|string Single feature or array in original format
     */
    public function feature(): string|BackedEnum|array
    {
        return $this->feature;
    }

    /**
     * Get the value that will be assigned upon activation.
     *
     * @return mixed Configured feature value, defaulting to boolean true
     */
    public function value(): mixed
    {
        return $this->value;
    }
}
