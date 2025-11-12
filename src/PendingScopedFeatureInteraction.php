<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole;

use Cline\Pole\Drivers\Decorator;
use Closure;
use Illuminate\Support\Collection;
use RuntimeException;

use function abs;
use function array_key_last;
use function array_merge;
use function assert;
use function count;
use function crc32;
use function head;
use function is_string;
use function throw_if;

/**
 * Manages feature flag interactions within a specific scope.
 *
 * This class provides a fluent interface for interacting with feature flags
 * for one or more scopes (e.g., users, teams, organizations). It allows bulk
 * operations and ensures all feature checks are performed within the defined scope.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PendingScopedFeatureInteraction
{
    /**
     * The feature interaction scope.
     *
     * Contains the scope identifiers (e.g., User models, IDs) for which
     * feature flags will be checked or modified.
     *
     * @var array<mixed>
     */
    private array $scope = [];

    /**
     * Create a new Pending Scoped Feature Interaction instance.
     *
     * @param Decorator $driver The feature driver to use for storage operations
     */
    public function __construct(
        /**
         * The feature driver.
         */
        private readonly Decorator $driver,
    ) {}

    /**
     * Add scope to the feature interaction.
     *
     * Scope can be a single value or an array of values (e.g., User models, IDs).
     * All subsequent operations will apply to all scopes in the collection.
     *
     * @param  mixed $scope A single scope or array of scopes
     * @return $this
     */
    public function for(mixed $scope): static
    {
        $this->scope = array_merge($this->scope, Collection::wrap($scope)->all());

        return $this;
    }

    /**
     * Load the feature into memory.
     *
     * Forces loading of the specified features for all scopes, regardless of
     * whether they're already cached.
     *
     * @param  array<int, string>|string        $features Feature name(s) to load
     * @return array<string, array<int, mixed>> Map of feature names to scope values
     */
    public function load(string|array $features): array
    {
        return Collection::wrap($features)
            ->mapWithKeys(fn ($feature): array => [$feature => $this->scope()])
            ->pipe(fn ($features): array => $this->driver->getAll($features->all()));
    }

    /**
     * Load the missing features into memory.
     *
     * Only loads features that haven't been cached yet, avoiding unnecessary
     * storage or resolution operations.
     *
     * @param  array<int|string, string>|string $features Feature name(s) to load if missing
     * @return array<string, array<int, mixed>> Map of feature names to scope values
     */
    public function loadMissing(string|array $features): array
    {
        return Collection::wrap($features)
            ->values()
            ->mapWithKeys(fn ($feature): array => [$feature => $this->scope()])
            ->pipe(fn ($features): array => $this->driver->getAllMissing($features->all()));
    }

    /**
     * Get the value of the flag.
     *
     * @param string $feature The feature name
     *
     * @throws RuntimeException If multiple scopes are set
     *
     * @return mixed The feature value (true/false for boolean features, or variant string)
     */
    public function value(string $feature): mixed
    {
        return head($this->values([$feature]));
    }

    /**
     * Get the values of the flags.
     *
     * @param array<string> $features Feature names to retrieve
     *
     * @throws RuntimeException If multiple scopes are set
     *
     * @return array<string, mixed> Map of feature names to their values
     */
    public function values(array $features): array
    {
        throw_if(count($this->scope()) > 1, RuntimeException::class, 'It is not possible to retrieve the values for multiple scopes.');

        $this->loadMissing($features);

        return Collection::make($features)
            ->mapWithKeys(fn (string $feature): array => [
                $this->driver->name($feature) => $this->driver->get($feature, $this->scope()[0]),
            ])
            ->all();
    }

    /**
     * Retrieve all the features and their values.
     *
     * @throws RuntimeException If multiple scopes are set
     *
     * @return array<string, mixed> Map of all feature names to their values
     */
    public function all(): array
    {
        return $this->values($this->driver->defined());
    }

    /**
     * Determine if the feature is active.
     *
     * @param  string $feature The feature name
     * @return bool   True if the feature is active for all scopes
     */
    public function active(string $feature): bool
    {
        return $this->allAreActive([$feature]);
    }

    /**
     * Determine if all the features are active.
     *
     * Returns true only if ALL features are active for ALL scopes.
     *
     * @param  array<string> $features Feature names to check
     * @return bool          True if all features are active for all scopes
     */
    public function allAreActive(array $features): bool
    {
        $this->loadMissing($features);

        return Collection::make($features)
            ->crossJoin($this->scope())
            ->every(function (array $bits): bool {
                assert(is_string($bits[0]));

                return $this->driver->get($bits[0], $bits[1]) !== false;
            });
    }

    /**
     * Determine if any of the features are active.
     *
     * Returns true if at least one feature is active for each scope.
     *
     * @param  array<string> $features Feature names to check
     * @return bool          True if at least one feature is active for each scope
     */
    public function someAreActive(array $features): bool
    {
        $this->loadMissing($features);

        return Collection::make($this->scope())
            ->every(fn ($scope) => Collection::make($features)
                ->contains(fn (string $feature): bool => $this->driver->get($feature, $scope) !== false));
    }

    /**
     * Determine if the feature is inactive.
     *
     * @param  string $feature The feature name
     * @return bool   True if the feature is inactive for all scopes
     */
    public function inactive(string $feature): bool
    {
        return $this->allAreInactive([$feature]);
    }

    /**
     * Determine if all the features are inactive.
     *
     * Returns true only if ALL features are inactive (false) for ALL scopes.
     *
     * @param  array<string> $features Feature names to check
     * @return bool          True if all features are inactive for all scopes
     */
    public function allAreInactive(array $features): bool
    {
        $this->loadMissing($features);

        return Collection::make($features)
            ->crossJoin($this->scope())
            ->every(function (array $bits): bool {
                assert(is_string($bits[0]));

                return $this->driver->get($bits[0], $bits[1]) === false;
            });
    }

    /**
     * Determine if any of the features are inactive.
     *
     * Returns true if at least one feature is inactive for each scope.
     *
     * @param  array<string> $features Feature names to check
     * @return bool          True if at least one feature is inactive for each scope
     */
    public function someAreInactive(array $features): bool
    {
        $this->loadMissing($features);

        return Collection::make($this->scope())
            ->every(fn ($scope) => Collection::make($features)
                ->contains(fn (string $feature): bool => $this->driver->get($feature, $scope) === false));
    }

    /**
     * Apply the callback if the feature is active.
     *
     * @param  string       $feature      The feature name
     * @param  Closure      $whenActive   Callback to execute if active (receives value and $this)
     * @param  null|Closure $whenInactive Optional callback to execute if inactive
     * @return mixed        Result of the executed callback, or null if feature is inactive and no callback provided
     */
    public function when(string $feature, Closure $whenActive, ?Closure $whenInactive = null): mixed
    {
        if ($this->active($feature)) {
            return $whenActive($this->value($feature), $this);
        }

        if ($whenInactive instanceof Closure) {
            return $whenInactive($this);
        }

        return null;
    }

    /**
     * Apply the callback if the feature is inactive.
     *
     * @param  string       $feature      The feature name
     * @param  Closure      $whenInactive Callback to execute if inactive
     * @param  null|Closure $whenActive   Optional callback to execute if active
     * @return mixed        Result of the executed callback
     */
    public function unless(string $feature, Closure $whenInactive, ?Closure $whenActive = null): mixed
    {
        return $this->when($feature, $whenActive ?? fn (): null => null, $whenInactive);
    }

    /**
     * Activate the feature.
     *
     * Sets the feature to the specified value (defaults to true) for all scopes.
     *
     * @param array<string>|string $feature Feature name(s) to activate
     * @param mixed                $value   Value to set (defaults to true)
     */
    public function activate(string|array $feature, mixed $value = true): void
    {
        Collection::wrap($feature)
            ->crossJoin($this->scope())
            ->each(function (array $bits) use ($value): void {
                assert(is_string($bits[0]));
                $this->driver->set($bits[0], $bits[1], $value);
            });
    }

    /**
     * Deactivate the feature.
     *
     * Sets the feature to false for all scopes.
     *
     * @param array<string>|string $feature Feature name(s) to deactivate
     */
    public function deactivate(string|array $feature): void
    {
        Collection::wrap($feature)
            ->crossJoin($this->scope())
            ->each(function (array $bits): void {
                assert(is_string($bits[0]));
                $this->driver->set($bits[0], $bits[1], false);
            });
    }

    /**
     * Forget the flags value.
     *
     * Removes the feature value from storage for all scopes.
     *
     * @param array<string>|string $features Feature name(s) to forget
     */
    public function forget(string|array $features): void
    {
        Collection::wrap($features)
            ->crossJoin($this->scope())
            ->each(function (array $bits): void {
                assert(is_string($bits[0]));
                $this->driver->delete($bits[0], $bits[1]);
            });
    }

    /**
     * Activate all features in a group.
     *
     * @param string $name Group name
     */
    public function activateGroup(string $name): void
    {
        $features = $this->driver->getGroup($name);

        Collection::wrap($features)
            ->crossJoin($this->scope())
            ->each(function (array $bits): void {
                assert(is_string($bits[0]));
                $this->driver->set($bits[0], $bits[1], true);
            });
    }

    /**
     * Deactivate all features in a group.
     *
     * @param string $name Group name
     */
    public function deactivateGroup(string $name): void
    {
        $features = $this->driver->getGroup($name);

        Collection::wrap($features)
            ->crossJoin($this->scope())
            ->each(function (array $bits): void {
                assert(is_string($bits[0]));
                $this->driver->set($bits[0], $bits[1], false);
            });
    }

    /**
     * Check if all features in a group are active.
     *
     * Returns true if all features in the group are active for all scopes.
     * An empty group is considered "all active".
     *
     * @param  string $name Group name
     * @return bool   True if all features in group are active
     */
    public function activeInGroup(string $name): bool
    {
        $features = $this->driver->getGroup($name);

        if ($features === []) {
            return true; // Empty group is considered "all active"
        }

        $this->loadMissing($features);

        return Collection::make($features)
            ->crossJoin($this->scope())
            ->every(function (array $bits): bool {
                assert(is_string($bits[0]));

                return $this->driver->get($bits[0], $bits[1]) !== false;
            });
    }

    /**
     * Check if any features in a group are active.
     *
     * Returns true if at least one feature in the group is active for each scope.
     * An empty group returns false.
     *
     * @param  string $name Group name
     * @return bool   True if at least one feature in group is active
     */
    public function someActiveInGroup(string $name): bool
    {
        $features = $this->driver->getGroup($name);

        if ($features === []) {
            return false; // Empty group has no features to be active
        }

        $this->loadMissing($features);

        return Collection::make($this->scope())
            ->every(fn ($scope) => Collection::make($features)
                ->contains(fn (string $feature): bool => $this->driver->get($feature, $scope) !== false));
    }

    /**
     * Get the variant for a feature based on the current scope.
     *
     * Uses consistent hashing to assign variants based on scope and configured weights.
     * Once assigned, the variant is stored to ensure consistency.
     *
     * @param string $name Feature name
     *
     * @throws RuntimeException If multiple scopes are set
     *
     * @return null|string The assigned variant name, or null if no variants configured
     */
    public function variant(string $name): ?string
    {
        throw_if(count($this->scope()) > 1, RuntimeException::class, 'It is not possible to retrieve variants for multiple scopes.');

        $scope = $this->scope()[0];

        // Check if variant is already stored for this scope
        $storedVariant = $this->driver->get($name, $scope);

        if ($storedVariant !== false && is_string($storedVariant)) {
            return $storedVariant;
        }

        // Get variant weights
        $variants = $this->driver->getVariants($name);

        if ($variants === []) {
            return null;
        }

        // Calculate variant based on consistent hashing
        $variant = $this->calculateVariant($name, $scope, $variants);

        // Store the variant
        $this->driver->set($name, $scope, $variant);

        return $variant;
    }

    /**
     * Calculate which variant to assign based on consistent hashing.
     *
     * Uses CRC32 hashing to deterministically assign a variant based on the
     * feature name and scope. This ensures the same scope always gets the same
     * variant, distributed according to the configured weights.
     *
     * @param  string             $feature Feature name
     * @param  mixed              $scope   The scope to hash
     * @param  array<string, int> $weights Map of variant names to percentage weights (should sum to 100)
     * @return string             The assigned variant name
     */
    private function calculateVariant(string $feature, mixed $scope, array $weights): string
    {
        // Create a hash from feature name and scope
        $scopeString = Feature::serializeScope($scope);
        $hashInput = $feature.'|'.$scopeString;
        $hash = crc32($hashInput);

        // Normalize to 0-99 range
        $bucket = abs($hash) % 100;

        // Find which variant this bucket falls into
        $cumulative = 0;

        foreach ($weights as $variant => $weight) {
            $cumulative += $weight;

            if ($bucket < $cumulative) {
                return $variant;
            }
        }

        // Fallback to last variant (shouldn't reach here if weights sum to 100)
        $lastKey = array_key_last($weights);
        assert(is_string($lastKey));

        return $lastKey;
    }

    /**
     * The scope to pass to the driver.
     *
     * Returns the configured scopes, or [null] if no scope was set (global scope).
     *
     * @return array<mixed> Array of scope identifiers
     */
    private function scope(): array
    {
        return $this->scope ?: [null];
    }
}
