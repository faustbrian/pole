<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

/**
 * Result object returned by VariantConductor::for().
 *
 * Encapsulates the variant assignment for a context, providing convenient methods
 * to check and retrieve the assigned variant value. This immutable result object
 * enables fluent variant checks in application code without repeatedly querying
 * the feature manager.
 *
 * Provides multiple access patterns:
 * - get(): Retrieve the variant name or null
 * - is(): Check if a specific variant is assigned
 * - getOr(): Retrieve variant with fallback default
 *
 * ```php
 * $result = Toggl::variant('ab-test')->for($user);
 *
 * // Get variant or null
 * $variant = $result->get(); // 'variant-a' or null
 *
 * // Check specific variant
 * if ($result->is('variant-b')) {
 *     // Show variant B UI
 * }
 *
 * // Get with default
 * $variant = $result->getOr('control'); // 'variant-a' or 'control'
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class VariantResult
{
    /**
     * Create a new variant result instance.
     *
     * @param null|string $variant The assigned variant name, or null if no variant is assigned
     *                             or the feature is not configured for variants
     */
    public function __construct(
        private ?string $variant,
    ) {}

    /**
     * Get the assigned variant name.
     *
     * Returns the variant identifier assigned to the context, or null if no variant
     * has been assigned yet or the feature is not configured for variants.
     *
     * @return null|string The variant name, or null if no variant is assigned
     */
    public function get(): ?string
    {
        return $this->variant;
    }

    /**
     * Check if a specific variant is assigned.
     *
     * Performs exact string comparison to determine if the specified variant
     * matches the assigned variant. Useful for conditional logic based on
     * variant assignment.
     *
     * @param  string $variantName The variant name to check for equality
     * @return bool   True if this variant is currently assigned, false otherwise
     */
    public function is(string $variantName): bool
    {
        return $this->variant === $variantName;
    }

    /**
     * Get the variant name or a default value.
     *
     * Returns the assigned variant if one exists, otherwise returns the provided
     * default value. This enables safe variant retrieval without null checks in
     * application code.
     *
     * @param  string $default The fallback value to return if no variant is assigned
     * @return string The assigned variant name, or the default if none assigned
     */
    public function getOr(string $default): string
    {
        return $this->variant ?? $default;
    }
}
