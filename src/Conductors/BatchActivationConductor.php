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
 * Conductor for batch feature operations using Cartesian product.
 *
 * Enables efficient bulk feature management by applying multiple features across
 * multiple contexts in a single operation. Uses Cartesian product pattern where every
 * feature is applied to every context (features × contexts), optimizing for scenarios
 * requiring widespread rollouts or coordinated deactivations.
 *
 * Example: Toggl::batch()->activate(['f1','f2'])->for([$u1,$u2]) results in
 * four operations: f1→u1, f1→u2, f2→u1, f2→u2, enabling coordinated state changes
 * across organizational boundaries with minimal code.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class BatchActivationConductor
{
    /**
     * Create a new batch activation conductor instance.
     *
     * @param FeatureManager $manager Core feature manager instance providing storage access and
     *                                context resolution for executing Cartesian product operations
     *                                across multiple feature-context combinations efficiently
     */
    public function __construct(
        private FeatureManager $manager,
    ) {}

    /**
     * Configure batch activation for specified features.
     *
     * Creates a BatchOperationConductor configured for activation mode, ready
     * to apply the provided features across target contexts using Cartesian product.
     * Supports optional value parameter for configuration features requiring
     * specific settings rather than boolean flags.
     *
     * ```php
     * Toggl::batch()
     *     ->activate(['api', 'webhooks'])
     *     ->for([$org1, $org2, $org3]);
     * ```
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $features Single feature or array of features to activate,
     *                                                              supporting both string names and BackedEnum instances
     * @param  mixed                                      $value    Value to assign to activated features, defaulting
     *                                                              to boolean true but supporting any type for configuration
     * @return BatchOperationConductor                    Conductor ready for terminal for() method to execute operations
     */
    public function activate(string|BackedEnum|array $features, mixed $value = true): BatchOperationConductor
    {
        return new BatchOperationConductor($this->manager, $features, $value, 'activate');
    }

    /**
     * Configure batch deactivation for specified features.
     *
     * Creates a BatchOperationConductor configured for deactivation mode, ready
     * to remove the provided features from target contexts. Uses Cartesian product
     * to ensure comprehensive cleanup across all specified contexts.
     *
     * ```php
     * Toggl::batch()
     *     ->deactivate(['legacy_ui', 'deprecated_api'])
     *     ->for([$user1, $user2]);
     * ```
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $features Single feature or array of features to deactivate,
     *                                                              supporting both string names and BackedEnum instances
     * @return BatchOperationConductor                    Conductor ready for terminal for() method to execute operations
     */
    public function deactivate(string|BackedEnum|array $features): BatchOperationConductor
    {
        return new BatchOperationConductor($this->manager, $features, false, 'deactivate');
    }
}
