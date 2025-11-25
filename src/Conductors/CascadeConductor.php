<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use BackedEnum;
use Cline\Toggl\Exceptions\MissingDependentFeaturesException;
use Cline\Toggl\FeatureManager;

use function throw_if;

/**
 * Conductor for cascading feature state changes across related features.
 *
 * Manages coordinated activation or deactivation of a primary feature alongside
 * its dependent features in a single atomic operation. Ensures correct ordering:
 * activations apply primary first then dependents, while deactivations remove
 * dependents first then primary to maintain referential integrity.
 *
 * Useful for feature hierarchies where enabling a parent feature requires related
 * features, or disabling a feature necessitates cleanup of dependent functionality.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class CascadeConductor
{
    /**
     * Create a new cascade conductor instance.
     *
     * @param FeatureManager                $manager           Core feature manager instance providing storage and context
     *                                                         access for executing coordinated state change operations
     * @param BackedEnum|string             $feature           Primary feature serving as cascade root, whose state change
     *                                                         triggers coordinated dependent feature operations to maintain
     *                                                         referential integrity and hierarchical consistency
     * @param null|array<BackedEnum|string> $dependentFeatures Related features to activate or deactivate alongside primary
     *                                                         feature, remaining null until configured via activating() or
     *                                                         deactivating() methods in the fluent chain
     * @param bool                          $activate          Operation mode determining execution order: true for activation
     *                                                         cascade (primary then dependents), false for deactivation cascade
     *                                                         (dependents then primary) to prevent orphaned dependencies
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum $feature,
        private ?array $dependentFeatures = null,
        private bool $activate = true,
    ) {}

    /**
     * Specify dependent features to activate alongside the primary feature.
     *
     * Configures the cascade for activation mode where the primary feature is
     * enabled first, followed by all dependent features in sequence. Ensures
     * dependencies are satisfied in the correct order.
     *
     * @param  array<BackedEnum|string> $features Array of features to activate after the
     *                                            primary feature is enabled
     * @return self                     New conductor instance configured for activation cascade
     */
    public function activating(array $features): self
    {
        return new self($this->manager, $this->feature, $features, true);
    }

    /**
     * Specify dependent features to deactivate alongside the primary feature.
     *
     * Configures the cascade for deactivation mode where dependent features are
     * disabled first, followed by the primary feature. Prevents orphaned dependencies
     * by removing them before their parent feature.
     *
     * @param  array<BackedEnum|string> $features Array of features to deactivate before
     *                                            the primary feature is disabled
     * @return self                     New conductor instance configured for deactivation cascade
     */
    public function deactivating(array $features): self
    {
        return new self($this->manager, $this->feature, $features, false);
    }

    /**
     * Execute the cascade operation on the specified context.
     *
     * Terminal method applying the coordinated state change to the target context.
     * Activation cascades enable primary then dependents; deactivation cascades
     * disable dependents then primary. Throws exception if dependents not specified.
     *
     * ```php
     * // Enable API and all related features
     * Toggl::cascade('api')
     *     ->activating(['webhooks', 'rate_limiting', 'documentation'])
     *     ->for($organization);
     *
     * // Disable feature and cleanup dependents
     * Toggl::cascade('premium')
     *     ->deactivating(['advanced_reports', 'priority_support'])
     *     ->for($user);
     * ```
     *
     * @param mixed $context Target context to receive the cascading state changes
     *
     * @throws MissingDependentFeaturesException When dependent features not specified via activating() or deactivating()
     */
    public function for(mixed $context): void
    {
        throw_if($this->dependentFeatures === null, MissingDependentFeaturesException::notSpecified());

        $driver = $this->manager->for($context);

        if ($this->activate) {
            // Activate primary feature first
            $driver->activate($this->feature);

            // Then activate all dependents
            foreach ($this->dependentFeatures as $dependent) {
                $driver->activate($dependent);
            }
        } else {
            // Deactivate dependents first
            foreach ($this->dependentFeatures as $dependent) {
                $driver->deactivate($dependent);
            }

            // Then deactivate primary feature
            $driver->deactivate($this->feature);
        }
    }

    /**
     * Get the primary cascade feature.
     *
     * @return BackedEnum|string Primary feature serving as cascade root
     */
    public function feature(): string|BackedEnum
    {
        return $this->feature;
    }

    /**
     * Get the configured dependent features.
     *
     * @return null|array<BackedEnum|string> Array of dependent features, or null if not yet configured
     */
    public function dependents(): ?array
    {
        return $this->dependentFeatures;
    }

    /**
     * Check if this cascade is configured for activation.
     *
     * @return bool True for activation cascade, false for deactivation cascade
     */
    public function isActivating(): bool
    {
        return $this->activate;
    }
}
