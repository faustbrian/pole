<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use BackedEnum;
use Cline\Toggl\Support\LazyEvaluation;

/**
 * Conductor for building lazy feature evaluations with a fluent interface.
 *
 * Provides the entry point for creating LazyEvaluation instances that can
 * be collected and batch-evaluated together for performance and rich analysis.
 *
 * ```php
 * Toggl::lazy('premium')->for($user)
 * Toggl::lazy(Feature::Analytics)->for($team)
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class LazyEvaluationConductor
{
    /**
     * Create a new lazy evaluation conductor instance.
     *
     * @param BackedEnum|string $feature Feature identifier to evaluate lazily. Supports string name
     *                                   or backed enum for type-safe feature references in batch operations
     */
    public function __construct(
        private string|BackedEnum $feature,
    ) {}

    /**
     * Bind the lazy evaluation to a specific context (terminal method).
     *
     * Finalizes the lazy evaluation by associating it with a context entity.
     * The resulting LazyEvaluation instance can be collected with others and
     * passed to Toggl::evaluate() for efficient batch processing, reducing
     * overhead when evaluating multiple feature-context combinations.
     *
     * @param  mixed          $context Context entity (user, team, tenant, etc.) for evaluation
     * @return LazyEvaluation Immutable evaluation object ready for batch processing
     */
    public function for(mixed $context): LazyEvaluation
    {
        return new LazyEvaluation($this->feature, $context);
    }
}
