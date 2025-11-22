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

use function is_array;

/**
 * Terminal conductor for executing batch feature operations via Cartesian product.
 *
 * Returned by BatchActivationConductor methods, this conductor performs the actual
 * Cartesian product execution where every feature is applied to every context. The for()
 * method serves as the terminal operation, iterating through all feature-context combinations
 * to apply activations or deactivations efficiently in nested loops.
 *
 * Optimized for bulk operations requiring consistent state across multiple entities,
 * such as organization-wide feature rollouts or coordinated deprecation of legacy features.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class BatchOperationConductor
{
    /**
     * Create a new batch operation conductor instance.
     *
     * @param FeatureManager                             $manager   Core feature manager instance providing storage and
     *                                                              context access for executing Cartesian product iterations
     * @param array<BackedEnum|string>|BackedEnum|string $features  Feature name(s) to operate on, accepting single feature
     *                                                              or array with type-safe BackedEnum and string support
     * @param mixed                                      $value     Value to assign during activation operations, ignored
     *                                                              during deactivation but supporting any type for configuration
     *                                                              features or boolean true for simple flags
     * @param string                                     $operation Operation type controlling behavior, either 'activate' for
     *                                                              enabling features with values or 'deactivate' for removal
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum|array $features,
        private mixed $value,
        private string $operation,
    ) {}

    /**
     * Execute the batch operation across all feature-context combinations.
     *
     * Terminal method performing Cartesian product iteration: every feature applied
     * to every context. Normalizes single values to arrays for consistent iteration,
     * then executes nested loops to ensure comprehensive coverage. Activations use
     * the configured value while deactivations ignore it.
     *
     * ```php
     * // Results in 6 operations: f1→u1, f1→u2, f1→u3, f2→u1, f2→u2, f2→u3
     * Toggl::batch()
     *     ->activate(['f1', 'f2'])
     *     ->for([$u1, $u2, $u3]);
     * ```
     *
     * @param array<mixed>|mixed $contexts Single context or array of contexts to receive the operations,
     *                                     supporting any entity type such as users or organizations
     */
    public function for(mixed $contexts): void
    {
        $features = is_array($this->features) ? $this->features : [$this->features];
        $contextArray = is_array($contexts) ? $contexts : [$contexts];

        // Cartesian product: every feature × every context
        foreach ($features as $feature) {
            foreach ($contextArray as $context) {
                if ($this->operation === 'activate') {
                    $this->manager->for($context)->activate($feature, $this->value);
                } else {
                    $this->manager->for($context)->deactivate($feature);
                }
            }
        }
    }

    /**
     * Get the features being operated on.
     *
     * @return array<BackedEnum|string>|BackedEnum|string Single feature or array of features
     *                                                    in their original format (string or BackedEnum)
     */
    public function features(): string|BackedEnum|array
    {
        return $this->features;
    }

    /**
     * Get the value that will be assigned during activation.
     *
     * @return mixed Configured value for activation operations, ignored during deactivation
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * Get the configured operation type.
     *
     * @return string Operation mode, either 'activate' or 'deactivate'
     */
    public function operation(): string
    {
        return $this->operation;
    }
}
