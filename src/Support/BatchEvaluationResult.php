<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Support;

use Illuminate\Support\Collection;

use function array_all;
use function array_any;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function collect;
use function count;

/**
 * Result container for batch feature evaluations.
 *
 * Provides a rich API for analyzing the results of multiple feature-context
 * evaluations, including aggregate checks (all, any, none) and filtering
 * capabilities by feature, context, or result value.
 *
 * ```php
 * $results = Toggl::evaluate([
 *     Toggl::lazy('premium')->for($user1),
 *     Toggl::lazy('premium')->for($user2),
 *     Toggl::lazy('analytics')->for($user1),
 * ]);
 *
 * $results->all();              // true if all evaluations are truthy
 * $results->any();              // true if any evaluation is truthy
 * $results->none();             // true if all evaluations are falsy
 * $results->forFeature('premium');  // filter by feature
 * $results->forContext($user1);     // filter by context
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class BatchEvaluationResult
{
    /**
     * Create a new batch evaluation result instance.
     *
     * @param array<string, EvaluationEntry> $entries    Map of unique keys to evaluation entries
     * @param callable                       $serializer Context serializer for lookups
     */
    public function __construct(
        private array $entries,
        private mixed $serializer,
    ) {}

    /**
     * Check if all evaluations returned truthy values.
     *
     * @return bool True if every feature-context pair evaluated to a truthy value
     */
    public function all(): bool
    {
        foreach ($this->entries as $entry) {
            if (!$entry->value) {
                return false;
            }
        }

        return $this->entries !== [];
    }

    /**
     * Check if any evaluation returned a truthy value.
     *
     * @return bool True if at least one feature-context pair evaluated to truthy
     */
    public function any(): bool
    {
        return array_any($this->entries, fn (EvaluationEntry $entry): bool => (bool) $entry->value);
    }

    /**
     * Check if all evaluations returned falsy values.
     *
     * @return bool True if every feature-context pair evaluated to a falsy value
     */
    public function none(): bool
    {
        return array_all($this->entries, fn ($entry): bool => !$entry->value);
    }

    /**
     * Count truthy evaluations.
     *
     * @return int Number of evaluations that returned truthy values
     */
    public function countActive(): int
    {
        return count(array_filter($this->entries, fn (EvaluationEntry $e): bool => (bool) $e->value));
    }

    /**
     * Count falsy evaluations.
     *
     * @return int Number of evaluations that returned falsy values
     */
    public function countInactive(): int
    {
        return count(array_filter($this->entries, fn (EvaluationEntry $e): bool => !$e->value));
    }

    /**
     * Get total number of evaluations.
     *
     * @return int Total count of feature-context pairs evaluated
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Filter results by feature name.
     *
     * Returns a new result containing only evaluations for the specified feature.
     *
     * @param  string $feature Feature name to filter by
     * @return self   New result with filtered entries
     */
    public function forFeature(string $feature): self
    {
        return new self(
            array_filter($this->entries, fn (EvaluationEntry $e): bool => $e->feature === $feature),
            $this->serializer,
        );
    }

    /**
     * Filter results by context.
     *
     * Returns a new result containing only evaluations for the specified context.
     *
     * @param  mixed $context Context entity to filter by
     * @return self  New result with filtered entries
     */
    public function forContext(mixed $context): self
    {
        $contextKey = ($this->serializer)($context);

        return new self(
            array_filter($this->entries, fn (EvaluationEntry $e): bool => $e->contextKey === $contextKey),
            $this->serializer,
        );
    }

    /**
     * Filter to only active (truthy) evaluations.
     *
     * @return self New result with only truthy entries
     */
    public function active(): self
    {
        return new self(
            array_filter($this->entries, fn (EvaluationEntry $e): bool => (bool) $e->value),
            $this->serializer,
        );
    }

    /**
     * Filter to only inactive (falsy) evaluations.
     *
     * @return self New result with only falsy entries
     */
    public function inactive(): self
    {
        return new self(
            array_filter($this->entries, fn (EvaluationEntry $e): bool => !$e->value),
            $this->serializer,
        );
    }

    /**
     * Get all results as a simple associative array.
     *
     * Keys are "{feature}|{serialized_context}" strings, values are the
     * evaluated results.
     *
     * @return array<string, mixed> Map of evaluation keys to values
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->entries as $key => $entry) {
            $result[$key] = $entry->value;
        }

        return $result;
    }

    /**
     * Get results grouped by feature.
     *
     * @return array<string, array<string, mixed>> Map of feature names to context-value maps
     */
    public function groupByFeature(): array
    {
        $grouped = [];

        foreach ($this->entries as $entry) {
            $grouped[$entry->feature][$entry->contextKey] = $entry->value;
        }

        return $grouped;
    }

    /**
     * Get results grouped by context.
     *
     * @return array<string, array<string, mixed>> Map of context keys to feature-value maps
     */
    public function groupByContext(): array
    {
        $grouped = [];

        foreach ($this->entries as $entry) {
            $grouped[$entry->contextKey][$entry->feature] = $entry->value;
        }

        return $grouped;
    }

    /**
     * Get all unique feature names in the results.
     *
     * @return array<string> List of feature names
     */
    public function features(): array
    {
        return array_values(array_unique(
            array_map(fn (EvaluationEntry $e): string => $e->feature, $this->entries),
        ));
    }

    /**
     * Get all evaluation entries.
     *
     * @return array<string, EvaluationEntry> Map of keys to evaluation entries
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Get results as a Laravel Collection.
     *
     * @return Collection<string, EvaluationEntry>
     */
    public function collect(): Collection
    {
        return collect($this->entries);
    }

    /**
     * Check if results are empty.
     *
     * @return bool True if no evaluations were performed
     */
    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Check if results are not empty.
     *
     * @return bool True if at least one evaluation was performed
     */
    public function isNotEmpty(): bool
    {
        return $this->entries !== [];
    }
}
