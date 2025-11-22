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

use function abs;
use function crc32;
use function is_object;
use function is_scalar;
use function max;
use function method_exists;
use function min;
use function mt_rand;
use function property_exists;
use function spl_object_hash;

/**
 * Conductor for percentage-based gradual feature rollouts with consistent context assignment.
 *
 * Enables controlled feature releases by activating features for a specified percentage
 * of contexts. Supports sticky rollouts using consistent hashing to ensure the same users
 * remain in the rollout as the percentage increases, and non-sticky random rollouts for
 * testing. Ideal for canary releases, A/B testing, and gradual feature adoption.
 *
 * ```php
 * // Sticky rollout - same users always included as percentage grows
 * Toggl::rollout('new-dashboard', 25)
 *     ->withStickiness(true)
 *     ->for($user);
 *
 * // Custom seed for deterministic rollout across environments
 * Toggl::rollout('beta-feature', 50)
 *     ->withSeed('production-seed')
 *     ->for($organization);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class RolloutConductor
{
    /**
     * Create a new rollout conductor instance.
     *
     * Initializes an immutable rollout conductor with configurable percentage, stickiness,
     * and optional deterministic seed. Typically instantiated via FeatureManager's rollout()
     * method rather than directly.
     *
     * @param FeatureManager    $manager    the feature manager instance used to activate or deactivate
     *                                      the feature for contexts based on rollout calculations
     * @param BackedEnum|string $feature    The feature identifier to gradually roll out. Can be a string
     *                                      name or BackedEnum for type-safe feature references.
     * @param int               $percentage Target rollout percentage between 0 (none) and 100 (all).
     *                                      Determines what portion of contexts will have the feature activated.
     *                                      Defaults to 0 for conservative rollout initialization.
     * @param bool              $sticky     Whether to use consistent hashing for sticky rollouts. When true,
     *                                      the same contexts are always included as percentage increases. When
     *                                      false, uses random selection on each check. Defaults to true.
     * @param null|string       $seed       Optional custom seed for deterministic hash-based rollout assignment.
     *                                      Useful for consistent rollouts across environments or testing.
     *                                      Defaults to null, using the feature name as seed.
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum $feature,
        private int $percentage = 0,
        private bool $sticky = true,
        private ?string $seed = null,
    ) {}

    /**
     * Set the target rollout percentage.
     *
     * Creates a new conductor instance with the updated percentage, clamped to the valid
     * range of 0-100. Values below 0 are adjusted to 0, and values above 100 are adjusted
     * to 100. This ensures safe percentage handling without exceptions.
     *
     * @param  int  $percent Desired rollout percentage. Will be clamped to 0-100 range.
     *                       0 deactivates for all contexts, 100 activates for all contexts.
     * @return self new conductor instance with updated percentage
     */
    public function toPercentage(int $percent): self
    {
        $percent = max(0, min(100, $percent));

        return new self($this->manager, $this->feature, $percent, $this->sticky, $this->seed);
    }

    /**
     * Configure whether to use sticky or random rollouts.
     *
     * Sticky rollouts use consistent hashing (CRC32) to deterministically assign contexts
     * to rollout buckets, ensuring the same contexts are always included as the percentage
     * increases. This provides a stable user experience during gradual rollouts. Non-sticky
     * rollouts use random selection each time, useful for rapid testing or experimentation.
     *
     * @param  bool $sticky true for consistent hash-based sticky rollouts (recommended for
     *                      production gradual releases), false for random rollouts per check
     *                      (useful for testing or experimentation)
     * @return self new conductor instance with updated stickiness setting
     */
    public function withStickiness(bool $sticky): self
    {
        return new self($this->manager, $this->feature, $this->percentage, $sticky, $this->seed);
    }

    /**
     * Set a custom seed for deterministic rollout assignment.
     *
     * Allows control over the hash function used for context assignment in sticky rollouts.
     * Useful for ensuring consistent rollout behavior across different environments,
     * testing specific user distributions, or reproducing rollout scenarios.
     *
     * @param  string $seed custom seed string used in hash calculation alongside context
     *                      identifier. Different seeds produce different context distributions
     *                      even with the same percentage and context identifiers.
     * @return self   new conductor instance with updated seed configuration
     */
    public function withSeed(string $seed): self
    {
        return new self($this->manager, $this->feature, $this->percentage, $this->sticky, $seed);
    }

    /**
     * Apply the rollout logic to determine and set feature activation for the context.
     *
     * Terminal method that evaluates whether the context should be included in the rollout
     * based on configured percentage and stickiness. Automatically activates or deactivates
     * the feature for the context as needed. Handles edge cases: 0% deactivates all, 100%
     * activates all, and intermediate percentages use hash-based or random assignment.
     *
     * @param  mixed $context the context entity (user, team, organization, etc.) to evaluate
     *                        for rollout inclusion. Must be an object with an ID or scalar value.
     * @return bool  true if the context is included in the rollout and feature was activated,
     *               false if excluded and feature was deactivated or remained inactive
     */
    public function for(mixed $context): bool
    {
        $contextdDriver = $this->manager->for($context);

        // If percentage is 0, deactivate
        if ($this->percentage === 0) {
            if ($contextdDriver->active($this->feature)) {
                $contextdDriver->deactivate($this->feature);
            }

            return false;
        }

        // If percentage is 100, activate everyone
        if ($this->percentage === 100) {
            if (!$contextdDriver->active($this->feature)) {
                $contextdDriver->activate($this->feature);
            }

            return true;
        }

        // Calculate if context is in rollout
        $isInRollout = $this->calculateRollout($context);

        // Get current state
        $isCurrentlyActive = $contextdDriver->active($this->feature);

        // Apply state changes
        if ($isInRollout && !$isCurrentlyActive) {
            $contextdDriver->activate($this->feature);
        } elseif (!$isInRollout && $isCurrentlyActive) {
            $contextdDriver->deactivate($this->feature);
        }

        return $isInRollout;
    }

    /**
     * Retrieve the feature identifier being rolled out.
     *
     * @return BackedEnum|string the feature name or enum case
     */
    public function feature(): string|BackedEnum
    {
        return $this->feature;
    }

    /**
     * Retrieve the configured rollout percentage.
     *
     * @return int percentage value between 0 and 100
     */
    public function percentage(): int
    {
        return $this->percentage;
    }

    /**
     * Check whether sticky rollouts are enabled.
     *
     * @return bool true if using consistent hashing, false for random assignment
     */
    public function isSticky(): bool
    {
        return $this->sticky;
    }

    /**
     * Retrieve the custom seed for rollout hashing.
     *
     * @return null|string the seed string if configured, null if using feature name as seed
     */
    public function seed(): ?string
    {
        return $this->seed;
    }

    /**
     * Calculate whether the context is included in the rollout based on configuration.
     *
     * Uses either random selection (non-sticky) or consistent hashing (sticky) to determine
     * rollout inclusion. Sticky mode ensures deterministic assignment based on context ID and seed.
     *
     * @param  mixed $context the context to evaluate for rollout inclusion
     * @return bool  true if context should be in the rollout, false otherwise
     */
    private function calculateRollout(mixed $context): bool
    {
        if (!$this->sticky) {
            // Random non-sticky rollout
            return mt_rand(1, 100) <= $this->percentage;
        }

        // Sticky rollout using consistent hashing
        $contextId = $this->getContextIdentifier($context);
        $seed = $this->seed ?? $this->featureToString($this->feature);
        $hash = $this->consistentHash($contextId, $seed);

        return $hash <= $this->percentage;
    }

    /**
     * Extract a unique, consistent identifier from the context for hash calculation.
     *
     * Attempts to retrieve an ID from common object properties (id, getId()) or falls back
     * to object hash or scalar string conversion. This identifier must be stable across
     * requests for sticky rollouts to work correctly.
     *
     * @param  mixed  $context the context object or scalar value to extract an identifier from
     * @return string stable identifier string used for consistent hashing
     */
    private function getContextIdentifier(mixed $context): string
    {
        if (is_object($context)) {
            // Try common ID properties
            if (property_exists($context, 'id')) {
                $id = $context->id;

                if (is_scalar($id)) {
                    return (string) $id;
                }
            }

            if (method_exists($context, 'getId')) {
                $id = $context->getId();

                if (is_scalar($id)) {
                    return (string) $id;
                }
            }

            // Fall back to object hash
            return spl_object_hash($context);
        }

        if (is_scalar($context)) {
            return (string) $context;
        }

        // For non-scalar, non-object values (arrays, resources, etc.)
        return 'unknown';
    }

    /**
     * Calculate a consistent hash value for deterministic rollout assignment.
     *
     * Uses CRC32 algorithm to hash the combination of seed and context ID, then maps
     * the result to a 0-100 range. This ensures the same context-seed combination always
     * produces the same rollout bucket assignment for sticky rollouts.
     *
     * @param  string $contextId unique context identifier string
     * @param  string $seed      seed string (feature name or custom seed) for hash variability
     * @return int    hash value between 0 and 100 representing rollout bucket assignment
     */
    private function consistentHash(string $contextId, string $seed): int
    {
        $hash = crc32($seed.':'.$contextId);

        // Convert to percentage (0-100)
        return abs($hash) % 101;
    }

    /**
     * Convert feature identifier to string representation for hashing.
     *
     * Extracts the underlying string value from BackedEnum cases or returns
     * the string as-is. Used to normalize feature identifiers for seed generation.
     *
     * @param  BackedEnum|string $feature the feature identifier to convert
     * @return string            string representation of the feature
     */
    private function featureToString(BackedEnum|string $feature): string
    {
        return $feature instanceof BackedEnum ? (string) $feature->value : $feature;
    }
}
