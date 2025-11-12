<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole;

use Cline\Pole\Contracts\Driver;
use Cline\Pole\Contracts\FeatureScopeSerializeable;
use Cline\Pole\Drivers\ArrayDriver;
use Cline\Pole\Drivers\DatabaseDriver;
use Cline\Pole\Drivers\Decorator;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;

use function array_key_exists;
use function assert;
use function is_int;
use function is_numeric;
use function is_string;
use function method_exists;
use function sprintf;
use function throw_if;
use function ucfirst;

/**
 * Central manager for feature flag stores and driver instances.
 *
 * This class manages multiple feature flag storage drivers, handles scope serialization,
 * and provides a unified interface for working with feature flags across the application.
 *
 * @mixin Decorator
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class FeatureManager
{
    /**
     * The array of resolved feature flag stores.
     *
     * @var array<string, Decorator>
     */
    private array $stores = [];

    /**
     * The registered custom driver creators.
     *
     * @var array<string, Closure>
     */
    private array $customCreators = [];

    /**
     * The default scope resolver.
     *
     * @var null|(callable(string): mixed)
     */
    private $defaultScopeResolver;

    /**
     * Indicates if the Eloquent "morph map" should be used when serializing.
     */
    private bool $useMorphMap = false;

    /**
     * Create a new feature manager instance.
     *
     * @param Container $container The application container
     */
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Dynamically call the default store instance.
     *
     * @param  string            $method     The method name to call
     * @param  array<int, mixed> $parameters The method parameters
     * @return mixed             The result of the method call
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->store()->{$method}(...$parameters);
    }

    /**
     * Get a feature flag store instance.
     *
     * @param null|string $store The store name, or null to use the default
     *
     * @throws InvalidArgumentException If the store is not defined
     *
     * @return Decorator The store decorator instance
     */
    public function store(?string $store = null): Decorator
    {
        return $this->driver($store);
    }

    /**
     * Get a feature flag store instance by name.
     *
     * @param null|string $name The driver name, or null to use the default
     *
     * @throws InvalidArgumentException If the driver is not defined or supported
     *
     * @return Decorator The driver decorator instance
     */
    public function driver(?string $name = null): Decorator
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->stores[$name] = $this->get($name);
    }

    /**
     * Create an instance of the array driver.
     */
    public function createArrayDriver(): ArrayDriver
    {
        /** @var Dispatcher */
        $events = $this->container->make(Dispatcher::class);

        return new ArrayDriver($events, []);
    }

    /**
     * Create an instance of the database driver.
     *
     * @param array<string, mixed> $config The driver configuration
     * @param string               $name   The driver name
     */
    public function createDatabaseDriver(array $config, string $name): DatabaseDriver
    {
        /** @var DatabaseManager */
        $db = $this->container->make(ConnectionResolverInterface::class);

        /** @var Dispatcher */
        $events = $this->container->make(Dispatcher::class);

        /** @var Repository */
        $configRepository = $this->container->make(Repository::class);

        return new DatabaseDriver($db, $events, $configRepository, $name, []);
    }

    /**
     * Serialize the given scope for storage.
     *
     * Converts various scope types (models, strings, numbers, null) into a string
     * representation that can be stored and compared consistently.
     *
     * @param mixed $scope The scope to serialize
     *
     * @throws RuntimeException If the scope cannot be serialized
     *
     * @return string The serialized scope identifier
     */
    public function serializeScope(mixed $scope): string
    {
        if ($scope instanceof Model) {
            $key = $scope->getKey();
            assert(is_string($key) || is_int($key));
            $keyString = (string) $key;

            return $this->useMorphMap
                ? $scope->getMorphClass().'|'.$keyString
                : $scope::class.'|'.$keyString;
        }

        return match (true) {
            $scope instanceof FeatureScopeSerializeable => $scope->featureScopeSerialize(),
            $scope === null => '__laravel_null',
            is_string($scope) => $scope,
            is_numeric($scope) => (string) $scope,
            default => throw new RuntimeException('Unable to serialize the feature scope to a string. You should implement the FeatureScopeSerializeable contract.'),
        };
    }

    /**
     * Specify that the Eloquent morph map should be used when serializing.
     *
     * @param bool $value Whether to use the morph map
     */
    public function useMorphMap(bool $value = true): static
    {
        $this->useMorphMap = $value;

        return $this;
    }

    /**
     * Flush the driver caches.
     *
     * Clears cached feature flag values across all registered drivers.
     */
    public function flushCache(): void
    {
        foreach ($this->stores as $driver) {
            $driver->flushCache();
        }
    }

    /**
     * Set the default scope resolver.
     *
     * @param callable(string): mixed $resolver The resolver callback
     */
    public function resolveScopeUsing(callable $resolver): void
    {
        $this->defaultScopeResolver = $resolver;
    }

    /**
     * Get the default store name.
     *
     * @return string The default driver name
     */
    public function getDefaultDriver(): string
    {
        /** @var Repository */
        $config = $this->container->make(Repository::class);

        /** @var string */
        return $config->get('pole.default', 'array');
    }

    /**
     * Set the default store name.
     *
     * @param string $name The default driver name
     */
    public function setDefaultDriver(string $name): void
    {
        /** @var Repository */
        $config = $this->container->make(Repository::class);
        $config->set('pole.default', $name);
    }

    /**
     * Unset the given store instances.
     *
     * @param null|array<int, string>|string $name The driver name(s) to forget, or null for default
     */
    public function forgetDriver(array|string|null $name = null): static
    {
        $name ??= $this->getDefaultDriver();

        foreach ((array) $name as $storeName) {
            if (array_key_exists($storeName, $this->stores)) {
                unset($this->stores[$storeName]);
            }
        }

        return $this;
    }

    /**
     * Forget all of the resolved store instances.
     */
    public function forgetDrivers(): static
    {
        $this->stores = [];

        return $this;
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param string  $driver   The driver name
     * @param Closure $callback The creator callback
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Set the container instance used by the manager.
     *
     * @param Container $container The container instance
     */
    public function setContainer(Container $container): static
    {
        foreach ($this->stores as $store) {
            $store->setContainer($container);
        }

        return $this;
    }

    /**
     * Attempt to get the store from the local cache.
     *
     * @param  string    $name The store name
     * @return Decorator The store decorator instance
     */
    private function get(string $name): Decorator
    {
        return $this->stores[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given store.
     *
     * Creates a new driver instance based on configuration and wraps it in a decorator.
     *
     * @param string $name The store name
     *
     * @throws InvalidArgumentException If the store is not defined or the driver is not supported
     *
     * @return Decorator The resolved decorator instance
     */
    private function resolve(string $name): Decorator
    {
        $config = $this->getConfig($name);

        throw_if($config === null, InvalidArgumentException::class, sprintf('Feature flag store [%s] is not defined.', $name));

        assert(is_string($config['driver']));

        if (array_key_exists($config['driver'], $this->customCreators)) {
            $driver = $this->callCustomCreator($config);
        } else {
            $driverMethod = 'create'.ucfirst($config['driver']).'Driver';

            if (method_exists($this, $driverMethod)) {
                $driver = $this->{$driverMethod}($config, $name);
            } else {
                throw new InvalidArgumentException(sprintf('Driver [%s] is not supported.', $config['driver']));
            }
        }

        assert($driver instanceof Driver);

        return new Decorator(
            $name,
            $driver,
            $this->defaultScopeResolver($name),
            $this->container,
        );
    }

    /**
     * Call a custom driver creator.
     *
     * @param  array<string, mixed> $config The driver configuration
     * @return mixed                The created driver instance
     */
    private function callCustomCreator(array $config): mixed
    {
        assert(is_string($config['driver']));

        return $this->customCreators[$config['driver']]($this->container, $config);
    }

    /**
     * The default scope resolver.
     *
     * Returns a closure that resolves the current scope, either using the custom
     * resolver or falling back to the authenticated user.
     *
     * @param  string  $driver The driver name
     * @return Closure The scope resolver closure
     */
    private function defaultScopeResolver(string $driver): Closure
    {
        return function () use ($driver) {
            if ($this->defaultScopeResolver !== null) {
                return ($this->defaultScopeResolver)($driver);
            }

            /** @var Factory */
            $auth = $this->container->make(Factory::class);

            return $auth->guard()->user();
        };
    }

    /**
     * Get the feature flag store configuration.
     *
     * @param  string                    $name The store name
     * @return null|array<string, mixed> The store configuration, or null if not found
     */
    private function getConfig(string $name): ?array
    {
        /** @var Repository */
        $config = $this->container->make(Repository::class);

        /** @var null|array<string, mixed> $result */
        return $config->get('pole.stores.'.$name);
    }
}
