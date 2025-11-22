<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Support;

use BackedEnum;

/**
 * Represents a lazy feature evaluation bound to a specific context.
 *
 * This class captures feature-context pairs for deferred batch evaluation,
 * allowing multiple feature checks to be collected and evaluated together
 * for better performance and richer result analysis.
 *
 * ```php
 * $evaluations = [
 *     Toggl::lazy('premium')->for($user1),
 *     Toggl::lazy('analytics')->for($user2),
 *     Toggl::lazy('reporting')->for($team),
 * ];
 *
 * $results = Toggl::evaluate($evaluations);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class LazyEvaluation
{
    /**
     * The normalized feature name.
     */
    public string $feature;

    /**
     * Create a new lazy evaluation instance.
     *
     * @param BackedEnum|string $feature The feature identifier to evaluate
     * @param mixed             $context The context entity (user, team, etc.) to evaluate against
     */
    public function __construct(
        string|BackedEnum $feature,
        public mixed $context,
    ) {
        $this->feature = $feature instanceof BackedEnum ? (string) $feature->value : $feature;
    }

    /**
     * Get a unique key for this feature-context combination.
     *
     * Used for result lookups and deduplication.
     *
     * @param  callable(mixed): string $contextSerializer Function to serialize context to string
     * @return non-empty-string
     */
    public function getKey(callable $contextSerializer): string
    {
        /** @var string $serialized */
        $serialized = $contextSerializer($this->context);

        return $this->feature.'|'.$serialized;
    }
}
