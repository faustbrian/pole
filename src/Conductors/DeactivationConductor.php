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
 * Fluent conductor for feature-first deactivation pattern.
 *
 * Enables the reverse-flow pattern: Toggl::deactivate('premium')->for($user)
 * Supports batch operations for both features and contexts, executing deactivation
 * across all combinations when arrays are provided.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class DeactivationConductor
{
    /**
     * Create a new deactivation conductor instance.
     *
     * @param FeatureManager                             $manager      Feature manager instance for managing feature state
     *                                                                 across contexts and handling deactivation operations
     * @param array<BackedEnum|string>|BackedEnum|string $features     Feature(s) to deactivate. Supports single feature (string
     *                                                                 or enum) or array of features for batch deactivation
     * @param null|array<string, mixed>                  $scopes       Scope criteria for scoped deactivation. When set, deactivation
     *                                                                 targets features matching the scope pattern instead of direct
     *                                                                 context binding (supports wildcard with null values)
     * @param null|string                                $kindOverride Kind override for cross-context scope operations. When null,
     *                                                                 kind is automatically derived from context passed to for()
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum|array $features,
        private ?array $scopes = null,
        private ?string $kindOverride = null,
    ) {}

    /**
     * Enable scoped deactivation for subsequent operations.
     *
     * Configures the conductor to deactivate features using scope criteria instead of
     * direct context binding. The scope pattern allows targeting features across multiple
     * contexts that match the criteria, with null values acting as wildcards.
     *
     * The kind is automatically derived from the context passed to for(). Use the optional
     * $kind parameter only when performing cross-context scope operations.
     *
     * ```php
     * Toggl::deactivate('premium')->withScopes([
     *     'company_id' => 3,
     *     'org_id' => 2,
     *     'user_id' => null, // Wildcard: all users in org 2 of company 3
     * ])->for($user);
     * ```
     *
     * @param  array<string, mixed> $scopes Scope criteria properties where null values act as wildcards,
     *                                      allowing deactivation across multiple matching contexts
     * @param  null|string          $kind   Optional kind override for cross-context scenarios. When null,
     *                                      automatically derived from context passed to for()
     * @return self                 New immutable conductor instance with scope configuration applied
     */
    public function withScopes(array $scopes, ?string $kind = null): self
    {
        return new self(
            $this->manager,
            $this->features,
            $scopes,
            $kind,
        );
    }

    /**
     * Deactivate the feature(s) for the given context(s) (terminal method).
     *
     * Executes the deactivation operation. When arrays are provided for both features
     * and contexts, deactivates all features for all contexts in a cartesian product pattern.
     *
     * When withScopes() has been called, performs scoped deactivation using the configured
     * scope criteria instead of direct context binding. The kind is derived from the context
     * unless explicitly overridden in withScopes().
     *
     * @param mixed $context Single context or array of contexts. Supports any context type
     *                       recognized by the feature manager (user, team, organization, etc.)
     */
    public function for(mixed $context): void
    {
        $contexts = is_array($context) ? $context : [$context];

        foreach ($contexts as $ctx) {
            $interaction = $this->manager->for($ctx);

            // Apply scope scope if configured
            if ($this->scopes !== null) {
                $kind = $this->kindOverride;
                $interaction = $interaction->withScopes($this->scopes, $kind);
            }

            /** @var array<string>|BackedEnum|string $feature */
            $feature = $this->features;
            $interaction->deactivate($feature);
        }
    }
}
