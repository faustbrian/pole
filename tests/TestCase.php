<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests;

use Cline\Toggl\Enums\SnapshotDriver;
use Cline\Toggl\TogglServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;

use function env;

/**
 * Base test case for Toggl feature flag package tests.
 *
 * Provides common test setup and configuration for all test suites, including
 * package service provider registration, default driver configuration, and
 * database schema setup for testing. Uses Orchestra Testbench to simulate
 * a minimal Laravel application environment for package testing.
 *
 * @internal
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Register the Toggl service provider for testing.
     *
     * Defines which Laravel service providers should be loaded when running tests.
     * The TogglServiceProvider registers all feature flag drivers, repositories,
     * and related services into the Laravel application container.
     *
     * @param  Application              $app The Laravel application instance being bootstrapped for testing
     * @return array<int, class-string> Array of service provider class names to register
     */
    protected function getPackageProviders($app): array
    {
        return [
            TogglServiceProvider::class,
        ];
    }

    /**
     * Configure the test environment before running tests.
     *
     * Sets the default feature flag driver to 'array' for testing purposes.
     * The array driver stores feature flags in memory, providing fast, isolated
     * tests without external dependencies like databases or caches.
     *
     * @param Application $app The Laravel application instance to configure
     */
    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set('toggl.default', 'array');
        $app->make(Repository::class)->set('toggl.primary_key_type', env('TOGGL_PRIMARY_KEY_TYPE', 'id'));
        $app->make(Repository::class)->set('toggl.morph_type', env('TOGGL_MORPH_TYPE', 'morph'));
        $app->make(Repository::class)->set('toggl.snapshots.driver', SnapshotDriver::Array);
    }

    /**
     * Create database tables required for testing.
     *
     * Sets up a minimal users table schema needed for feature flag context testing.
     * This table supports tests that verify feature flags work correctly with
     * user contexts, model serialization, and database-backed drivers.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
