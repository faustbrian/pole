<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

/**
 * Contract for feature flag resolution strategies.
 *
 * Strategies encapsulate the decision-making logic for feature flag evaluation,
 * determining whether features are active and what values they should return for
 * given contexts. This pattern enables sophisticated rollout mechanisms beyond
 * simple boolean flags.
 *
 * Common strategy implementations include:
 * - Boolean strategies: Always true/false regardless of context
 * - Percentage rollout: Activate for a percentage of users deterministically
 * - Time-based: Enable features during specific time windows
 * - Conditional: Evaluate based on user attributes or business rules
 * - A/B testing: Return different variants for different user cohorts
 * - Gradual rollout: Increase percentage over time automatically
 *
 * Strategies receive both the feature's target context (user, team, etc.) and
 * optional metadata that can influence decision-making. The separation of concerns
 * between feature definition and resolution strategy allows for powerful, reusable
 * activation logic.
 *
 * ```php
 * // Percentage-based rollout strategy
 * class PercentageStrategy implements Strategy
 * {
 *     public function __construct(private int $percentage) {}
 *
 *     public function resolve(mixed $context, mixed $meta = null): mixed
 *     {
 *         $hash = crc32($context->serialize());
 *         return ($hash % 100) < $this->percentage;
 *     }
 *
 *     public function canHandleNullContext(): bool
 *     {
 *         return false; // Requires context for consistent hashing
 *     }
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Strategy
{
    /**
     * Resolve the feature value for the given context.
     *
     * This method contains the core logic for determining if a feature is active
     * and what value it should return. The implementation should be deterministic:
     * the same context and metadata should always produce the same result.
     *
     * The context parameter represents the entity being evaluated (user, team,
     * organization, etc.) and may be null for global features. Strategies should
     * validate the context type matches their requirements.
     *
     * The optional meta parameter provides additional data that may influence the
     * decision, such as request attributes, environment variables, or feature
     * configuration. This allows strategies to be reusable across different features
     * with varying parameters.
     *
     * @param  mixed $context The context to resolve for (User model, team, organization, or null for global features)
     * @param  mixed $meta    Optional metadata to influence resolution (percentage, time window, rules, etc.)
     * @return mixed The resolved feature value (typically bool for toggles, string for variants, or any custom type)
     */
    public function resolve(mixed $context, mixed $meta = null): mixed;

    /**
     * Determine if this strategy can handle null context.
     *
     * Indicates whether the strategy can function without a context. Context-independent
     * strategies (like time-based or simple boolean) return true, while strategies that
     * require context for their logic (like percentage-based rollout or user attribute
     * checks) return false.
     *
     * This method enables early validation and helps the framework optimize feature
     * resolution by avoiding unnecessary context lookups for global features.
     *
     * @return bool True if the strategy can evaluate features without a context, false if context is required
     */
    public function canHandleNullContext(): bool;
}
