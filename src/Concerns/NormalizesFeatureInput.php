<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Concerns;

use BackedEnum;

use function array_map;
use function is_array;

/**
 * Converts diverse feature parameter types into consistent string representations.
 *
 * Enables API methods to accept feature names as either string literals or BackedEnum
 * instances by normalizing all inputs to their string values. This provides type-safe
 * enum support throughout the public API while maintaining a uniform string-based
 * internal representation for storage, comparison, and retrieval operations.
 *
 * Normalization preserves API flexibility by allowing consumers to use plain strings
 * for simple cases or domain-specific enums for compile-time type safety, with both
 * approaches producing identical internal results.
 *
 * @author Brian Faust <brian@cline.sh>
 */
trait NormalizesFeatureInput
{
    /**
     * Extract string representation from a feature parameter.
     *
     * Converts BackedEnum instances to their underlying string values while passing
     * string parameters through unchanged. This enables methods to accept typed enums
     * for compile-time safety without requiring consumers to manually extract values,
     * simplifying the public API while maintaining internal string consistency.
     *
     * @param  BackedEnum|string $feature Feature identifier as typed enum or string literal
     * @return string            Normalized string value suitable for storage and comparison
     */
    private function normalizeFeature(string|BackedEnum $feature): string
    {
        return $feature instanceof BackedEnum ? (string) $feature->value : $feature;
    }

    /**
     * Convert an array of mixed feature types to uniform string values.
     *
     * Applies normalization across feature collections, transforming BackedEnum instances
     * to their string values while leaving string elements unchanged. Preserves array
     * structure and element ordering, ensuring predictable iteration order in batch
     * operations and multi-feature method calls.
     *
     * @param  array<BackedEnum|string> $features Feature identifiers as mixed enum/string array
     * @return array<string>            Fully normalized string array for internal operations
     */
    private function normalizeFeatures(array $features): array
    {
        return array_map(
            fn (string|BackedEnum $feature): string => $this->normalizeFeature($feature),
            $features,
        );
    }

    /**
     * Normalize variadic feature parameters preserving input structure.
     *
     * Handles method parameters accepting either single features or feature arrays by
     * dispatching to appropriate normalization methods while preserving the original
     * parameter structure. Single values return normalized strings; arrays return
     * normalized string arrays, maintaining one-to-one correspondence with inputs.
     *
     * This structural preservation enables method signatures to use flexible parameter
     * types without complex branching logic in implementation code.
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $feature Single feature or feature array
     * @return array<string>|string                       Normalized value(s) matching input type structure
     */
    private function normalizeFeatureInput(string|BackedEnum|array $feature): string|array
    {
        if (is_array($feature)) {
            return $this->normalizeFeatures($feature);
        }

        return $this->normalizeFeature($feature);
    }
}
