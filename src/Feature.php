<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole;

use Cline\Pole\Contracts\Driver;
use Cline\Pole\Drivers\ArrayDriver;
use Cline\Pole\Drivers\Decorator;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/**
 * Feature flag facade for managing feature toggles across your application.
 *
 * This facade provides a clean API for defining, activating, checking, and managing
 * feature flags with support for scoped features (per user, team, etc.), feature groups,
 * variants, and multiple storage drivers.
 *
 * @method static void                             activateGroup(string $name, mixed $value = true)                                  Activate all features in a group
 * @method static bool                             active(string $feature)                                                           Check if feature is active
 * @method static bool                             activeInGroup(string $name)                                                       Check if all features in group are active
 * @method static array<string, mixed>             all()                                                                             Get all features and their values
 * @method static bool                             allAreActive(array<string> $features)                                             Check if all features are active
 * @method static bool                             allAreInactive(array<string> $features)                                           Check if all features are inactive
 * @method static ArrayDriver                      createArrayDriver()                                                               Create an array-based driver instance
 * @method static void                             deactivateGroup(string $name)                                                     Deactivate all features in a group
 * @method static void                             define(string $feature, mixed $resolver = null)                                   Define a feature with optional resolver
 * @method static array<string>                    defined()                                                                         Get all defined feature names
 * @method static void                             defineGroup(string $name, array<string> $features)                                Define a feature group
 * @method static Decorator                        driver((string|null) $name = null)                                                Get a specific driver instance
 * @method static FeatureManager                   extend(string $driver, \Closure $callback)                                        Register a custom driver creator
 * @method static void                             purge(string|array<string>|null $features = null) Purge feature(s)                from storage
 * @method static void                             flushCache()                                                                      Flush the in-memory feature cache
 * @method static PendingScopedFeatureInteraction  for(mixed $scope)                                                                 Create a scoped feature interaction
 * @method static FeatureManager                   forgetDrivers()                                                                   Remove all driver instances
 * @method static string                           getDefaultDriver()                                                                Get the default driver name
 * @method static Driver                           getDriver()                                                                       Get the current driver instance
 * @method static array<string>                    getGroup(string $name)                                                            Get all features in a group
 * @method static bool                             hasGroup(string $name)                                                            Check if a group exists
 * @method static bool                             inactive(string $feature)                                                         Check if feature is inactive
 * @method static mixed                            instance(string $name)                                                            Get a driver instance by name
 * @method static array<string, array<int, mixed>> loadAll()                                                                         Load all defined features into memory
 * @method static array<string, array<int, mixed>> loadMissing(string|array<int, string> $features)                                  Load missing features into memory
 * @method static string                           name(string $feature)                                                             Get the canonical name for a feature
 * @method static array<string, mixed>             nameMap()                                                                         Get the feature name mapping
 * @method static void                             resolveScopeUsing(callable $resolver)                                             Set custom scope resolver
 * @method static string                           serializeScope(mixed $scope)                                                      Serialize a scope for storage
 * @method static FeatureManager                   setContainer(Container $container)                                                Set the IoC container
 * @method static void                             setDefaultDriver(string $name)                                                    Set the default driver
 * @method static bool                             someAreActive(array<string> $features)                                            Check if any features are active
 * @method static bool                             someAreInactive(array<string> $features)                                          Check if any features are inactive
 * @method static Decorator                        store((string|null) $store = null)                                                Get a driver by store name
 * @method static array<string>                    stored()                                                                          Get all stored feature names
 * @method static mixed                            unless(string $feature, \Closure $whenInactive, \Closure|null $whenActive = null) Execute callback if feature is inactive
 * @method static FeatureManager                   useMorphMap(bool $value = true)                                                   Enable morph map usage for scope serialization
 * @method static mixed                            value(string $feature)                                                            Get the feature value
 * @method static array<string, mixed>             values(array<string> $features)                                                   Get multiple feature values
 * @method static mixed                            variant(string $feature)                                                          Get the assigned variant for a feature
 * @method static mixed                            when(string $feature, \Closure $whenActive, \Closure|null $whenInactive = null)   Execute callback if feature is active
 *
 * @see FeatureManager
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class Feature extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string The service container binding key
     */
    protected static function getFacadeAccessor(): string
    {
        return FeatureManager::class;
    }
}
