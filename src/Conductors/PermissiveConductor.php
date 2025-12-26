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
 * Fluent conductor for permissive/default-allow activation strategy.
 *
 * Implements inverted feature logic where features are considered active by default
 * unless explicitly deactivated. This enables permissive feature management where
 * new features are automatically available until specifically forbidden.
 *
 * Key difference from standard (restrictive) activation:
 * - Restrictive: unknown features = inactive (false)
 * - Permissive: unknown features = active (true)
 *
 * Use cases:
 * - Beta features enabled for all users unless opted out (opt-out strategy)
 * - Default-on experiments where specific segments opt out
 * - Progressive feature rollback (deactivate for problematic contexts)
 * - Denylist-based access control
 * - Permissive security models
 *
 * Accessible via semantic aliases:
 * - Toggl::denylist() / deny() - Security/access control contexts
 * - Toggl::defaultAllow() / block() - Feature rollout contexts
 * - Toggl::permissive() / restrict() - Permission contexts
 * - Toggl::optOut() / optOutFrom() - User preference contexts
 *
 * ```php
 * // Denylist approach
 * if (Toggl::denylist('api-v2')->for($user)) {
 *     // Allowed unless explicitly denied
 * }
 * Toggl::deny('api-v2')->for($abusiveUser);
 *
 * // Default-allow approach
 * if (Toggl::defaultAllow('beta-ui')->for($user)) {
 *     // Enabled by default
 * }
 * Toggl::block('beta-ui')->for($incompatibleClient);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class PermissiveConductor
{
    /**
     * Create a new permissive conductor instance.
     *
     * @param FeatureManager                             $manager  Core feature manager instance
     * @param array<BackedEnum|string>|BackedEnum|string $features Feature name(s) to check
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum|array $features,
    ) {}

    /**
     * Check if features are active using permissive/default-allow logic.
     *
     * Returns true unless the feature is explicitly set to Inactive.
     * Uses isForbidden() which checks for explicit Inactive state only.
     *
     * Terminal method that evaluates feature state for specified contexts.
     *
     * @param  mixed $context Single context entity or array of contexts
     * @return bool  True if feature is active (not explicitly forbidden) for all contexts
     */
    public function for(mixed $context): bool
    {
        $contexts = is_array($context) ? $context : [$context];
        $features = is_array($this->features) ? $this->features : [$this->features];

        foreach ($contexts as $ctx) {
            $interaction = $this->manager->for($ctx);

            foreach ($features as $feature) {
                // If ANY feature is explicitly forbidden (state === Inactive), return false
                if ($interaction->isForbidden($feature)) {
                    return false;
                }
            }
        }

        // Not explicitly forbidden = allowed
        return true;
    }

    /**
     * Get the feature(s) being checked.
     *
     * @return array<BackedEnum|string>|BackedEnum|string Feature identifier(s)
     */
    public function features(): string|BackedEnum|array
    {
        return $this->features;
    }
}
