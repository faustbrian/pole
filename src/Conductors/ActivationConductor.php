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
use Closure;

use function is_array;

/**
 * Fluent conductor for feature-first activation pattern.
 *
 * Provides a chainable interface for activating features with optional values,
 * conditions, and dependencies before applying to target contexts. Enables the
 * reverse-flow pattern Toggl::activate('premium')->for($user) where features
 * are specified first and contexts last, creating readable activation chains.
 *
 * Supports conditional activation, dependency management, and value customization
 * through a fluent API that maintains immutability via readonly properties.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class ActivationConductor
{
    /**
     * Create a new activation conductor instance.
     *
     * @param FeatureManager                             $manager      Core feature manager instance providing access to storage
     *                                                                 and context resolution for executing feature operations
     * @param array<BackedEnum|string>|BackedEnum|string $features     Feature name(s) to activate, accepting single feature or
     *                                                                 array for batch operations with type-safe BackedEnum support
     * @param mixed                                      $value        Value to assign when activating features, defaulting to
     *                                                                 boolean true for simple flags but accepting any type for
     *                                                                 configuration features storing settings or variant identifiers
     * @param null|array<string, mixed>                  $scopes       Optional scope constraints for hierarchical activation,
     *                                                                 enabling features to apply across matching scope patterns
     *                                                                 rather than direct context binding
     * @param null|string                                $kindOverride Optional kind identifier override, bypassing automatic
     *                                                                 derivation from context type for cross-context scenarios
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum|array $features,
        private mixed $value = true,
        private ?array $scopes = null,
        private ?string $kindOverride = null,
    ) {}

    /**
     * Set a custom value for the feature(s).
     *
     * Replaces the default boolean true with a custom value for configuration
     * features that store settings, preferences, or variant identifiers. Maintains
     * fluent chaining by returning a new immutable conductor instance.
     *
     * ```php
     * Toggl::activate('theme')->withValue('dark')->for($user);
     * Toggl::activate('plan')->withValue('premium')->for($organization);
     * ```
     *
     * @param  mixed $value Custom value to assign, supporting any type including strings,
     *                      numbers, arrays, or objects for complex configuration storage
     * @return self  New immutable conductor instance with updated value
     */
    public function withValue(mixed $value): self
    {
        return new self($this->manager, $this->features, $value, $this->scopes, $this->kindOverride);
    }

    /**
     * Enable scoped activation for subsequent operations.
     *
     * When withScopes() is called, the for() method will activate features
     * using the specified scope scope instead of direct context binding.
     * This enables inheritance patterns where features apply to all matching
     * contexts within the scope.
     *
     * The `kind` is automatically derived from the context passed to for().
     * Use the optional $kind parameter only for cross-context scenarios.
     *
     * ```php
     * // Activate for all users in company 3, org 2
     * // Kind is automatically 'user' (or whatever $user's context type is)
     * Toggl::activate('premium')->withScopes([
     *     'company_id' => 3,
     *     'org_id' => 2,
     *     'user_id' => null,  // Any user (wildcard)
     * ])->for($user);
     *
     * // With custom value
     * Toggl::activate('theme')->withValue('dark')->withScopes([
     *     'org_id' => 5,
     * ])->for($user);
     *
     * // Cross-context: explicitly set kind (rare use case)
     * Toggl::activate('feature')->withScopes($scopes, 'team')->for($user);
     * ```
     *
     * @param  array<string, mixed> $scopes Scope scope properties (null = wildcard)
     * @param  null|string          $kind   Optional kind override (defaults to context type from for())
     * @return self                 New immutable conductor with scope scope configured
     */
    public function withScopes(array $scopes, ?string $kind = null): self
    {
        return new self(
            $this->manager,
            $this->features,
            $this->value,
            $scopes,
            $kind,
        );
    }

    /**
     * Add a condition that must evaluate to true for activation.
     *
     * Creates a conditional activation that only proceeds when the provided
     * closure returns true. Multiple conditions can be chained, requiring all
     * to pass. Only supports single features; arrays use the first element.
     *
     * ```php
     * Toggl::activate('admin_panel')
     *     ->onlyIf(fn($user) => $user->role === 'admin')
     *     ->for($user);
     * ```
     *
     * @param  Closure                        $condition Callback receiving context and returning boolean,
     *                                                   evaluated at activation time to determine eligibility
     * @return ConditionalActivationConductor New conductor supporting additional condition chaining
     *
     * @phpstan-assert BackedEnum|string $this->features
     */
    public function onlyIf(Closure $condition): ConditionalActivationConductor
    {
        /** @var BackedEnum|string $feature */
        $feature = is_array($this->features) ? $this->features[0] : $this->features;

        return new ConditionalActivationConductor(
            $this->manager,
            $feature,
            $this->value,
            [$condition],
            [],
        );
    }

    /**
     * Add a condition that must evaluate to false for activation.
     *
     * Creates a negative condition where activation only proceeds when the
     * closure returns false. Enables guard patterns for excluding contexts.
     * Only supports single features; arrays use the first element.
     *
     * ```php
     * Toggl::activate('beta_features')
     *     ->unless(fn($user) => $user->opted_out_beta)
     *     ->for($user);
     * ```
     *
     * @param  Closure                        $condition Callback receiving context and returning boolean,
     *                                                   activation blocked when returning true
     * @return ConditionalActivationConductor New conductor supporting additional condition chaining
     *
     * @phpstan-assert BackedEnum|string $this->features
     */
    public function unless(Closure $condition): ConditionalActivationConductor
    {
        /** @var BackedEnum|string $feature */
        $feature = is_array($this->features) ? $this->features[0] : $this->features;

        return new ConditionalActivationConductor(
            $this->manager,
            $feature,
            $this->value,
            [],
            [$condition],
        );
    }

    /**
     * Specify prerequisite features that must be activated first.
     *
     * Establishes dependency relationships between features, ensuring required
     * features are active before allowing dependent feature activation. Validates
     * prerequisites at activation time. Only supports single dependent features.
     *
     * ```php
     * Toggl::activate('api_v2')
     *     ->requires('api_enabled')
     *     ->for($organization);
     * ```
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $prerequisites Single prerequisite feature or array of required features
     *                                                                   that must be active before dependent can be activated
     * @return DependencyConductor                        New conductor for managing feature dependencies
     *
     * @phpstan-assert BackedEnum|string $this->features
     */
    public function requires(string|BackedEnum|array $prerequisites): DependencyConductor
    {
        /** @var BackedEnum|string $dependent */
        $dependent = is_array($this->features) ? $this->features[0] : $this->features;

        return new DependencyConductor($this->manager, $prerequisites, $dependent);
    }

    /**
     * Execute a callback without breaking the fluent chain.
     *
     * Enables side effects like logging, events, or debugging within activation
     * chains without interrupting the flow. Callback receives the conductor instance
     * for inspecting features and values being activated.
     *
     * ```php
     * Toggl::activate('premium')
     *     ->tap(fn($c) => Log::info('Activating premium', ['feature' => $c->features()]))
     *     ->for($user);
     * ```
     *
     * @param  Closure $callback Callback receiving this conductor instance, executed
     *                           immediately for side effects before continuing chain
     * @return self    Same conductor instance for continued method chaining
     */
    public function tap(Closure $callback): self
    {
        $callback($this);

        return $this;
    }

    /**
     * Get the feature(s) being activated.
     *
     * @return array<BackedEnum|string>|BackedEnum|string Single feature name or array of features
     *                                                    in their original format (string or BackedEnum)
     */
    public function features(): string|BackedEnum|array
    {
        return $this->features;
    }

    /**
     * Get the value that will be assigned to activated features.
     *
     * @return mixed Feature value, defaulting to boolean true for flags or custom
     *               values set via withValue() for configuration features
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * Execute feature activation for specified context(s).
     *
     * Terminal method performing the configured activation operation on target contexts.
     * Accepts single contexts or arrays for batch processing, applying all specified
     * features to all provided contexts in nested iteration. Normalizes single values
     * to arrays internally for consistent processing logic.
     *
     * When scope constraints are configured via withScopes(), activation uses scope-based
     * inheritance instead of direct context binding. The scope kind is automatically derived
     * from the context's type unless explicitly overridden during withScopes() configuration.
     *
     * ```php
     * Toggl::activate('premium')->for($user);
     * Toggl::activate(['api', 'beta'])->for([$org1, $org2]);
     *
     * // Scope-based activation (kind auto-derived from $user)
     * Toggl::activate('premium')->withScopes(['company_id' => 3])->for($user);
     * ```
     *
     * @param mixed $context Single context entity or array of contexts to receive feature
     *                       activation, supporting users, organizations, or any contextable entity
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
            $interaction->activate($feature, $this->value);
        }
    }
}
