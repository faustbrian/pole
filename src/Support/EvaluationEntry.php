<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Support;

/**
 * Value object representing a single feature evaluation result.
 *
 * Contains all information about a feature-context evaluation including
 * the feature name, serialized context key, original context, and result value.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class EvaluationEntry
{
    /**
     * Create a new evaluation entry.
     *
     * @param string $feature    The feature name that was evaluated
     * @param string $contextKey The serialized context key for lookups
     * @param mixed  $context    The original context entity
     * @param mixed  $value      The evaluated result value
     */
    public function __construct(
        public string $feature,
        public string $contextKey,
        public mixed $context,
        public mixed $value,
    ) {}

    /**
     * Check if this evaluation is active (truthy).
     *
     * @return bool True if the value is truthy
     */
    public function isActive(): bool
    {
        return (bool) $this->value;
    }

    /**
     * Check if this evaluation is inactive (falsy).
     *
     * @return bool True if the value is falsy
     */
    public function isInactive(): bool
    {
        return !$this->value;
    }
}
