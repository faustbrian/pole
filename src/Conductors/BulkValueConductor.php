<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use Cline\Toggl\FeatureManager;

use function is_array;

/**
 * Conductor for bulk setting multiple feature-value pairs with distinct values.
 *
 * Optimized for scenarios requiring multiple features with different values applied
 * to the same context(s), such as user preferences or configuration bundles. Unlike
 * batch() which uses Cartesian products, bulk() maintains the specific value for each
 * feature, enabling efficient configuration updates in a single operation.
 *
 * Example: Toggl::bulk(['theme' => 'dark', 'language' => 'es', 'timezone' => 'UTC'])
 * ->for($user) sets three distinct configuration values without Cartesian multiplication.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class BulkValueConductor
{
    /**
     * Create a new bulk value conductor instance.
     *
     * @param FeatureManager       $manager Core feature manager instance providing storage access for
     *                                      executing feature-value assignments across target contexts
     * @param array<string, mixed> $values  Feature-to-value mapping associating each feature name with
     *                                      its specific value, enabling distinct per-feature assignments
     *                                      rather than uniform values like batch operations provide
     */
    public function __construct(
        private FeatureManager $manager,
        private array $values,
    ) {}

    /**
     * Apply all feature-value pairs to the specified context(s).
     *
     * Terminal method iterating through the configured feature-value map and applying
     * each pair to all provided contexts. Normalizes single contexts to arrays for consistent
     * processing, enabling both single-context and multi-context bulk updates.
     *
     * ```php
     * // Apply multiple settings to one user
     * Toggl::bulk([
     *     'theme' => 'dark',
     *     'language' => 'es',
     *     'notifications' => true,
     * ])->for($user);
     *
     * // Apply same settings to multiple users
     * Toggl::bulk(['plan' => 'premium', 'api_access' => true])
     *     ->for([$user1, $user2]);
     * ```
     *
     * @param mixed $context Single context or array of contexts to receive all feature-value pairs,
     *                       supporting users, organizations, or other contextual entities
     */
    public function for(mixed $context): void
    {
        $contexts = is_array($context) ? $context : [$context];

        foreach ($contexts as $c) {
            foreach ($this->values as $feature => $value) {
                $this->manager->for($c)->activate($feature, $value);
            }
        }
    }
}
