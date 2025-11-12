<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Override;

use function assert;
use function class_exists;
use function config_path;
use function func_num_args;
use function is_string;

/**
 * Service provider for the Pole feature flag package.
 *
 * Registers the feature manager, publishes configuration and migrations,
 * registers Blade directives, and sets up event listeners for cache clearing.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PoleServiceProvider extends ServiceProvider
{
    /**
     * Register the package's services.
     *
     * Binds the FeatureManager as a singleton and merges the package configuration.
     */
    #[Override()]
    public function register(): void
    {
        $this->app->singleton(function ($app): FeatureManager {
            /** @var \Illuminate\Contracts\Container\Container $app */
            return new FeatureManager($app);
        });

        $this->mergeConfigFrom(__DIR__.'/../config/pole.php', 'pole');
    }

    /**
     * Bootstrap the package's services.
     *
     * Publishes configuration and migrations when in console mode,
     * registers Blade directives for feature checking, and sets up event listeners.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->offerPublishing();

            // Register commands when available
            // $this->commands([
            //     \Cline\Pole\Commands\FeatureMakeCommand::class,
            //     \Cline\Pole\Commands\PurgeCommand::class,
            // ]);
        }

        $this->callAfterResolving('blade.compiler', function ($blade): void {
            /** @var BladeCompiler $blade */
            // @feature directive - Check if a feature is active or has a specific value
            $blade->if('feature', function (mixed $feature, mixed $value = null): bool {
                assert(is_string($feature));

                if (func_num_args() === 2) {
                    return Feature::value($feature) === $value;
                }

                return Feature::active($feature);
            });

            // @featureany directive - Check if any of the given features are active
            $blade->if('featureany', function (mixed $features): bool {
                /** @var array<string> $features */
                return Feature::someAreActive($features);
            });

            // @featureall directive - Check if all of the given features are active
            $blade->if('featureall', function (mixed $features): bool {
                /** @var array<string> $features */
                return Feature::allAreActive($features);
            });
        });

        $this->listenForEvents();
    }

    /**
     * Listen for the events relevant to the package.
     *
     * Sets up event listeners to flush the feature manager cache when appropriate,
     * including Laravel Octane events and queue job completion.
     */
    private function listenForEvents(): void
    {
        /** @var Dispatcher */
        $events = $this->app->make(Dispatcher::class);

        // Laravel Octane support - flush cache on new requests, tasks, and ticks
        if (class_exists('Laravel\Octane\Events\RequestReceived')) {
            $events->listen([
                'Laravel\Octane\Events\RequestReceived',
                'Laravel\Octane\Events\TaskReceived',
                'Laravel\Octane\Events\TickReceived',
            ], fn () => $this->getFeatureManager()
                ->setContainer(Container::getInstance())
                ->flushCache());
        }

        // Queue support - flush cache after job processing
        $events->listen([
            JobProcessed::class,
        ], fn () => $this->getFeatureManager()->flushCache());
    }

    /**
     * Get the feature manager instance.
     */
    private function getFeatureManager(): FeatureManager
    {
        /** @var FeatureManager */
        return $this->app->make(FeatureManager::class);
    }

    /**
     * Register the migrations and publishing for the package.
     *
     * Offers configuration and migration files for publishing to the application.
     */
    private function offerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/pole.php' => config_path('pole.php'),
        ], 'pole-config');

        // Always use publishes for migrations (publishesMigrations is a Laravel 11+ feature)
        $this->publishes([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'pole-migrations');
    }
}
