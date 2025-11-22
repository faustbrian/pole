<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Drivers;

use Cline\Toggl\Contracts\CanListStoredFeatures;
use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Contracts\HasFlushableCache;
use Cline\Toggl\Events\UnknownFeatureResolved;
use Cline\Toggl\Exceptions\Configuration\InvalidTtlConfigurationException;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Config;
use stdClass;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function assert;
use function config;
use function in_array;
use function is_callable;
use function is_int;
use function is_numeric;
use function sprintf;

/**
 * Laravel Cache-backed feature flag driver.
 *
 * Stores feature flags using Laravel's Cache facade, providing persistence with automatic
 * expiration support. This driver leverages cache stores like Redis, Memcached, or file-based
 * caches for distributed feature flag management with TTL support.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CacheDriver implements CanListStoredFeatures, Driver, HasFlushableCache
{
    /**
     * The sentinel value for unknown features.
     *
     * Used to distinguish between features that resolve to false/null
     * and features that haven't been defined at all.
     */
    private readonly stdClass $unknownFeatureValue;

    /**
     * Create a new driver instance.
     *
     * @param CacheRepository                                  $cache                 Laravel cache repository instance for storing and retrieving feature values
     *                                                                                from the configured cache backend (Redis, Memcached, file, etc.), providing
     *                                                                                persistence with automatic expiration support based on configured TTL
     * @param Dispatcher                                       $events                Laravel event dispatcher instance used to fire UnknownFeatureResolved
     *                                                                                events when undefined features are accessed, enabling monitoring and logging
     *                                                                                of feature flag usage patterns and detection of missing feature definitions
     * @param string                                           $name                  The driver name used to retrieve configuration values from the toggl.stores
     *                                                                                config array, including cache prefix, TTL, and connection settings
     * @param array<string, (callable(mixed $context): mixed)> $featureStateResolvers Map of feature names to their resolver callbacks or static values.
     *                                                                                Resolvers accept a context parameter and return the feature's value for that context.
     *                                                                                Static values are automatically wrapped in closures during definition,
     *                                                                                providing a consistent callable interface for all feature resolution
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly Dispatcher $events,
        private readonly string $name,
        private array $featureStateResolvers,
    ) {
        $this->unknownFeatureValue = new stdClass();
    }

    /**
     * Define an initial feature flag state resolver.
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
     * Retrieve the names of all stored features.
     *
     * Returns features that have been cached. Note: This requires maintaining
     * a separate index in cache due to the nature of cache stores.
     *
     * @return array<string> The list of stored feature names
     */
    public function stored(): array
    {
        /** @var array<string> */
        return $this->cache->get($this->cacheKey('__index'), []);
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
     * Checks the cache first, then resolves using the feature's resolver if needed.
     *
     * @param  string       $feature The feature name
     * @param  TogglContext $context The context to check
     * @return mixed        The feature's value for the given context
     */
    public function get(string $feature, TogglContext $context): mixed
    {
        $contextKey = $context->serialize();
        $cacheKey = $this->cacheKey($feature, $contextKey);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $value = $this->resolveValue($feature, $context);

        if ($value === $this->unknownFeatureValue) {
            return false;
        }

        $this->set($feature, $context, $value);

        return $value;
    }

    /**
     * Set a feature flag's value.
     *
     * @param string       $feature The feature name
     * @param TogglContext $context The context to update
     * @param mixed        $value   The new value to set
     */
    public function set(string $feature, TogglContext $context, mixed $value): void
    {
        $contextKey = $context->serialize();
        $cacheKey = $this->cacheKey($feature, $contextKey);
        $ttl = $this->getTtl();

        if ($ttl !== null) {
            $this->cache->put($cacheKey, $value, $ttl);
        } else {
            $this->cache->forever($cacheKey, $value);
        }

        $this->addToIndex($feature);
        $this->trackContextKey($feature, $contextKey);
    }

    /**
     * Set a feature flag's value for all contexts.
     *
     * Clears all cached contextual values and updates the resolver.
     *
     * @param string $feature The feature name
     * @param mixed  $value   The new value to set
     */
    public function setForAllContexts(string $feature, mixed $value): void
    {
        $this->featureStateResolvers[$feature] = fn (): mixed => $value;

        $this->clearContextCache($feature);
    }

    /**
     * Delete a feature flag's value.
     *
     * @param string       $feature The feature name
     * @param TogglContext $context The context to delete
     */
    public function delete(string $feature, TogglContext $context): void
    {
        $contextKey = $context->serialize();
        $cacheKey = $this->cacheKey($feature, $contextKey);

        $this->cache->forget($cacheKey);
    }

    /**
     * Purge the given features from storage.
     *
     * @param null|array<string> $features The feature names to purge, or null to purge all
     */
    public function purge(?array $features): void
    {
        if ($features === null) {
            $this->cache->getStore()->flush();
        } else {
            foreach ($features as $feature) {
                $this->clearContextCache($feature);
                $this->removeFromIndex($feature);
            }
        }
    }

    /**
     * Flush the cache.
     *
     * Clears all feature flag entries from the cache store.
     */
    public function flushCache(): void
    {
        $this->cache->getStore()->flush();
    }

    /**
     * Get all contextual keys for a feature.
     *
     * @param  string        $feature The feature name
     * @return array<string> The list of contextual keys
     */
    private function getContextKeys(string $feature): array
    {
        /** @var array<string> */
        return $this->cache->get($this->cacheKey($feature.'.__contexts'), []);
    }

    /**
     * Track a contextual key for a feature.
     *
     * @param string $feature    The feature name
     * @param string $contextKey The context key
     */
    private function trackContextKey(string $feature, string $contextKey): void
    {
        $keys = $this->getContextKeys($feature);

        if (!in_array($contextKey, $keys, true)) {
            $keys[] = $contextKey;
            $this->cache->forever($this->cacheKey($feature.'.__contexts'), $keys);
        }
    }

    /**
     * Clear all contextual cache entries for a feature.
     *
     * @param string $feature The feature name
     */
    private function clearContextCache(string $feature): void
    {
        $contextdKeys = $this->getContextKeys($feature);

        foreach ($contextdKeys as $contextKey) {
            $this->cache->forget($this->cacheKey($feature, $contextKey));
        }

        $this->cache->forget($this->cacheKey($feature.'.__contexts'));
    }

    /**
     * Determine the initial value for a given feature and context.
     *
     * Calls the feature's resolver or dispatches an UnknownFeatureResolved event.
     *
     * @param  string       $feature The feature name
     * @param  TogglContext $context The context to evaluate
     * @return mixed        The resolved value or the unknown feature sentinel
     */
    private function resolveValue(string $feature, TogglContext $context): mixed
    {
        if (!array_key_exists($feature, $this->featureStateResolvers)) {
            if (Config::get('toggl.events.enabled', true)) {
                $this->events->dispatch(
                    new UnknownFeatureResolved($feature, $context),
                );
            }

            return $this->unknownFeatureValue;
        }

        return $this->featureStateResolvers[$feature]($context);
    }

    /**
     * Generate a cache key for the feature and context.
     *
     * @param  string      $feature    The feature name
     * @param  null|string $contextKey The serialized context key
     * @return string      The cache key
     */
    private function cacheKey(string $feature, ?string $contextKey = null): string
    {
        /** @var string $prefix */
        $prefix = Config::get(sprintf('toggl.stores.%s.prefix', $this->name), 'features');

        if ($contextKey === null) {
            return sprintf('%s:%s', $prefix, $feature);
        }

        return sprintf('%s:%s:%s', $prefix, $feature, $contextKey);
    }

    /**
     * Get the TTL for cache entries.
     *
     * Retrieves the configured time-to-live value from the driver configuration.
     * Accepts integer or numeric string values. Returns null for permanent storage.
     *
     * @throws InvalidTtlConfigurationException If TTL is configured but not numeric
     *
     * @return null|int The TTL in seconds, or null for forever
     */
    private function getTtl(): ?int
    {
        $ttl = Config::get(sprintf('toggl.stores.%s.ttl', $this->name));

        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return $ttl;
        }

        // If not null and not int, it should be numeric (string, float)
        if (!is_numeric($ttl)) {
            throw InvalidTtlConfigurationException::invalidType($ttl);
        }

        /** @var float|numeric-string $ttl */
        return (int) $ttl;
    }

    /**
     * Add a feature to the stored features index.
     *
     * @param string $feature The feature name
     */
    private function addToIndex(string $feature): void
    {
        $index = $this->stored();

        if (!in_array($feature, $index, true)) {
            $index[] = $feature;
            $this->cache->forever($this->cacheKey('__index'), $index);
        }
    }

    /**
     * Remove a feature from the stored features index.
     *
     * @param string $feature The feature name
     */
    private function removeFromIndex(string $feature): void
    {
        $index = $this->stored();
        $filtered = array_filter($index, fn (string $item): bool => $item !== $feature);

        $this->cache->forever($this->cacheKey('__index'), array_values($filtered));
    }
}
