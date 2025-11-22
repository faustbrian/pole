<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Drivers;

use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Events\UnknownFeatureResolved;
use Cline\Toggl\Exceptions\CannotDeleteFeatureValueException;
use Cline\Toggl\Exceptions\CannotPurgeFeatureValuesException;
use Cline\Toggl\Exceptions\CannotSetFeatureValueException;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Config;

use function array_keys;
use function assert;
use function is_callable;
use function is_string;
use function sprintf;

/**
 * Laravel Gate-based feature flag driver.
 *
 * Delegates feature flag evaluation to Laravel's authorization gate system.
 * This driver always returns a boolean result based on gate authorization,
 * making it suitable for user-based or permission-based feature access control.
 * Unlike other drivers, this one does not support persistence and always
 * evaluates features in real-time through the configured gate.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class GateDriver implements Driver
{
    /**
     * Create a new driver instance.
     *
     * @param Gate                                             $gate                  Laravel gate instance for authorization checks that evaluates feature
     *                                                                                access through the configured gate policy, enabling permission-based
     *                                                                                feature control integrated with Laravel's authorization system
     * @param Dispatcher                                       $events                Laravel event dispatcher instance used to fire UnknownFeatureResolved
     *                                                                                events when undefined features are accessed or no gate is defined,
     *                                                                                enabling monitoring and logging of feature flag usage patterns
     * @param string                                           $name                  The driver name used to retrieve configuration from toggl.stores array,
     *                                                                                including gate name and guard settings for authorization evaluation
     * @param array<string, (callable(mixed $context): mixed)> $featureStateResolvers Map of feature names to their resolver callbacks or static values.
     *                                                                                Resolvers accept a context parameter and return the feature's value for that context.
     *                                                                                Static values are automatically wrapped in closures during definition,
     *                                                                                providing a consistent callable interface for all feature resolution
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly Dispatcher $events,
        private readonly string $name,
        private array $featureStateResolvers,
    ) {}

    /**
     * Define an initial feature flag state resolver.
     *
     * Note: For the gate driver, resolvers are used as fallback when no gate is defined.
     * In most cases, feature evaluation is delegated to Laravel's Gate system rather than
     * using these resolvers.
     *
     * @param  string                                  $feature  The feature name
     * @param  (callable(mixed $context): mixed)|mixed $resolver The resolver callback or static value
     * @return mixed                                   Always returns null
     */
    public function define(string $feature, mixed $resolver = null): mixed
    {
        $this->featureStateResolvers[$feature] = is_callable($resolver)
            ? $resolver
            : fn (): mixed => $resolver;

        return null;
    }

    /**
     * Retrieve the names of all defined features.
     *
     * @return array<string> The list of defined feature names
     */
    public function defined(): array
    {
        return array_keys($this->featureStateResolvers);
    }

    /**
     * Get multiple feature flag values.
     *
     * @param  array<string, array<int, mixed>> $features Map of feature names to their contexts
     * @return array<string, array<int, mixed>> Map of feature names to their resolved values
     */
    public function getAll(array $features): array
    {
        $results = [];

        foreach ($features as $feature => $contexts) {
            $results[$feature] = [];

            foreach ($contexts as $context) {
                assert($context instanceof TogglContext);
                $results[$feature][] = $this->get($feature, $context);
            }
        }

        return $results;
    }

    /**
     * Retrieve a feature flag's value.
     *
     * Evaluates the feature through the configured Laravel gate. Uses the original
     * source model from the context (if available) for gate authorization checks.
     * Dispatches UnknownFeatureResolved event if no gate is defined. Always returns
     * a boolean value based on gate authorization result.
     *
     * @param  string       $feature The feature name
     * @param  TogglContext $context The context to check
     * @return bool         The feature's value for the given context
     */
    public function get(string $feature, TogglContext $context): mixed
    {
        $gateName = $this->getGateName();

        if ($this->gate->has($gateName)) {
            // Use the original source model for Gate authorization when available
            $user = $context->source ?? $context;

            return $this->gate->forUser($user)->allows($gateName, $feature);
        }

        if (Config::get('toggl.events.enabled', true)) {
            $this->events->dispatch(
                new UnknownFeatureResolved($feature, $context),
            );
        }

        return false;
    }

    /**
     * Set a feature flag's value.
     *
     * Not supported for gate driver as gates are evaluated in real-time.
     *
     * @param string       $feature The feature name
     * @param TogglContext $context The context to update
     * @param mixed        $value   The new value to set
     *
     * @throws CannotSetFeatureValueException Always throws as operation is not supported
     */
    public function set(string $feature, TogglContext $context, mixed $value): void
    {
        throw CannotSetFeatureValueException::forGateDriver();
    }

    /**
     * Set a feature flag's value for all contexts.
     *
     * Not supported for gate driver as gates are evaluated in real-time.
     *
     * @param string $feature The feature name
     * @param mixed  $value   The new value to set
     *
     * @throws CannotSetFeatureValueException Always throws as operation is not supported
     */
    public function setForAllContexts(string $feature, mixed $value): void
    {
        throw CannotSetFeatureValueException::forGateDriver();
    }

    /**
     * Delete a feature flag's value.
     *
     * Not supported for gate driver as gates are evaluated in real-time.
     *
     * @param string       $feature The feature name
     * @param TogglContext $context The context to delete
     *
     * @throws CannotDeleteFeatureValueException Always throws as operation is not supported
     */
    public function delete(string $feature, TogglContext $context): void
    {
        throw CannotDeleteFeatureValueException::forGateDriver();
    }

    /**
     * Purge the given features from storage.
     *
     * Not supported for gate driver as gates are evaluated in real-time.
     *
     * @param null|array<string> $features The feature names to purge, or null to purge all
     *
     * @throws CannotPurgeFeatureValuesException Always throws as operation is not supported
     */
    public function purge(?array $features): void
    {
        throw CannotPurgeFeatureValuesException::forGateDriver();
    }

    /**
     * Get the gate name from configuration.
     *
     * Retrieves the gate name from the driver configuration, defaulting to 'feature'
     * if not specified. This gate is used for all feature authorization checks.
     *
     * @return string The gate name to use for feature checks
     */
    private function getGateName(): string
    {
        $gateName = Config::get(sprintf('toggl.stores.%s.gate', $this->name), 'feature');

        return is_string($gateName) ? $gateName : 'feature';
    }
}
