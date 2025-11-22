<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use BackedEnum;
use Cline\Toggl\Exceptions\MissingComparisonContextException;
use Cline\Toggl\FeatureManager;

use function array_key_exists;
use function throw_if;

/**
 * Conductor for differential analysis of feature states across contexts.
 *
 * Identifies and categorizes differences between two contexts' feature configurations,
 * reporting features unique to each context and features with conflicting values. Enables
 * auditing discrepancies, synchronizing configurations, debugging inconsistencies, and
 * analyzing feature distribution patterns across organizational boundaries.
 *
 * Results are structured as three categories: features only in first context, features
 * only in second context, and features present in both with different values, providing
 * comprehensive visibility into configuration divergence.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class ComparisonConductor
{
    /**
     * Create a new comparison conductor instance.
     *
     * @param FeatureManager $manager  Core feature manager instance providing access to stored
     *                                 feature data for both contexts being compared
     * @param mixed          $context1 First context serving as comparison baseline, used to identify
     *                                 features unique to this context or having different values
     * @param null|mixed     $context2 Second context for differential analysis, remaining null until
     *                                 specified via against() method in fluent API construction pattern
     */
    public function __construct(
        private FeatureManager $manager,
        private mixed $context1,
        private mixed $context2 = null,
    ) {}

    /**
     * Set comparison target and execute differential analysis.
     *
     * Fluent terminal method accepting the second context and immediately performing
     * comprehensive differential analysis between the two contexts' feature states.
     * Returns structured categorization of differences: features unique to each context
     * and features present in both but with conflicting values.
     *
     * ```php
     * $diff = Toggl::compare($user1)->against($user2);
     * // Returns: ['only_context1' => [...], 'only_context2' => [...], 'different_values' => [...]]
     * ```
     *
     * @param  mixed                                                                                                                                                                                      $context2 Second context to compare against first context baseline
     * @return array{only_context1: array<BackedEnum|string, mixed>, only_context2: array<BackedEnum|string, mixed>, different_values: array<BackedEnum|string, array{context1: mixed, context2: mixed}>} Categorized comparison results identifying all configuration differences
     */
    public function against(mixed $context2): array
    {
        return $this->performComparison($this->context1, $context2);
    }

    /**
     * Execute comparison when both contexts configured via constructor.
     *
     * Alternative terminal method for scenarios where both contexts were provided during
     * conductor construction rather than via fluent against() method. Validates second
     * context exists before executing analysis, throwing exception if configuration incomplete.
     *
     * @throws MissingComparisonContextException When second context not configured via constructor or against()
     *
     * @return array{only_context1: array<BackedEnum|string, mixed>, only_context2: array<BackedEnum|string, mixed>, different_values: array<BackedEnum|string, array{context1: mixed, context2: mixed}>} Categorized comparison results
     */
    public function get(): array
    {
        throw_if($this->context2 === null, MissingComparisonContextException::notProvided());

        return $this->performComparison($this->context1, $this->context2);
    }

    /**
     * Execute differential analysis between two contexts' active feature states.
     *
     * Compares active features from both contexts, filtering out explicitly deactivated
     * (false value) features to focus on meaningful configuration differences. Categorizes
     * results into three groups: features unique to first context, features unique to
     * second context, and features present in both but with conflicting values.
     *
     * The comparison treats explicitly-deactivated features (value === false) as equivalent
     * to never-activated features, ensuring the analysis focuses on active configuration
     * divergence rather than deactivation tracking differences.
     *
     * @param  mixed                                                                                                                                                                                      $context1 First context providing baseline feature state
     * @param  mixed                                                                                                                                                                                      $context2 Second context to compare against baseline
     * @return array{only_context1: array<BackedEnum|string, mixed>, only_context2: array<BackedEnum|string, mixed>, different_values: array<BackedEnum|string, array{context1: mixed, context2: mixed}>} Structured difference categorization
     */
    private function performComparison(mixed $context1, mixed $context2): array
    {
        $pending1 = $this->manager->for($context1);
        $pending2 = $this->manager->for($context2);

        // Get only active features (excluding false/inactive)
        // This treats explicitly-deactivated (false) same as never-activated
        // which is the desired behavior for most comparisons
        $allFeatures1 = $pending1->stored();
        $allFeatures2 = $pending2->stored();

        // Filter to active features only (value !== false)
        $features1 = [];

        foreach ($allFeatures1 as $key => $value) {
            if ($value !== false) {
                $features1[$key] = $value;
            }
        }

        $features2 = [];

        foreach ($allFeatures2 as $key => $value) {
            if ($value !== false) {
                $features2[$key] = $value;
            }
        }

        $onlyContext1 = [];
        $onlyContext2 = [];
        $differentValues = [];

        // Find features only in context1 or with different values
        foreach ($features1 as $feature => $value1) {
            if (!array_key_exists($feature, $features2)) {
                $onlyContext1[$feature] = $value1;
            } elseif ($value1 !== $features2[$feature]) {
                $differentValues[$feature] = [
                    'context1' => $value1,
                    'context2' => $features2[$feature],
                ];
            }
        }

        // Find features only in context2
        foreach ($features2 as $feature => $value2) {
            if (!array_key_exists($feature, $features1)) {
                $onlyContext2[$feature] = $value2;
            }
        }

        return [
            'only_context1' => $onlyContext1,
            'only_context2' => $onlyContext2,
            'different_values' => $differentValues,
        ];
    }
}
