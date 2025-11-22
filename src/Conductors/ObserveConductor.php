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
use Cline\Toggl\PendingContextualFeatureInteraction;
use Closure;

/**
 * Conductor for observing and watching feature changes.
 *
 * Enables monitoring feature state changes and executing callbacks when features
 * are activated, deactivated, or changed. Returns an observer object that can
 * check for changes and trigger registered callbacks.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class ObserveConductor
{
    /**
     * Create a new observe conductor instance.
     *
     * @param FeatureManager    $manager      The feature manager instance for managing feature state
     *                                        across different contexts and handling observation operations
     * @param BackedEnum|string $feature      Feature to observe. Can be a string identifier or backed enum
     *                                        value for type-safe feature references
     * @param null|Closure      $onChange     Callback executed when feature state or value changes. Receives
     *                                        feature, old value, new value, and active state as parameters
     * @param null|Closure      $onActivate   Callback executed when feature transitions from inactive to active.
     *                                        Receives feature and new value as parameters
     * @param null|Closure      $onDeactivate Callback executed when feature transitions from active to inactive.
     *                                        Receives feature and old value as parameters
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum $feature,
        private ?Closure $onChange = null,
        private ?Closure $onActivate = null,
        private ?Closure $onDeactivate = null,
    ) {}

    /**
     * Register callback for any feature change.
     *
     * Returns a new conductor instance with the onChange callback configured.
     * This callback is triggered whenever the feature's state or value changes.
     *
     * @param  Closure $callback Callback to execute on change. Receives (feature, oldValue, newValue, isActive)
     *                           as parameters. Return value will be returned by observer's check() method.
     * @return self    new conductor instance with onChange callback configured
     */
    public function onChange(Closure $callback): self
    {
        return new self(
            $this->manager,
            $this->feature,
            $callback,
            $this->onActivate,
            $this->onDeactivate,
        );
    }

    /**
     * Register callback for feature activation.
     *
     * Returns a new conductor instance with the onActivate callback configured.
     * This callback is triggered only when the feature transitions from inactive to active.
     *
     * @param  Closure $callback Callback to execute on activation. Receives (feature, newValue)
     *                           as parameters. Return value will be returned by observer's check() method.
     * @return self    new conductor instance with onActivate callback configured
     */
    public function onActivate(Closure $callback): self
    {
        return new self(
            $this->manager,
            $this->feature,
            $this->onChange,
            $callback,
            $this->onDeactivate,
        );
    }

    /**
     * Register callback for feature deactivation.
     *
     * Returns a new conductor instance with the onDeactivate callback configured.
     * This callback is triggered only when the feature transitions from active to inactive.
     *
     * @param  Closure $callback Callback to execute on deactivation. Receives (feature, oldValue)
     *                           as parameters. Return value will be returned by observer's check() method.
     * @return self    new conductor instance with onDeactivate callback configured
     */
    public function onDeactivate(Closure $callback): self
    {
        return new self(
            $this->manager,
            $this->feature,
            $this->onChange,
            $this->onActivate,
            $callback,
        );
    }

    /**
     * Start watching the feature for a specific context (terminal method).
     *
     * Creates and returns an observer object that tracks the feature's state and can
     * detect changes. The observer provides check(), isActive(), and value() methods
     * to monitor the feature.
     *
     * @param  mixed $context Context to watch. Can be any context type supported by the
     *                        feature manager (user, team, organization, etc.)
     * @return mixed Observer object with check(), isActive(), and value() methods
     */
    public function for(mixed $context): mixed
    {
        $driver = $this->manager->for($context);
        $wasActive = $driver->active($this->feature);
        $oldValue = $driver->value($this->feature);

        // Return observer that can check for changes
        return new class($this->feature, $wasActive, $oldValue, $this->onChange, $this->onActivate, $this->onDeactivate, $driver)
        {
            /**
             * Create a new feature observer instance.
             *
             * @param BackedEnum|string                   $feature      Feature being observed
             * @param bool                                $wasActive    Initial active state of the feature
             * @param mixed                               $oldValue     Initial value of the feature
             * @param null|Closure                        $onChange     Callback for any change
             * @param null|Closure                        $onActivate   Callback for activation
             * @param null|Closure                        $onDeactivate Callback for deactivation
             * @param PendingContextualFeatureInteraction $driver       Context-aware driver for checking feature state
             */
            public function __construct(
                private readonly string|BackedEnum $feature,
                private bool $wasActive,
                private mixed $oldValue,
                private readonly ?Closure $onChange,
                private readonly ?Closure $onActivate,
                private readonly ?Closure $onDeactivate,
                private readonly PendingContextualFeatureInteraction $driver,
            ) {}

            /**
             * Check current state and trigger callbacks if changed.
             *
             * Compares current feature state with previous state and triggers appropriate
             * callbacks. Updates internal state tracking after callback execution. Only
             * one callback is triggered per check (priority: onActivate > onDeactivate > onChange).
             *
             * @return mixed result from callback, or null if no change or no callback configured
             */
            public function check(): mixed
            {
                $isActive = $this->driver->active($this->feature);
                $newValue = $this->driver->value($this->feature);

                $stateChanged = $this->wasActive !== $isActive;
                $valueChanged = $this->oldValue !== $newValue;

                // Feature was activated
                if (!$this->wasActive && $isActive && $this->onActivate instanceof Closure) {
                    $result = ($this->onActivate)($this->feature, $newValue);
                    $this->wasActive = $isActive;
                    $this->oldValue = $newValue;

                    return $result;
                }

                // Feature was deactivated
                if ($this->wasActive && !$isActive && $this->onDeactivate instanceof Closure) {
                    $result = ($this->onDeactivate)($this->feature, $this->oldValue);
                    $this->wasActive = $isActive;
                    $this->oldValue = $newValue;

                    return $result;
                }

                // Feature state or value changed
                if (($stateChanged || $valueChanged) && $this->onChange instanceof Closure) {
                    $result = ($this->onChange)($this->feature, $this->oldValue, $newValue, $isActive);
                    $this->wasActive = $isActive;
                    $this->oldValue = $newValue;

                    return $result;
                }

                return null;
            }

            /**
             * Get current feature state.
             *
             * Returns the current active/inactive state of the feature without
             * triggering any callbacks. Useful for polling feature state.
             *
             * @return bool true if feature is currently active, false otherwise
             */
            public function isActive(): bool
            {
                return $this->driver->active($this->feature);
            }

            /**
             * Get current feature value.
             *
             * Returns the current value of the feature without triggering any callbacks.
             * Useful for inspecting feature configuration or metadata.
             *
             * @return mixed current feature value
             */
            public function value(): mixed
            {
                return $this->driver->value($this->feature);
            }
        };
    }

    /**
     * Get the feature being observed.
     *
     * Returns the feature identifier configured for observation.
     *
     * @return BackedEnum|string the feature identifier
     */
    public function feature(): string|BackedEnum
    {
        return $this->feature;
    }

    /**
     * Get the onChange callback.
     *
     * Returns the callback configured to execute when the feature changes,
     * or null if no onChange callback has been registered.
     *
     * @return null|Closure the onChange callback, or null if not configured
     */
    public function onChangeCallback(): ?Closure
    {
        return $this->onChange;
    }

    /**
     * Get the onActivate callback.
     *
     * Returns the callback configured to execute when the feature activates,
     * or null if no onActivate callback has been registered.
     *
     * @return null|Closure the onActivate callback, or null if not configured
     */
    public function onActivateCallback(): ?Closure
    {
        return $this->onActivate;
    }

    /**
     * Get the onDeactivate callback.
     *
     * Returns the callback configured to execute when the feature deactivates,
     * or null if no onDeactivate callback has been registered.
     *
     * @return null|Closure the onDeactivate callback, or null if not configured
     */
    public function onDeactivateCallback(): ?Closure
    {
        return $this->onDeactivate;
    }
}
