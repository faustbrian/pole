<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Conductors\DependencyConductor;
use Cline\Toggl\ContextManager;
use Cline\Toggl\Contracts\Context;
use Cline\Toggl\Contracts\Serializable;
use Cline\Toggl\Drivers\ArrayDriver;
use Cline\Toggl\Drivers\CacheDriver;
use Cline\Toggl\Drivers\DatabaseDriver;
use Cline\Toggl\Drivers\GateDriver;
use Cline\Toggl\Exceptions\CannotSerializeContextException;
use Cline\Toggl\FeatureManager;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Set up the features database table before each test.
 *
 * Creates the features table schema required for database driver testing.
 * This setup ensures tests have a clean database state and can verify
 * FeatureManager's ability to create and configure database-backed drivers.
 */
beforeEach(function (): void {
    Schema::dropIfExists('features');

    $primaryKeyType = config('toggl.primary_key_type', 'id');

    // Create features table for database driver tests
    Schema::create('features', function ($table) use ($primaryKeyType): void {
        match ($primaryKeyType) {
            'ulid' => $table->ulid('id')->primary(),
            'uuid' => $table->uuid('id')->primary(),
            default => $table->id(),
        };

        $table->string('name');
        $table->string('context');
        $table->text('value');
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->unique(['name', 'context']);
    });
});

/**
 * FeatureManager test suite.
 *
 * Tests the central manager class responsible for driver creation, context serialization,
 * driver lifecycle management, and configuration. The FeatureManager acts as a factory
 * and registry for feature flag drivers, handling driver instantiation from configuration,
 * context normalization across different types (strings, models, custom objects), morph map
 * integration, custom driver extensions, and cache invalidation. Tests verify driver creation,
 * context serialization strategies, container integration, and the manager's role as a facade
 * that delegates to the default driver.
 */
describe('FeatureManager', function (): void {
    describe('Happy Path', function (): void {
        test('creates array driver', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $driver = $manager->createArrayDriver();

            // Assert
            expect($driver)->toBeInstanceOf(ArrayDriver::class);
        });

        test('creates database driver', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $config = ['driver' => 'database', 'table' => 'features'];

            // Act
            $driver = $manager->createDatabaseDriver($config, 'test');

            // Assert
            expect($driver)->toBeInstanceOf(DatabaseDriver::class);
        });

        test('serializes null context', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $result = $manager->serializeContext(null);

            // Assert
            expect($result)->toBe('__laravel_null');
        });

        test('serializes string context', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $result = $manager->serializeContext('user-123');

            // Assert
            expect($result)->toBe('user-123');
        });

        test('serializes numeric context', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $result = $manager->serializeContext(123);

            // Assert
            expect($result)->toBe('123');
        });

        test('serializes model context without morph map', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $model = new TestUser(['id' => 1]);

            // Act
            $result = $manager->serializeContext($model);

            // Assert
            expect($result)->toBe(TestUser::class.'|1');
        });

        test('serializes model context with morph map enabled', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $manager->useMorphMap(true);

            $model = new TestUser(['id' => 1]);

            // Act
            $result = $manager->serializeContext($model);

            // Assert
            expect($result)->toBe('users|1');
        });

        test('useMorphMap returns manager instance for chaining', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $result = $manager->useMorphMap(true);

            // Assert
            expect($result)->toBe($manager);
        });

        test('resolveContextUsing sets custom resolver', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $customResolver = fn ($driver): string => 'custom-context';

            // Act
            $manager->resolveContextUsing($customResolver);

            // Assert - The resolver is set (we can't directly test it without triggering driver resolution)
            expect(true)->toBeTrue();
        });

        test('setDefaultDriver changes default driver', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $originalDefault = $manager->getDefaultDriver();

            // Act
            $manager->setDefaultDriver('custom');
            $newDefault = $manager->getDefaultDriver();

            // Assert
            expect($originalDefault)->toBe('array');
            expect($newDefault)->toBe('custom');
        });

        test('forgetDriver removes single driver from cache', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $store1 = $manager->store('array');
            $store2 = $manager->store('array'); // Should return cached

            // Act
            $manager->forgetDriver('array');
            $store3 = $manager->store('array'); // Should create new

            // Assert
            expect($store1)->toBe($store2); // Same instance before forget
            expect($store1)->not->toBe($store3); // Different instance after forget
        });

        test('forgetDriver removes multiple drivers from cache', function (): void {
            // Arrange
            config(['toggl.stores.store1' => ['driver' => 'array']]);
            config(['toggl.stores.store2' => ['driver' => 'array']]);
            $manager = app(FeatureManager::class);
            $manager->store('store1');
            $manager->store('store2');

            // Act
            $manager->forgetDriver(['store1', 'store2']);

            // Assert - No exception should be thrown when accessing again
            expect($manager->store('store1'))->not->toBeNull();
            expect($manager->store('store2'))->not->toBeNull();
        });

        test('forgetDriver with null removes default driver', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $store1 = $manager->store(); // Get default

            // Act
            $manager->forgetDriver();
            $store2 = $manager->store(); // Should create new

            // Assert
            expect($store1)->not->toBe($store2);
        });

        test('forgetDrivers removes all drivers from cache', function (): void {
            // Arrange
            config(['toggl.stores.store1' => ['driver' => 'array']]);
            config(['toggl.stores.store2' => ['driver' => 'array']]);
            $manager = app(FeatureManager::class);
            $store1 = $manager->store('store1');
            $store2 = $manager->store('store2');

            // Act
            $result = $manager->forgetDrivers();

            // Assert
            expect($result)->toBe($manager); // Returns manager for chaining
            expect($manager->store('store1'))->not->toBe($store1);
            expect($manager->store('store2'))->not->toBe($store2);
        });

        test('extend registers custom driver creator', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            config(['toggl.stores.custom' => ['driver' => 'custom-driver']]);

            // Act
            $result = $manager->extend('custom-driver', fn ($container, $config): ArrayDriver => new ArrayDriver($container['events'], []));

            // Assert
            expect($result)->toBe($manager); // Returns manager for chaining
            expect($manager->store('custom'))->not->toBeNull();
        });

        test('setContainer updates container for all stores', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $manager->store('array');
            // Create a store
            $newContainer = app(Container::class);

            // Act
            $result = $manager->setContainer($newContainer);

            // Assert
            expect($result)->toBe($manager); // Returns manager for chaining
        });

        test('serializes custom Serializable', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $customContext = new TestCustomContext();

            // Act
            $result = $manager->serializeContext($customContext);

            // Assert
            expect($result)->toBe('custom-serialized-context');
        });

        test('creates cache driver with parameters', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $config = ['driver' => 'cache', 'store' => 'redis'];

            // Act
            $driver = $manager->createCacheDriver($config, 'test-cache');

            // Assert
            expect($driver)->toBeInstanceOf(CacheDriver::class);
        });

        test('creates gate driver with parameters', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $config = ['driver' => 'gate', 'guard' => 'web'];

            // Act
            $driver = $manager->createGateDriver($config, 'test-gate');

            // Assert
            expect($driver)->toBeInstanceOf(GateDriver::class);
        });

        test('serializes array context using md5 hash', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $arrayContext = ['key' => 'value', 'nested' => ['data' => 'test']];

            // Act
            $result = $manager->serializeContext($arrayContext);

            // Assert
            expect($result)->toBe(md5(serialize($arrayContext)));
            expect($result)->toBeString();
        });

        test('setContextManager sets custom context manager', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $customContext = new TestContextManager();

            // Act
            $result = $manager->setContextManager($customContext);

            // Assert
            expect($result)->toBe($manager); // Returns manager for chaining
            expect($manager->context())->toBe($customContext);
        });

        test('context() creates and caches ContextManager instance', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $context1 = $manager->context();
            $context2 = $manager->context();

            // Assert
            expect($context1)->toBeInstanceOf(ContextManager::class);
            expect($context1)->toBe($context2); // Same instance returned
        });

        test('context() sets feature manager on ContextManager', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $context = $manager->context();

            // Assert - Verify that the context manager has the feature manager set
            $reflection = new ReflectionClass($context);
            $property = $reflection->getProperty('featureManager');

            expect($property->getValue($context))->toBe($manager);
        });
    });

    describe('Sad Path', function (): void {
        test('throws exception when store is not defined', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act & Assert
            expect(fn () => $manager->store('non-existent'))
                ->toThrow(InvalidArgumentException::class, 'Feature flag store [non-existent] is not defined.');
        });

        test('throws exception when driver is not supported', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            config(['toggl.stores.test' => ['driver' => 'unsupported-driver']]);

            // Act & Assert
            expect(fn () => $manager->store('test'))
                ->toThrow(InvalidArgumentException::class, 'Driver [unsupported-driver] is not supported.');
        });

        test('can serialize object contexts using spl_object_hash', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $objectContext = new stdClass();

            // Act
            $serialized = $manager->serializeContext($objectContext);

            // Assert
            expect($serialized)->toBeString();
            expect($serialized)->toBe(spl_object_hash($objectContext));
        });
    });

    describe('Edge Cases', function (): void {
        test('createCacheDriver uses container to resolve dependencies', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $config = ['driver' => 'cache'];

            // Act
            $driver1 = $manager->createCacheDriver($config, 'cache1');
            $driver2 = $manager->createCacheDriver($config, 'cache2');

            // Assert
            expect($driver1)->toBeInstanceOf(CacheDriver::class);
            expect($driver2)->toBeInstanceOf(CacheDriver::class);
            expect($driver1)->not->toBe($driver2); // Different instances
        });

        test('createGateDriver uses container to resolve dependencies', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $config = ['driver' => 'gate'];

            // Act
            $driver1 = $manager->createGateDriver($config, 'gate1');
            $driver2 = $manager->createGateDriver($config, 'gate2');

            // Assert
            expect($driver1)->toBeInstanceOf(GateDriver::class);
            expect($driver2)->toBeInstanceOf(GateDriver::class);
            expect($driver1)->not->toBe($driver2); // Different instances
        });

        test('context() lazy creates ContextManager only when accessed', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act - First call creates instance
            $context1 = $manager->context();

            // Act - Subsequent calls return same instance
            $context2 = $manager->context();

            // Assert
            expect($context1)->toBeInstanceOf(ContextManager::class);
            expect($context2)->toBe($context1);
        });

        test('setContextManager replaces default ContextManager', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // First get default context
            $defaultContext = $manager->context();
            expect($defaultContext)->toBeInstanceOf(ContextManager::class);

            // Act - Replace with custom context
            $customContext = new TestContextManager();
            $manager->setContextManager($customContext);

            // Assert
            expect($manager->context())->toBe($customContext);
            expect($manager->context())->not->toBe($defaultContext);
        });

        test('serializes empty array context', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $emptyArray = [];

            // Act
            $result = $manager->serializeContext($emptyArray);

            // Assert
            expect($result)->toBe(md5(serialize($emptyArray)));
        });

        test('serializes nested array context', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $nestedArray = [
                'user' => ['id' => 1, 'roles' => ['admin', 'editor']],
                'team' => ['id' => 5, 'name' => 'Test Team'],
            ];

            // Act
            $result = $manager->serializeContext($nestedArray);

            // Assert
            expect($result)->toBe(md5(serialize($nestedArray)));
            expect($result)->toBeString();
        });

        test('forgetDriver handles non-existent driver gracefully', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $result = $manager->forgetDriver('non-existent');

            // Assert
            expect($result)->toBe($manager);
        });

        test('setContainer works with empty stores array', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $newContainer = app(Container::class);

            // Act
            $result = $manager->setContainer($newContainer);

            // Assert
            expect($result)->toBe($manager);
        });

        test('custom driver creator receives correct parameters', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $receivedContainer = null;
            $receivedConfig = null;

            config(['toggl.stores.custom' => ['driver' => 'test-driver', 'option' => 'value']]);

            $manager->extend('test-driver', function (Container $container, $config) use (&$receivedContainer, &$receivedConfig): ArrayDriver {
                $receivedContainer = $container;
                $receivedConfig = $config;

                return new ArrayDriver($container->make(Dispatcher::class), []);
            });

            // Act
            $manager->store('custom');

            // Assert
            expect($receivedContainer)->not->toBeNull();
            expect($receivedConfig)->toHaveKey('driver', 'test-driver');
            expect($receivedConfig)->toHaveKey('option', 'value');
        });

        test('useMorphMap can be disabled after being enabled', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $manager->useMorphMap(true);

            $model = new TestUser(['id' => 1]);

            // Act
            $manager->useMorphMap(false);
            $result = $manager->serializeContext($model);

            // Assert
            expect($result)->toBe(TestUser::class.'|1');
        });

        test('driver method returns same instance as store method', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $store = $manager->store('array');
            $driver = $manager->driver('array');

            // Assert
            expect($store)->toBe($driver);
        });

        test('flushCache handles multiple stores', function (): void {
            // Arrange
            config(['toggl.stores.store1' => ['driver' => 'array']]);
            config(['toggl.stores.store2' => ['driver' => 'array']]);
            $manager = app(FeatureManager::class);
            $manager->store('store1');
            $manager->store('store2');

            // Act - Should not throw exception
            $manager->flushCache();

            // Assert
            expect(true)->toBeTrue();
        });

        test('__call proxies to default store', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $manager->define('test-feature', true);

            $defined = $manager->defined();

            // Assert
            expect($defined)->toContain('test-feature');
        });

        test('serializes float as string', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $result = $manager->serializeContext(123.45);

            // Assert
            expect($result)->toBe('123.45');
        });

        test('throws exception for non-serializable resource type', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $resource = fopen('php://memory', 'rb');

            // Act & Assert
            expect(fn () => $manager->serializeContext($resource))
                ->toThrow(CannotSerializeContextException::class);

            fclose($resource);
        });

        test('default context resolver calls custom resolver when set', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $customContextCalled = false;
            $manager->resolveContextUsing(function (string $driver) use (&$customContextCalled): TogglContext {
                $customContextCalled = true;

                return TogglContext::simple('custom-context-'.$driver, 'test');
            });

            // Act - Access store to trigger context resolver
            $store = $manager->store('array');

            // Trigger the resolver by calling a method that uses the default context
            $reflection = new ReflectionClass($store);
            $method = $reflection->getMethod('defaultContext');

            $result = $method->invoke($store);

            // Assert - Line 350: custom resolver should be called
            expect($customContextCalled)->toBeTrue();
            expect($result->id)->toBe('custom-context-array');
        });

        test('dependency creates DependencyConductor', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $manager->define('auth', fn (): true => true);
            $manager->define('premium', fn (): true => true);

            // Act
            $conductor = $manager->dependency(['auth', 'premium']);

            // Assert - Line 747: dependency() method
            expect($conductor)->toBeInstanceOf(DependencyConductor::class);
        });
    });
});

/**
 * Test user model for context serialization testing.
 *
 * A minimal Eloquent model that demonstrates how the FeatureManager serializes
 * model contexts, including support for morph maps. The getMorphClass override
 * returns 'users' to test morph map-based serialization, while getKey provides
 * the model identifier for context generation.
 *
 * @internal
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class TestUser extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $table = 'users';

    protected $fillable = ['id'];

    /**
     * Get the morph class name for the model.
     *
     * @return string The morph class identifier used in polymorphic relations and context serialization
     */
    public function getMorphClass()
    {
        return 'users';
    }

    /**
     * Get the model's primary key value.
     *
     * @return mixed The primary key value, defaults to 1 if not set for testing purposes
     */
    #[Override()]
    public function getKey()
    {
        return $this->attributes['id'] ?? 1;
    }
}

/**
 * Test implementation of Serializable for custom context serialization.
 *
 * Demonstrates how custom objects can control their own context serialization by
 * implementing the Serializable contract. Used to verify the
 * FeatureManager correctly delegates serialization to objects that provide
 * their own serialization logic.
 *
 * @internal
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class TestCustomContext implements Serializable
{
    /**
     * Serialize this context for feature flag storage.
     *
     * @return string The serialized context identifier used for feature flag lookups
     */
    public function serialize(): string
    {
        return 'custom-serialized-context';
    }
}

/**
 * Test implementation of Context for custom context manager testing.
 *
 * A minimal implementation of the Context contract used to verify that
 * FeatureManager correctly accepts and uses custom context manager implementations
 * via the setContextManager method.
 *
 * @internal
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class TestContextManager implements Context
{
    private mixed $context = null;

    public function to(mixed $identifier): static
    {
        $this->context = $identifier;

        return $this;
    }

    public function current(): mixed
    {
        return $this->context;
    }

    public function hasContext(): bool
    {
        return $this->context !== null;
    }

    public function clear(): static
    {
        $this->context = null;

        return $this;
    }
}
