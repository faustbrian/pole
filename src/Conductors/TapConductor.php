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
 * Tap conductor for injecting side-effect callbacks into the activation chain.
 *
 * Enables inspection, logging, or custom logic during feature activation without breaking
 * the fluent interface chain. The tap pattern allows callbacks to be executed at any point
 * while the chain continues, making it ideal for debugging, audit logging, notifications,
 * or conditional logic that doesn't affect the activation flow.
 *
 * ```php
 * Toggl::activate('premium-subscription')
 *     ->tap(fn($conductor) => Log::info('Activating premium', [
 *         'features' => $conductor->features()
 *     ]))
 *     ->tap(fn() => event(new PremiumActivated()))
 *     ->for($user);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class TapConductor
{
    /**
     * Create a new tap conductor instance.
     *
     * Initializes an immutable conductor with features to activate and optional value.
     *
     * @param FeatureManager                             $manager  The feature manager instance for executing activations
     * @param array<BackedEnum|string>|BackedEnum|string $features Single feature or array of features to activate
     * @param mixed                                      $value    Optional value to associate with the feature(s).
     *                                                             Defaults to true for simple boolean features.
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum|array $features,
        private mixed $value = true,
    ) {}

    /**
     * Execute a callback for side effects without breaking the chain.
     *
     * The callback receives this conductor instance as its parameter, providing access
     * to feature information via features() and value() methods. The callback's return
     * value is ignored, and the conductor is returned for continued chaining.
     *
     * @param  Closure $callback Closure receiving the conductor instance as parameter.
     *                           Use for logging, events, or inspection without affecting
     *                           the activation flow.
     * @return self    Returns this conductor unchanged for continued chaining
     */
    public function tap(Closure $callback): self
    {
        $callback($this);

        return $this;
    }

    /**
     * Apply feature activation to the specified context(s).
     *
     * Terminal method that activates all configured features for each context with the
     * configured value. Supports both single contexts and arrays of contexts.
     *
     * @param mixed $context Single context entity or array of contexts to activate features for
     */
    public function for(mixed $context): void
    {
        $contexts = is_array($context) ? $context : [$context];

        foreach ($contexts as $s) {
            if (is_array($this->features)) {
                foreach ($this->features as $feature) {
                    /** @var BackedEnum|string $feature */
                    $this->manager->for($s)->activate($feature, $this->value);
                }
            } else {
                $this->manager->for($s)->activate($this->features, $this->value);
            }
        }
    }

    /**
     * Retrieve the feature(s) being activated.
     *
     * @return array<BackedEnum|string>|BackedEnum|string Single feature or array of features
     */
    public function features(): string|BackedEnum|array
    {
        return $this->features;
    }

    /**
     * Retrieve the value being associated with the feature(s).
     *
     * @return mixed The activation value (boolean, array, object, etc.).
     */
    public function value(): mixed
    {
        return $this->value;
    }
}
