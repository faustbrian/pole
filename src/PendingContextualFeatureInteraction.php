<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl;

use BackedEnum;
use Cline\Toggl\Concerns\NormalizesFeatureInput;
use Cline\Toggl\Drivers\Decorator;
use Cline\Toggl\Exceptions\MissingContextException;
use Cline\Toggl\Exceptions\MultipleContextException;
use Cline\Toggl\Support\ContextResolver;
use Cline\Toggl\Support\FeatureScope;
use Closure;

use function array_any;
use function array_merge;
use function array_values;
use function collect;
use function count;
use function head;
use function is_array;
use function is_string;
use function throw_if;

/**
 * Manages feature flag interactions within a specific context.
 *
 * This class provides a fluent interface for interacting with feature flags
 * for one or more contexts (e.g., users, teams, organizations). It allows bulk
 * operations and ensures all feature checks are performed within the defined context.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PendingContextualFeatureInteraction
{
    use NormalizesFeatureInput;

    /**
     * The context identifiers for feature interactions.
     *
     * Contains the context identifiers (e.g., User models, IDs, team identifiers)
     * for which feature flags will be checked or modified. Multiple contexts can be
     * added for bulk operations.
     *
     * @var array<mixed>
     */
    private array $context = [];

    /**
     * Whether to use feature scope for feature resolution.
     *
     * When true, feature lookups will check both exact context matches
     * and scoped scope matches.
     */
    private bool $useScopes = false;

    /**
     * Explicit feature scope to use instead of context's default.
     *
     * When set, this scope is used for scoped resolution instead
     * of extracting from the context.
     */
    private ?FeatureScope $explicitFeatureScope = null;

    /**
     * Create a new Pending Context-aware Feature Interaction instance.
     *
     * @param Decorator $driver The decorated feature driver providing access to feature storage
     *                          and resolution. The decorator wraps the underlying driver to add
     *                          caching and normalization behavior for consistent feature handling.
     */
    public function __construct(
        private readonly Decorator $driver,
    ) {}

    /**
     * Add context to the feature interaction.
     *
     * Context can be a single value or an array of values (e.g., User models, IDs).
     * All subsequent operations will apply to all contexts in the collection. Multiple
     * calls to this method accumulate contexts rather than replacing them.
     *
     * @param  mixed $context A single context identifier or array of context identifiers
     * @return $this For method chaining
     */
    public function for(mixed $context): static
    {
        $this->context = array_merge($this->context, collect()->wrap($context)->all());

        return $this;
    }

    /**
     * Enable scoped feature resolution for this interaction.
     *
     * When called without arguments, uses the context's feature scope (from
     * TogglContextable interface). When called with an array, uses that explicit
     * feature scope instead.
     *
     * This enables features activated at organizational levels to automatically
     * apply to matching contexts without duplicating database records.
     *
     * The `kind` is automatically derived from the context. Use the optional
     * $kind parameter only for cross-context scenarios.
     *
     * ```php
     * // Use context's built-in scope (kind auto-derived)
     * Toggl::for($user)->withScopes()->active('premium');
     *
     * // Use explicit feature scope (kind auto-derived from $user)
     * Toggl::for($user)->withScopes([
     *     'company_id' => 3,
     *     'org_id' => 5,
     *     'team_id' => null,  // wildcard
     * ])->active('premium');
     *
     * // Cross-context: explicitly set kind (rare use case)
     * Toggl::for($user)->withScopes($scopes, 'team')->active('premium');
     * ```
     *
     * @param  null|array<string, mixed> $scopes Optional explicit feature scope properties
     * @param  null|string               $kind   Optional kind override (defaults to context type)
     * @return $this                     For method chaining
     */
    public function withScopes(?array $scopes = null, ?string $kind = null): static
    {
        $this->useScopes = true;

        if ($scopes !== null) {
            // Derive kind from context if not explicitly provided
            $resolvedKind = $kind ?? $this->resolveKindFromContext();
            $this->explicitFeatureScope = new FeatureScope($resolvedKind, $scopes);
        }

        return $this;
    }

    /**
     * Check if scoped resolution is enabled.
     *
     * @return bool True if scope should be considered for feature lookups
     */
    public function usesScopes(): bool
    {
        return $this->useScopes;
    }

    /**
     * Get the effective feature scope for a context.
     *
     * Returns the explicit scope if set, otherwise extracts from the context.
     *
     * @param  mixed             $context The context to get scope for
     * @return null|FeatureScope The feature scope or null if not available
     */
    public function getFeatureScope(mixed $context): ?FeatureScope
    {
        if ($this->explicitFeatureScope instanceof FeatureScope) {
            return $this->explicitFeatureScope;
        }

        return ContextResolver::extractFeatureScope($context);
    }

    /**
     * Load features into memory.
     *
     * Forces loading of the specified features for all contexts, regardless of
     * whether they're already cached. Useful for preloading features to avoid
     * N+1 queries when checking multiple features for multiple contexts.
     *
     * @codeCoverageIgnore Nested closure internals not tracked by pcov
     *
     * @param  array<int, BackedEnum|string>|BackedEnum|string $features Feature name(s) or enum(s) to load
     * @return array<string, array<int, mixed>>                Map of feature names to context values
     */
    public function load(string|BackedEnum|array $features): array
    {
        $features = $this->normalizeFeatureInput($features);
        $featureArray = is_array($features) ? $features : [$features];

        $mapped = [];

        foreach ($featureArray as $feature) {
            $mapped[$feature] = $this->getContexts();
        }

        return $this->driver->getAll($mapped);
    }

    /**
     * Load missing features into memory.
     *
     * Only loads features that haven't been cached yet, avoiding unnecessary
     * storage or resolution operations. This is the preferred method for most
     * feature checks as it minimizes database queries while ensuring features
     * are available when needed.
     *
     * @codeCoverageIgnore Nested closure internals not tracked by pcov
     *
     * @param  array<int|string, BackedEnum|string>|BackedEnum|string $features Feature name(s) or enum(s) to load if missing
     * @return array<string, array<int, mixed>>                       Map of feature names to context values
     */
    public function loadMissing(string|BackedEnum|array $features): array
    {
        $features = $this->normalizeFeatureInput($features);
        $featureArray = is_array($features) ? array_values($features) : [$features];

        $mapped = [];

        foreach ($featureArray as $feature) {
            $mapped[$feature] = $this->getContexts();
        }

        return $this->driver->getAllMissing($mapped);
    }

    /**
     * Get the value of a feature flag.
     *
     * Retrieves the resolved value for a single feature. Can only be used when
     * a single context is set - throws an exception if multiple contexts are configured.
     *
     * @param string $feature The feature name
     *
     * @throws MultipleContextException If multiple contexts are set
     *
     * @return mixed The feature value (true/false for boolean features, variant string, or custom value)
     */
    public function value(string|BackedEnum $feature): mixed
    {
        $feature = $this->normalizeFeature($feature);

        return head($this->values([$feature]));
    }

    /**
     * Get the values of multiple feature flags.
     *
     * Retrieves the resolved values for multiple features in a single operation.
     * Can only be used when a single context is set - throws an exception if multiple
     * contexts are configured. Features are loaded if not already cached.
     *
     * @param array<BackedEnum|string> $features Feature names or enums to retrieve
     *
     * @throws MultipleContextException If multiple contexts are set
     *
     * @return array<string, mixed> Map of feature names to their resolved values
     */
    public function values(array $features): array
    {
        throw_if(count($this->getContexts()) > 1, MultipleContextException::cannotRetrieveValues());

        $features = $this->normalizeFeatures($features);
        $this->loadMissing($features);

        $result = [];
        $context = $this->getContexts()[0];

        foreach ($features as $feature) {
            $name = $this->driver->name($feature);
            $result[$name] = $this->getFeatureValue($feature, $context);
        }

        return $result;
    }

    /**
     * Retrieve all defined features and their values for the current context.
     *
     * Returns a map of all defined feature names to their resolved values. This
     * includes both stored and strategy-resolved features.
     *
     * @throws MultipleContextException When multiple contexts are set (only single context supported)
     *
     * @return array<string, mixed> Map of all feature names to their resolved values
     */
    public function all(): array
    {
        return $this->values($this->driver->defined());
    }

    /**
     * Retrieve all stored features and their values for the current context.
     *
     * Returns only features that have been explicitly activated or stored in the
     * driver, excluding features that would be resolved via strategies without
     * stored overrides.
     *
     * @throws MultipleContextException When multiple contexts are set (only single context supported)
     *
     * @return array<string, mixed> Map of all stored feature names to their values
     */
    public function stored(): array
    {
        return $this->values($this->driver->stored());
    }

    /**
     * Determine if the feature is active for all configured contexts.
     *
     * @param  BackedEnum|string $feature The feature name or enum to check
     * @return bool              True if the feature is active for all configured contexts
     */
    public function active(string|BackedEnum $feature): bool
    {
        $feature = $this->normalizeFeature($feature);

        return $this->allAreActive([$feature]);
    }

    /**
     * Determine if all the features are active.
     *
     * Returns true only if ALL features are active for ALL contexts.
     *
     * @param  array<BackedEnum|string> $features Feature names or enums to check
     * @return bool                     True if all features are active for all contexts
     */
    public function allAreActive(array $features): bool
    {
        $features = $this->normalizeFeatures($features);

        // Skip loadMissing when using scope - scope lookups need fresh DB queries
        if (!$this->useScopes) {
            $this->loadMissing($features);
        }

        foreach ($features as $feature) {
            foreach ($this->getContexts() as $context) {
                if ($this->getFeatureValue($feature, $context) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determine if any of the features are active.
     *
     * Returns true if at least one feature is active for each context.
     *
     * @param  array<BackedEnum|string> $features Feature names or enums to check
     * @return bool                     True if at least one feature is active for each context
     */
    public function someAreActive(array $features): bool
    {
        $features = $this->normalizeFeatures($features);

        if (!$this->useScopes) {
            $this->loadMissing($features);
        }

        foreach ($this->getContexts() as $context) {
            $foundActive = array_any($features, fn (string|BackedEnum $feature): bool => $this->getFeatureValue($feature, $context) !== false);

            if (!$foundActive) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the feature is inactive for all configured contexts.
     *
     * @param  BackedEnum|string $feature The feature name or enum to check
     * @return bool              True if the feature is inactive for all configured contexts
     */
    public function inactive(string|BackedEnum $feature): bool
    {
        $feature = $this->normalizeFeature($feature);

        return $this->allAreInactive([$feature]);
    }

    /**
     * Determine if all the features are inactive.
     *
     * Returns true only if ALL features are inactive (false) for ALL contexts.
     *
     * @param  array<BackedEnum|string> $features Feature names or enums to check
     * @return bool                     True if all features are inactive for all contexts
     */
    public function allAreInactive(array $features): bool
    {
        $features = $this->normalizeFeatures($features);

        if (!$this->useScopes) {
            $this->loadMissing($features);
        }

        foreach ($features as $feature) {
            foreach ($this->getContexts() as $context) {
                if ($this->getFeatureValue($feature, $context) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Determine if any of the features are inactive.
     *
     * Returns true if at least one feature is inactive for each context.
     *
     * @param  array<BackedEnum|string> $features Feature names or enums to check
     * @return bool                     True if at least one feature is inactive for each context
     */
    public function someAreInactive(array $features): bool
    {
        $features = $this->normalizeFeatures($features);

        if (!$this->useScopes) {
            $this->loadMissing($features);
        }

        foreach ($this->getContexts() as $context) {
            $foundInactive = array_any($features, fn (string|BackedEnum $feature): bool => $this->getFeatureValue($feature, $context) === false);

            if (!$foundInactive) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute a callback if the feature is active.
     *
     * Provides conditional execution based on feature state. The active callback
     * receives the feature value and the interaction instance for further operations.
     *
     * @param  BackedEnum|string $feature      The feature name or enum to check
     * @param  Closure           $whenActive   Callback to execute if active (receives value and $this)
     * @param  null|Closure      $whenInactive Optional callback to execute if inactive (receives $this)
     * @return mixed             Result of the executed callback, or null if inactive with no callback
     */
    public function when(string|BackedEnum $feature, Closure $whenActive, ?Closure $whenInactive = null): mixed
    {
        $feature = $this->normalizeFeature($feature);

        if ($this->active($feature)) {
            return $whenActive($this->value($feature), $this);
        }

        if ($whenInactive instanceof Closure) {
            return $whenInactive($this);
        }

        return null;
    }

    /**
     * Execute a callback if the feature is inactive.
     *
     * Inverse of when(). Provides conditional execution when a feature is disabled.
     *
     * @param  BackedEnum|string $feature      The feature name or enum to check
     * @param  Closure           $whenInactive Callback to execute if inactive (receives $this)
     * @param  null|Closure      $whenActive   Optional callback to execute if active (receives value and $this)
     * @return mixed             Result of the executed callback
     */
    public function unless(string|BackedEnum $feature, Closure $whenInactive, ?Closure $whenActive = null): mixed
    {
        $feature = $this->normalizeFeature($feature);

        return $this->when($feature, $whenActive ?? fn (): null => null, $whenInactive);
    }

    /**
     * Activate one or more features for all configured contexts.
     *
     * Sets the feature to the specified value (defaults to true) for all configured
     * contexts. This persists the value in storage and overrides any strategy-based
     * resolution for the affected contexts, ensuring consistent feature state.
     *
     * @param array<BackedEnum|string>|BackedEnum|string $feature Feature name(s) or enum(s) to activate
     * @param mixed                                      $value   Value to set (defaults to true for boolean activation)
     */
    public function activate(string|BackedEnum|array $feature, mixed $value = true): void
    {
        $feature = $this->normalizeFeatureInput($feature);
        $features = is_array($feature) ? $feature : [$feature];

        foreach ($features as $featureName) {
            foreach ($this->getContexts() as $context) {
                $this->driver->set($featureName, $this->wrapContextWithScope($context), $value);
            }
        }
    }

    /**
     * Deactivate one or more features for all configured contexts.
     *
     * Sets the feature to false for all configured contexts. This persists the
     * false value in storage and overrides any strategy-based resolution for
     * the affected contexts, explicitly disabling the feature.
     *
     * @param array<BackedEnum|string>|BackedEnum|string $feature Feature name(s) or enum(s) to deactivate
     */
    public function deactivate(string|BackedEnum|array $feature): void
    {
        $feature = $this->normalizeFeatureInput($feature);
        $features = is_array($feature) ? $feature : [$feature];

        foreach ($features as $featureName) {
            // Purge cache for feature when using scope (affects multiple contexts)
            if ($this->explicitFeatureScope instanceof FeatureScope) {
                $this->driver->purge([$featureName]);
            }

            foreach ($this->getContexts() as $context) {
                $wrappedContext = $this->wrapContextWithScope($context);

                // If feature scope is set, delete the scoped record
                if ($this->explicitFeatureScope instanceof FeatureScope) {
                    $this->driver->delete($featureName, $wrappedContext);
                } else {
                    $this->driver->set($featureName, $wrappedContext, false);
                }
            }
        }
    }

    /**
     * Forget stored feature flag values for all configured contexts.
     *
     * Removes the stored feature values from storage for all configured contexts.
     * After forgetting, the feature will be resolved using its strategy on the
     * next check rather than using a stored override value. Useful for clearing
     * temporary overrides or resetting to default behavior.
     *
     * @param array<BackedEnum|string>|BackedEnum|string $features Feature name(s) or enum(s) to forget
     */
    public function forget(string|BackedEnum|array $features): void
    {
        $features = $this->normalizeFeatureInput($features);
        $featureArray = is_array($features) ? $features : [$features];

        foreach ($featureArray as $featureName) {
            foreach ($this->getContexts() as $context) {
                $this->driver->delete($featureName, $context);
            }
        }
    }

    /**
     * Activate all features in a group for all configured contexts.
     *
     * Sets all features belonging to the specified group to true for all
     * configured contexts. This is useful for bulk operations like enabling
     * all premium features for a user or organization.
     *
     * @param string $name The group name containing features to activate
     */
    public function activateGroup(string $name): void
    {
        $features = $this->driver->getGroup($name);

        foreach ($features as $featureName) {
            foreach ($this->getContexts() as $context) {
                $this->driver->set($featureName, $context, true);
            }
        }
    }

    /**
     * Deactivate all features in a group for all configured contexts.
     *
     * Sets all features belonging to the specified group to false for all
     * configured contexts. Useful for bulk operations like disabling all
     * experimental features or revoking feature access.
     *
     * @param string $name The group name containing features to deactivate
     */
    public function deactivateGroup(string $name): void
    {
        $features = $this->driver->getGroup($name);

        foreach ($features as $featureName) {
            foreach ($this->getContexts() as $context) {
                $this->driver->set($featureName, $context, false);
            }
        }
    }

    /**
     * Check if all features in a group are active for all configured contexts.
     *
     * Returns true if all features in the group are active for all contexts.
     * An empty group is considered "all active".
     *
     * @param  string $name The group name to check
     * @return bool   True if all features in the group are active for all contexts
     */
    public function activeInGroup(string $name): bool
    {
        $features = $this->driver->getGroup($name);

        if ($features === []) {
            return true; // Empty group is considered "all active"
        }

        $this->loadMissing($features);

        foreach ($features as $featureName) {
            foreach ($this->getContexts() as $context) {
                if ($this->getFeatureValue($featureName, $context) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if any features in a group are active for the configured contexts.
     *
     * Returns true if at least one feature in the group is active for each context.
     * An empty group returns false.
     *
     * @param  string $name The group name to check
     * @return bool   True if at least one feature in the group is active for each context
     */
    public function someActiveInGroup(string $name): bool
    {
        $features = $this->driver->getGroup($name);

        if ($features === []) {
            return false; // Empty group has no features to be active
        }

        $this->loadMissing($features);

        foreach ($this->getContexts() as $context) {
            $foundActive = array_any($features, fn (string|BackedEnum $featureName): bool => $this->getFeatureValue($featureName, $context) !== false);

            if (!$foundActive) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the assigned variant for a feature based on the current context.
     *
     * Uses consistent hashing to assign variants based on context and configured weights.
     * Once assigned, the variant is stored to ensure consistency across requests. This
     * enables A/B testing and gradual rollouts with stable user experiences.
     *
     * @param string $name The feature name to get the variant for
     *
     * @throws MultipleContextException When multiple contexts are set (only single context supported)
     *
     * @return null|string The assigned variant name, or null if no variants configured
     */
    public function variant(string $name): ?string
    {
        throw_if(count($this->getContexts()) > 1, MultipleContextException::cannotRetrieveVariants());

        $context = $this->getContexts()[0];

        // Check if variant is already stored for this context
        $storedVariant = $this->driver->get($name, $context);

        if ($storedVariant !== false && is_string($storedVariant)) {
            return $storedVariant;
        }

        // Get variant weights
        $variants = $this->driver->getVariants($name);

        if ($variants === []) {
            return null;
        }

        // Calculate variant based on consistent hashing
        $variant = Toggl::calculateVariant($name, $context, $variants);

        // Store the variant
        $this->driver->set($name, $context, $variant);

        return $variant;
    }

    /**
     * Check if a feature is enabled for all configured contexts.
     *
     * Alias for active() method.
     *
     * @param  BackedEnum|string $feature The feature name or enum to check
     * @return bool              True if the feature is enabled for all configured contexts
     */
    public function isEnabled(string|BackedEnum $feature): bool
    {
        return $this->active($feature);
    }

    /**
     * Check if a feature is disabled for all configured contexts.
     *
     * Alias for inactive() method.
     *
     * @param  BackedEnum|string $feature The feature name or enum to check
     * @return bool              True if the feature is disabled for all configured contexts
     */
    public function isDisabled(string|BackedEnum $feature): bool
    {
        return $this->inactive($feature);
    }

    /**
     * Check if any of the given features are enabled.
     *
     * Alias for someAreActive() method. Matches Laravel Collection's any()
     * method naming convention.
     *
     * @param  array<BackedEnum|string> $features Feature names or enums to check
     * @return bool                     True if at least one feature is enabled for each context
     */
    public function anyAreActive(array $features): bool
    {
        return $this->someAreActive($features);
    }

    /**
     * Check if any of the given features are disabled.
     *
     * Alias for someAreInactive() method. Matches Laravel Collection's any()
     * method naming convention.
     *
     * @param  array<BackedEnum|string> $features Feature names or enums to check
     * @return bool                     True if at least one feature is disabled for each context
     */
    public function anyAreInactive(array $features): bool
    {
        return $this->someAreInactive($features);
    }

    /**
     * Enable a feature for all configured contexts.
     *
     * Alias for activate() method.
     *
     * @param array<string>|BackedEnum|string $feature Feature name(s) or enum to enable
     * @param mixed                           $value   The value to set (defaults to true)
     */
    public function enable(string|BackedEnum|array $feature, mixed $value = true): void
    {
        $this->activate($feature, $value);
    }

    /**
     * Disable a feature for all configured contexts.
     *
     * Alias for deactivate() method.
     *
     * @param array<string>|BackedEnum|string $feature Feature name(s) or enum to disable
     */
    public function disable(string|BackedEnum|array $feature): void
    {
        $this->deactivate($feature);
    }

    /**
     * Resolve the scope kind from the current context.
     *
     * @return string The scope kind (e.g., 'user', 'team')
     */
    private function resolveKindFromContext(): string
    {
        $context = head($this->context);

        if ($context !== null) {
            return ContextResolver::resolve($context)->kind;
        }

        return 'unknown';
    }

    /**
     * Wrap a context with feature scope if one is set.
     *
     * Returns a TogglContext with the explicit feature scope attached,
     * or the original context if no feature scope is configured.
     *
     * @param  mixed              $context The original context
     * @return mixed|TogglContext The context, optionally wrapped with scope
     */
    private function wrapContextWithScope(mixed $context): mixed
    {
        if (!$this->explicitFeatureScope instanceof FeatureScope) {
            return $context;
        }

        $togglContext = ContextResolver::resolve($context);

        return $togglContext->withFeatureScope($this->explicitFeatureScope);
    }

    /**
     * Get the context identifiers to pass to the driver.
     *
     * Returns the configured contexts.
     *
     * @throws MissingContextException If no context was set
     *
     * @return array<mixed> Array of context identifiers
     */
    private function getContexts(): array
    {
        throw_if($this->context === [], MissingContextException::notSet());

        return $this->context;
    }

    /**
     * Get the value of a feature for a context, using scope if enabled.
     *
     * @param  BackedEnum|string $feature The feature name or enum
     * @param  mixed             $context The context to check
     * @return mixed             The feature value
     */
    private function getFeatureValue(string|BackedEnum $feature, mixed $context): mixed
    {
        if ($this->useScopes && $context !== null) {
            $scope = $this->getFeatureScope($context);

            if ($scope instanceof FeatureScope) {
                return $this->driver->getWithScope($feature, $context, $scope);
            }
        }

        return $this->driver->get($feature, $context);
    }
}
