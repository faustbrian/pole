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

use function is_string;

/**
 * Conductor for feature testing and fakes.
 *
 * Provides test double capabilities for feature flags during testing, enabling
 * isolation and controlled feature state management in test environments. This
 * conductor allows tests to override feature flag behavior without modifying
 * persistent storage or affecting production code.
 *
 * Supports both single feature fakes and batch operations for testing scenarios
 * involving multiple feature flags. Features can be faked globally or contextual to
 * specific test contexts (users, teams, etc.).
 *
 * ```php
 * Toggl::fake('new-ui')->fake(true)->globally();
 * Toggl::fake('new-ui')->fake('variant-b')->for($user);
 * Toggl::fakeMany(['feature-a' => true, 'feature-b' => false])->globally();
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class TestingConductor
{
    /**
     * Create a new testing conductor instance.
     *
     * @param FeatureManager                       $manager   The feature manager instance used to apply fakes
     *                                                        to the underlying driver system
     * @param null|BackedEnum|string               $feature   Feature identifier to fake (null when using batch fakeMany)
     * @param mixed                                $fakeValue The value to return for the faked feature (boolean, string,
     *                                                        or any type). Null values are treated as deactivation
     * @param null|array<BackedEnum|string, mixed> $fakeMany  Map of feature names to fake values for batch operations.
     *                                                        When set, individual feature/fakeValue are ignored
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum|null $feature = null,
        private mixed $fakeValue = null,
        private ?array $fakeMany = null,
    ) {}

    /**
     * Set the fake value for the feature.
     *
     * @param  mixed $value The value to return when the feature is checked (true/false for
     *                      boolean flags, strings for variants, or any type for value-based features)
     * @return self  New conductor instance with the fake value set
     */
    public function fake(mixed $value): self
    {
        return new self($this->manager, $this->feature, $value, $this->fakeMany);
    }

    /**
     * Fake multiple features at once.
     *
     * Enables batch faking of features for tests that need to control multiple
     * feature states simultaneously. More efficient than chaining multiple fake()
     * calls when testing multi-feature scenarios.
     *
     * @param  array<BackedEnum|string, mixed> $features Map of feature identifiers to their fake values
     * @return self                            New conductor instance configured for batch fake operations
     */
    public function fakeMany(array $features): self
    {
        return new self($this->manager, $this->feature, $this->fakeValue, $features);
    }

    /**
     * Apply fake to specific context.
     *
     * Terminal operation that applies the fake configuration to a given context
     * (user, team, etc.). The fake persists for the context using the
     * underlying driver's activation/deactivation mechanism.
     *
     * - Boolean true: activates the feature for the context
     * - Boolean false/null: deactivates the feature for the context
     * - Other values: activates with the value stored (for variants/value flags)
     *
     * @param mixed $context The context to apply the fake to (user, team, organization, etc.)
     */
    public function for(mixed $context): void
    {
        $contextdDriver = $this->manager->for($context);

        if ($this->fakeMany !== null) {
            // Batch fake multiple features
            foreach ($this->fakeMany as $feature => $value) {
                /**
                 * Type guard to ensure $feature is BackedEnum|string
                 *
                 * @phpstan-ignore-next-line instanceof.alwaysFalse
                 */
                if (!is_string($feature) && !$feature instanceof BackedEnum) {
                    continue; // @codeCoverageIgnore
                }

                if ($value === true) {
                    $contextdDriver->activate($feature);
                } elseif ($value === false) {
                    $contextdDriver->deactivate($feature);
                } else {
                    // Store value by activating with the value
                    $contextdDriver->activate($feature, $value);
                }
            }
        } elseif ($this->feature !== null) {
            // Single feature fake
            if ($this->fakeValue === true) {
                $contextdDriver->activate($this->feature);
            } elseif ($this->fakeValue === false) {
                $contextdDriver->deactivate($this->feature);
            } elseif ($this->fakeValue === null) {
                // Null is inactive
                $contextdDriver->deactivate($this->feature);
            } else {
                // Store value by activating with the value
                $contextdDriver->activate($this->feature, $this->fakeValue);
            }
        }
    }

    /**
     * Apply fake globally (no context).
     *
     * Terminal operation that applies the fake configuration globally without
     * context restrictions. The fake affects all feature checks using the driver's
     * define() method to register the fake values as feature resolvers.
     *
     * This is useful for test setups where features should behave consistently
     * across all contexts without needing to fake for each context individually.
     */
    public function globally(): void
    {
        $driver = $this->manager->driver();

        if ($this->fakeMany !== null) {
            // Batch fake multiple features
            foreach ($this->fakeMany as $feature => $value) {
                /**
                 * Type guard to ensure $feature is BackedEnum|string
                 *
                 * @phpstan-ignore-next-line instanceof.alwaysFalse
                 */
                if (!is_string($feature) && !$feature instanceof BackedEnum) {
                    continue; // @codeCoverageIgnore
                }

                $driver->define($feature, fn () => $value);
            }
        } elseif ($this->feature !== null) {
            // Single feature fake
            $driver->define($this->feature, fn (): mixed => $this->fakeValue);
        }
    }

    /**
     * Get the feature name.
     *
     * @return null|BackedEnum|string The feature identifier being faked, or null for batch operations
     */
    public function feature(): string|BackedEnum|null
    {
        return $this->feature;
    }

    /**
     * Get the fake value.
     *
     * @return mixed The value configured to be returned for the faked feature
     */
    public function fakeValue(): mixed
    {
        return $this->fakeValue;
    }

    /**
     * Get the fake many array.
     *
     * @return null|array<BackedEnum|string, mixed> Map of features to fake values for batch operations,
     *                                              or null if not using batch mode
     */
    public function fakeManyArray(): ?array
    {
        return $this->fakeMany;
    }
}
