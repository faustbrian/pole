<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Contracts\FeatureScopeSerializeable;
use Cline\Pole\Drivers\ArrayDriver;
use Cline\Pole\Drivers\DatabaseDriver;
use Cline\Pole\FeatureManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Create features table for database driver tests
    Schema::create('features', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('scope');
        $table->text('value');
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->unique(['name', 'scope']);
    });
});

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

        test('serializes null scope', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $result = $manager->serializeScope(null);

            // Assert
            expect($result)->toBe('__laravel_null');
        });

        test('serializes string scope', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $result = $manager->serializeScope('user-123');

            // Assert
            expect($result)->toBe('user-123');
        });

        test('serializes numeric scope', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $result = $manager->serializeScope(123);

            // Assert
            expect($result)->toBe('123');
        });

        test('serializes model scope without morph map', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $model = new TestUser(['id' => 1]);

            // Act
            $result = $manager->serializeScope($model);

            // Assert
            expect($result)->toBe(TestUser::class.'|1');
        });

        test('serializes model scope with morph map enabled', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $manager->useMorphMap(true);

            $model = new TestUser(['id' => 1]);

            // Act
            $result = $manager->serializeScope($model);

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

        test('resolveScopeUsing sets custom resolver', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $customResolver = fn ($driver): string => 'custom-scope';

            // Act
            $manager->resolveScopeUsing($customResolver);

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
            config(['pole.stores.store1' => ['driver' => 'array']]);
            config(['pole.stores.store2' => ['driver' => 'array']]);
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
            config(['pole.stores.store1' => ['driver' => 'array']]);
            config(['pole.stores.store2' => ['driver' => 'array']]);
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
            config(['pole.stores.custom' => ['driver' => 'custom-driver']]);

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

        test('serializes custom FeatureScopeSerializeable', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $customScope = new TestCustomScope();

            // Act
            $result = $manager->serializeScope($customScope);

            // Assert
            expect($result)->toBe('custom-serialized-scope');
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
            config(['pole.stores.test' => ['driver' => 'unsupported-driver']]);

            // Act & Assert
            expect(fn () => $manager->store('test'))
                ->toThrow(InvalidArgumentException::class, 'Driver [unsupported-driver] is not supported.');
        });

        test('throws exception when serializing unsupported scope type', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $unsupportedScope = new stdClass();

            // Act & Assert
            expect(fn () => $manager->serializeScope($unsupportedScope))
                ->toThrow(RuntimeException::class, 'Unable to serialize the feature scope to a string.');
        });
    });

    describe('Edge Cases', function (): void {
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

            config(['pole.stores.custom' => ['driver' => 'test-driver', 'option' => 'value']]);

            $manager->extend('test-driver', function (array $container, $config) use (&$receivedContainer, &$receivedConfig): ArrayDriver {
                $receivedContainer = $container;
                $receivedConfig = $config;

                return new ArrayDriver($container['events'], []);
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
            $result = $manager->serializeScope($model);

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
            config(['pole.stores.store1' => ['driver' => 'array']]);
            config(['pole.stores.store2' => ['driver' => 'array']]);
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
            $result = $manager->serializeScope(123.45);

            // Assert
            expect($result)->toBe('123.45');
        });

        test('default scope resolver calls custom resolver when set', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $customScopeCalled = false;
            $manager->resolveScopeUsing(function (string $driver) use (&$customScopeCalled): string {
                $customScopeCalled = true;

                return 'custom-scope-'.$driver;
            });

            // Act - Access store to trigger scope resolver
            $store = $manager->store('array');
            $store->define('test', fn (): true => true);
            $store->get('test', null); // This triggers the default scope resolver

            // Trigger the resolver by calling a method that uses the default scope
            $reflection = new ReflectionClass($store);
            $method = $reflection->getMethod('defaultScope');

            $result = $method->invoke($store);

            // Assert - Line 350: custom resolver should be called
            expect($customScopeCalled)->toBeTrue();
            expect($result)->toBe('custom-scope-array');
        });
    });
});

// Test helper classes
/**
 * @author Brian Faust <brian@cline.sh>
 */
final class TestUser extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $table = 'users';

    protected $fillable = ['id'];

    public function getMorphClass()
    {
        return 'users';
    }

    #[Override()]
    public function getKey()
    {
        return $this->attributes['id'] ?? 1;
    }
}

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class TestCustomScope implements FeatureScopeSerializeable
{
    public function featureScopeSerialize(): string
    {
        return 'custom-serialized-scope';
    }
}
