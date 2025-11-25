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
 * Conductor for copying features from one context to another.
 *
 * Supports full copy, selective copy with only(), and filtered copy with except().
 * Feature values are preserved during copy, including boolean states and custom values.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class CopyConductor
{
    /**
     * Create a new copy conductor instance.
     *
     * @param FeatureManager                $manager        The feature manager instance for managing feature state
     *                                                      across different contexts and handling storage operations
     * @param mixed                         $sourceContext  Source context to copy from. Can be any context type supported
     *                                                      by the feature manager (user, team, organization, etc.)
     * @param null|array<BackedEnum|string> $onlyFeatures   Features to include in the copy operation. When set, only these
     *                                                      features will be copied (whitelist pattern). Mutually exclusive with exceptFeatures
     * @param null|array<BackedEnum|string> $exceptFeatures Features to exclude from copy operation. When set, all features
     *                                                      except these will be copied (blacklist pattern). Mutually exclusive with onlyFeatures
     */
    public function __construct(
        private FeatureManager $manager,
        private mixed $sourceContext,
        private ?array $onlyFeatures = null,
        private ?array $exceptFeatures = null,
    ) {}

    /**
     * Specify features to include (whitelist).
     *
     * Returns a new conductor instance configured to copy only the specified features.
     * This method is mutually exclusive with except() - using both will result in
     * only() taking precedence.
     *
     * @param  array<BackedEnum|string> $features Features to copy. All other features will be excluded
     * @return self                     New conductor instance with whitelist applied
     */
    public function only(array $features): self
    {
        return new self($this->manager, $this->sourceContext, $features);
    }

    /**
     * Specify features to exclude (blacklist).
     *
     * Returns a new conductor instance configured to copy all features except the specified ones.
     * This method is mutually exclusive with only() - using both will result in only() taking precedence.
     *
     * @param  array<BackedEnum|string> $features Features to exclude from copy operation
     * @return self                     New conductor instance with blacklist applied
     */
    public function except(array $features): self
    {
        return new self($this->manager, $this->sourceContext, null, $features);
    }

    /**
     * Execute the copy operation (terminal method).
     *
     * Copies features from source context to target context, applying any configured filters.
     * Boolean features maintain their true/false state. Features with custom values have
     * those values preserved. Only features active in the source context are copied.
     *
     * @param mixed $targetContext Target context to copy to. Can be any context type supported
     *                             by the feature manager (user, team, organization, etc.)
     */
    public function copyTo(mixed $targetContext): void
    {
        $sourceDriver = $this->manager->for($this->sourceContext);
        $allFeatures = $sourceDriver->stored();

        foreach ($allFeatures as $feature => $value) {
            // Skip if only() specified and feature not in whitelist
            if ($this->onlyFeatures !== null && !in_array($feature, $this->onlyFeatures, true)) {
                continue;
            }

            // Skip if except() specified and feature in blacklist
            if ($this->exceptFeatures !== null && in_array($feature, $this->exceptFeatures, true)) {
                continue;
            }

            // Copy feature with its value
            if ($value === true || $value === false) {
                // Boolean feature
                if ($value) {
                    $this->manager->for($targetContext)->activate($feature);
                }
            } else {
                // Feature with custom value
                $this->manager->for($targetContext)->activate($feature, $value);
            }
        }
    }

    /**
     * Get the source context.
     *
     * @return mixed The source context being copied from
     */
    public function sourceContext(): mixed
    {
        return $this->sourceContext;
    }

    /**
     * Get the whitelist features.
     *
     * Returns the array of features that should be included in the copy operation,
     * or null if no whitelist is configured (meaning all features will be copied
     * unless a blacklist is specified).
     *
     * @return null|array<BackedEnum|string> Features to include, or null if no filter applied
     */
    public function onlyFeatures(): ?array
    {
        return $this->onlyFeatures;
    }

    /**
     * Get the blacklist features.
     *
     * Returns the array of features that should be excluded from the copy operation,
     * or null if no blacklist is configured (meaning all features will be copied
     * unless a whitelist is specified).
     *
     * @return null|array<BackedEnum|string> Features to exclude, or null if no filter applied
     */
    public function exceptFeatures(): ?array
    {
        return $this->exceptFeatures;
    }
}
