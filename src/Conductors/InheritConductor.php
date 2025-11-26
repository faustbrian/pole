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

use function in_array;

/**
 * Conductor for inheriting features from parent contexts.
 *
 * Enables context scope where child contexts inherit features from parent contexts.
 * Supports cascading inheritance and selective feature inheritance. Child context's
 * own settings always take precedence over inherited values.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class InheritConductor
{
    /**
     * Create a new inherit conductor instance.
     *
     * @param FeatureManager                $manager        Feature manager instance for managing feature state across
     *                                                      contexts and coordinating inheritance operations
     * @param mixed                         $childContext   Child context that receives inherited features. Supports any
     *                                                      context type (user, team, organization, etc.)
     * @param null|array<BackedEnum|string> $onlyFeatures   Whitelist of features to inherit from parent. When set, only
     *                                                      these features are copied. Mutually exclusive with exceptFeatures
     * @param null|array<BackedEnum|string> $exceptFeatures Blacklist of features to exclude from inheritance. When set,
     *                                                      all parent features except these are inherited. Mutually
     *                                                      exclusive with onlyFeatures
     */
    public function __construct(
        private FeatureManager $manager,
        private mixed $childContext,
        private ?array $onlyFeatures = null,
        private ?array $exceptFeatures = null,
    ) {}

    /**
     * Specify features to inherit (whitelist).
     *
     * Returns a new conductor instance configured to inherit only the specified features.
     * All other features from the parent will be ignored. Mutually exclusive with except().
     *
     * @param  array<BackedEnum|string> $features Features to inherit from parent. Only these
     *                                            features will be copied to the child context
     * @return self                     New conductor instance with whitelist applied
     */
    public function only(array $features): self
    {
        return new self($this->manager, $this->childContext, $features);
    }

    /**
     * Specify features to exclude from inheritance (blacklist).
     *
     * Returns a new conductor instance configured to inherit all features except the
     * specified ones. Mutually exclusive with only().
     *
     * @param  array<BackedEnum|string> $features Features to exclude from inheritance.
     *                                            All other parent features will be inherited
     * @return self                     New conductor instance with blacklist applied
     */
    public function except(array $features): self
    {
        return new self($this->manager, $this->childContext, null, $features);
    }

    /**
     * Execute inheritance from parent context (terminal method).
     *
     * Inherits features from parent to child context, applying any configured filters.
     * Child context's existing features are never overwritten - they always take precedence.
     * Supports both boolean features and features with custom configuration values.
     *
     * @param mixed $parentContext Parent context to inherit from. Supports any context type
     *                             recognized by the feature manager (user, team, organization, etc.)
     */
    public function from(mixed $parentContext): void
    {
        $parentDriver = $this->manager->for($parentContext);
        $allFeatures = $parentDriver->stored();

        foreach ($allFeatures as $feature => $value) {
            // Skip if only() specified and feature not in whitelist
            if ($this->onlyFeatures !== null && !in_array($feature, $this->onlyFeatures, true)) {
                continue;
            }

            // Skip if except() specified and feature in blacklist
            if ($this->exceptFeatures !== null && in_array($feature, $this->exceptFeatures, true)) {
                continue;
            }

            // Only inherit if child doesn't already have this feature
            $childDriver = $this->manager->for($this->childContext);

            if ($childDriver->active($feature)) {
                continue; // Child's own setting takes precedence
            }

            // Inherit feature with its value
            if ($value === true || $value === false) {
                // Boolean feature
                if ($value) {
                    $childDriver->activate($feature);
                }
            } else {
                // Feature with custom value
                $childDriver->activate($feature, $value);
            }
        }
    }

    /**
     * Get the child context.
     *
     * Returns the context that will inherit features from the parent context.
     *
     * @return mixed The child context
     */
    public function childContext(): mixed
    {
        return $this->childContext;
    }

    /**
     * Get the whitelist features.
     *
     * Returns the array of features that should be inherited from the parent,
     * or null if no whitelist is configured (meaning all features will be inherited
     * unless a blacklist is specified).
     *
     * @return null|array<BackedEnum|string> Features to inherit, or null if no filter applied
     */
    public function onlyFeatures(): ?array
    {
        return $this->onlyFeatures;
    }

    /**
     * Get the blacklist features.
     *
     * Returns the array of features that should be excluded from inheritance,
     * or null if no blacklist is configured (meaning all features will be inherited
     * unless a whitelist is specified).
     *
     * @return null|array<BackedEnum|string> Features to exclude, or null if no filter applied
     */
    public function exceptFeatures(): ?array
    {
        return $this->exceptFeatures;
    }
}
