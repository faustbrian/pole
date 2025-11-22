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

/**
 * Query conductor for conditional feature status checks with chainable callbacks.
 *
 * Implements the query-first pattern for executing conditional logic based on feature
 * activation state. Provides an expressive, fluent interface for feature-based branching
 * logic that reads naturally and maintains immutability throughout the chain.
 *
 * ```php
 * Toggl::when('premium')
 *     ->for($user)
 *     ->then(fn() => $this->showPremiumDashboard())
 *     ->otherwise(fn() => $this->showFreeTier());
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class QueryConductor
{
    /**
     * Create a new query conductor instance.
     *
     * Initializes an immutable query conductor for checking the activation status
     * of a specific feature across different contexts. Typically instantiated via
     * the FeatureManager's when() method rather than directly.
     *
     * @param FeatureManager    $manager the feature manager instance used to check feature
     *                                   activation status across different contexts and drivers
     * @param BackedEnum|string $feature The feature identifier to query. Can be a string name
     *                                   or a BackedEnum case for type-safe feature references.
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum $feature,
    ) {}

    /**
     * Specify the context for feature status evaluation.
     *
     * Transitions from the feature-focused query to a context-specific query by creating
     * a QueryContextualConductor. This allows subsequent then() and otherwise() callbacks
     * to execute based on whether the feature is active for the specified context.
     *
     * @param  mixed                    $context the context entity (user, team, tenant, etc.) to check
     *                                           feature activation status against. Can be any object
     *                                           or value supported by the feature manager's drivers.
     * @return QueryContextualConductor contextual conductor ready for then()/otherwise() chaining
     *                                  to execute conditional callbacks based on feature state
     */
    public function for(mixed $context): QueryContextualConductor
    {
        return new QueryContextualConductor($this->manager, $this->feature, $context);
    }
}
