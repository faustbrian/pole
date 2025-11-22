<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use Cline\Toggl\FeatureManager;

/**
 * Variant conductor for A/B testing and feature variants.
 *
 * Manages feature variants for A/B testing, multivariate testing, and gradual
 * rollouts with multiple variations. Variants enable serving different versions
 * of a feature to different user segments, with support for both deterministic
 * assignment (use()) and calculated distribution (weight-based).
 *
 * The conductor provides two modes of operation:
 * - Calculated variants: Uses weight distribution from feature definition
 * - Explicit variants: Assigns a specific variant using use() method
 *
 * Common patterns:
 *
 * ```php
 * // Get calculated variant based on weights
 * $variant = Toggl::variant('ab-test')->for($user)->get();
 *
 * // Explicitly assign variant
 * Toggl::variant('ab-test')->use('variant-b')->for($user);
 *
 * // Check which variant is active
 * if (Toggl::variant('ab-test')->for($user)->is('variant-b')) {
 *     // Show variant B experience
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class VariantConductor
{
    /**
     * Create a new variant conductor instance.
     *
     * @param FeatureManager $manager     The feature manager instance used to interact with
     *                                    the driver and retrieve/store variant assignments
     * @param string         $feature     The variant feature identifier to manage variants for
     * @param null|string    $variantName Optional specific variant name to explicitly assign.
     *                                    When null, variant is calculated using weight distribution
     */
    public function __construct(
        private FeatureManager $manager,
        private string $feature,
        private ?string $variantName = null,
    ) {}

    /**
     * Assign a specific variant (instead of using weight distribution).
     *
     * Forces assignment of a specific variant for the context instead of letting
     * the feature definition's weight distribution determine the variant. This
     * is useful for manually enrolling users in specific test variations or for
     * administrative overrides.
     *
     * @param  string $variantName The variant name to explicitly assign
     * @return self   New conductor instance configured to assign the specified variant
     */
    public function use(string $variantName): self
    {
        return new self($this->manager, $this->feature, $variantName);
    }

    /**
     * Apply variant to the given context and return contextual result.
     *
     * Terminal operation that either assigns the explicitly specified variant
     * (from use()) or retrieves the calculated/stored variant for the context.
     * Returns a VariantResult object that provides methods to check and retrieve
     * the variant value.
     *
     * When variantName is set via use(), this stores the explicit assignment.
     * When variantName is null, this retrieves the existing variant assignment
     * or calculates one based on the feature's weight distribution.
     *
     * @param  mixed         $context The context for variant assignment (user, team, etc.).
     *                                Variants do not support multiple contexts simultaneously
     * @return VariantResult Result object providing get(), is(), and getOr() methods
     */
    public function for(mixed $context): VariantResult
    {
        if ($this->variantName !== null) {
            // Assign specific variant
            $this->manager->for($context)->activate($this->feature, $this->variantName);

            return new VariantResult($this->variantName);
        }

        // Get variant (calculated or stored)
        $variant = $this->manager->for($context)->variant($this->feature);

        return new VariantResult($variant);
    }

    /**
     * Get the feature name.
     *
     * @return string The variant feature identifier
     */
    public function feature(): string
    {
        return $this->feature;
    }

    /**
     * Get the assigned variant name (if any).
     *
     * @return null|string The explicitly assigned variant name from use(), or null if using calculated variant distribution
     */
    public function variantName(): ?string
    {
        return $this->variantName;
    }
}
