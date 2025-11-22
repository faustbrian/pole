<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl;

use Cline\Toggl\Console\Commands\MigrateCommand;
use Cline\Toggl\Console\Commands\PruneSnapshotsCommand;
use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Contracts\SnapshotRepository;
use Cline\Toggl\Database\ModelRegistry;
use Cline\Toggl\Enums\SnapshotDriver;
use Cline\Toggl\Events\FeatureActivated;
use Cline\Toggl\Events\FeatureDeactivated;
use Cline\Toggl\Exceptions\InvalidConfigurationException;
use Cline\Toggl\Listeners\AutoSnapshotListener;
use Cline\Toggl\Repositories\ArraySnapshotRepository;
use Cline\Toggl\Repositories\CacheSnapshotRepository;
use Cline\Toggl\Repositories\DatabaseSnapshotRepository;
use Illuminate\Container\Container;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\View\Compilers\BladeCompiler;
use Laravel\Octane\Contracts\OperationTerminated;
use Override;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

use function config;
use function func_num_args;
use function interface_exists;
use function is_array;

/**
 * Service provider for the Toggl feature flag package.
 *
 * Registers the feature manager, publishes configuration and migrations,
 * registers Blade directives, and sets up event listeners for cache clearing.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class TogglServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package settings.
     *
     * Defines package configuration including the package name, config file,
     * and database migration for feature flag storage tables.
     *
     * @param Package $package The package configuration instance to configure
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('toggl')
            ->hasConfigFile()
            ->hasMigrations(['create_toggl_tables'])
            ->hasCommands([
                MigrateCommand::class,
                PruneSnapshotsCommand::class,
            ]);
    }

    /**
     * Register the package's services in the container.
     *
     * Binds the SnapshotRepository based on configured driver. FeatureManager
     * uses #[Singleton] attribute for automatic singleton registration.
     */
    #[Override()]
    public function registeringPackage(): void
    {
        // Register Driver based on default store config
        $this->app->singleton(function (Container $app): Driver {
            /** @var FeatureManager $manager */
            $manager = $app->make(FeatureManager::class);

            return $manager->store();
        });

        // Register SnapshotRepository based on config (conditional binding requires closure)
        $this->app->singleton(function (Container $app): SnapshotRepository {
            /** @var FeatureManager $manager */
            $manager = $app->make(FeatureManager::class);

            return match (Config::get('toggl.snapshots.driver')) {
                SnapshotDriver::Database => new DatabaseSnapshotRepository($manager),
                SnapshotDriver::Cache => new CacheSnapshotRepository($manager),
                default => new ArraySnapshotRepository($manager),
            };
        });
    }

    /**
     * Bootstrap the package's services.
     *
     * Registers Blade directives for feature checking in views and sets up
     * event listeners for automatic cache management in long-running processes.
     */
    #[Override()]
    public function bootingPackage(): void
    {
        $this->configureMorphKeyMaps();

        $this->callAfterResolving(BladeCompiler::class, function (BladeCompiler $blade): void {
            // @feature directive - Check if a feature is active or has a specific value
            // Usage: @feature('new-ui') or @feature('theme', 'dark')
            $blade->if(
                'feature',
                fn (string $feature, mixed $value = null): bool => func_num_args() === 2
                    ? Toggl::value($feature) === $value
                    : Toggl::active($feature),
            );

            // Positive checks - hasFeature variants
            // @hasFeature('premium') - Check if a single feature is active
            $blade->if('hasFeature', fn (string $feature): bool => Toggl::active($feature));

            // @hasAnyFeature(['premium', 'trial']) - Check if any of the given features are active
            $blade->if('hasAnyFeature', function (array $features): bool {
                /** @var array<string> $features */
                return Toggl::someAreActive($features);
            });

            // @hasAllFeatures(['api-v2', 'webhooks']) - Check if all of the given features are active
            $blade->if('hasAllFeatures', function (array $features): bool {
                /** @var array<string> $features */
                return Toggl::allAreActive($features);
            });

            // Negative checks - missingFeature variants
            // @missingFeature('premium') - Check if a single feature is inactive
            $blade->if('missingFeature', fn (string $feature): bool => Toggl::inactive($feature));

            // @missingAnyFeature(['premium', 'trial']) - Check if any of the given features are inactive
            $blade->if('missingAnyFeature', function (array $features): bool {
                /** @var array<string> $features */
                return Toggl::someAreInactive($features);
            });

            // @missingAllFeatures(['api-v2', 'webhooks']) - Check if all of the given features are inactive
            $blade->if('missingAllFeatures', function (array $features): bool {
                /** @var array<string> $features */
                return Toggl::allAreInactive($features);
            });

            // Unless variants (alternative naming for negative checks)
            // @unlessFeature('premium') - Same as @missingFeature
            $blade->if('unlessFeature', fn (string $feature): bool => Toggl::inactive($feature));

            // @unlessAnyFeature(['premium', 'trial']) - Same as @missingAnyFeature
            $blade->if('unlessAnyFeature', function (array $features): bool {
                /** @var array<string> $features */
                return Toggl::someAreInactive($features);
            });

            // @unlessAllFeatures(['api-v2', 'webhooks']) - Same as @missingAllFeatures
            $blade->if('unlessAllFeatures', function (array $features): bool {
                /** @var array<string> $features */
                return Toggl::allAreInactive($features);
            });
        });

        $this->listenForEvents();
    }

    /**
     * Listen for events relevant to feature flag cache management.
     *
     * Sets up event listeners to flush the feature manager cache when appropriate.
     * This ensures features are re-evaluated in long-running processes (Octane)
     * and after queue job processing, preventing stale feature flag values in
     * persistent application states.
     *
     * Laravel Octane keeps the application in memory between requests, which
     * means cached feature flags could persist across different requests or users.
     * Queue jobs may also have cached feature values from earlier in the process.
     * Flushing the cache ensures each request and job starts with fresh feature data.
     */
    private function listenForEvents(): void
    {
        // Laravel Octane support - flush cache after operations complete
        if (Config::get('toggl.register_octane_reset_listener', true) && interface_exists(OperationTerminated::class)) {
            Event::listen(fn (OperationTerminated $event) => $this->getFeatureManager()
                ->setContainer(Container::getInstance())
                ->flushCache());
        }

        // Queue support - flush cache after job processing
        Event::listen([
            JobProcessed::class,
        ], fn () => $this->getFeatureManager()->flushCache());

        // Automatic snapshot creation on feature changes (if events are enabled)
        if (Config::get('toggl.events.enabled', true)) {
            Event::listen([
                FeatureActivated::class,
                FeatureDeactivated::class,
            ], AutoSnapshotListener::class);
        }
    }

    /**
     * Get the feature manager instance from the container.
     *
     * @return FeatureManager The singleton feature manager instance
     */
    private function getFeatureManager(): FeatureManager
    {
        /** @var FeatureManager */
        return $this->app->make(FeatureManager::class);
    }

    /**
     * Applies morphKeyMap or enforceMorphKeyMap configuration based on which is defined.
     * Throws InvalidConfigurationException if both are configured simultaneously.
     *
     * @throws InvalidConfigurationException When both morphKeyMap and enforceMorphKeyMap are configured
     */
    private function configureMorphKeyMaps(): void
    {
        $morphKeyMap = Config::get('toggl.morphKeyMap', []);
        $enforceMorphKeyMap = Config::get('toggl.enforceMorphKeyMap', []);

        if (!is_array($morphKeyMap)) {
            $morphKeyMap = [];
        }

        if (!is_array($enforceMorphKeyMap)) {
            $enforceMorphKeyMap = [];
        }

        $hasMorphKeyMap = $morphKeyMap !== [];
        $hasEnforceMorphKeyMap = $enforceMorphKeyMap !== [];

        if ($hasMorphKeyMap && $hasEnforceMorphKeyMap) {
            throw InvalidConfigurationException::conflictingMorphKeyMaps();
        }

        $registry = $this->app->make(ModelRegistry::class);

        if ($hasEnforceMorphKeyMap) {
            /** @var array<class-string, string> $enforceMorphKeyMap */
            $registry->enforceMorphKeyMap($enforceMorphKeyMap);
        } elseif ($hasMorphKeyMap) {
            /** @var array<class-string, string> $morphKeyMap */
            $registry->morphKeyMap($morphKeyMap);
        }
    }
}
