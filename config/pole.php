<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Strategies\BooleanStrategy;
use Cline\Pole\Strategies\ConditionalStrategy;
use Cline\Pole\Strategies\PercentageStrategy;
use Cline\Pole\Strategies\ScheduledStrategy;
use Cline\Pole\Strategies\TimeBasedStrategy;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Feature Flags Store
    |--------------------------------------------------------------------------
    |
    | This value defines the default feature flags store that will be used
    | by the framework. The "database" store is recommended for production
    | as it provides persistence across requests.
    |
    */

    'default' => env('FEATURE_FLAGS_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Feature Flag Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the feature flag stores for your application
    | as well as their drivers. You may even define multiple stores for the
    | same driver to group related features together.
    |
    | Supported drivers: "array", "database"
    |
    */

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CONNECTION', null),
            'table' => 'features',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flag Strategies
    |--------------------------------------------------------------------------
    |
    | Here you may define the strategies available for feature flags. Each
    | strategy determines how feature flags are evaluated. You can extend
    | this with custom strategies by implementing the Strategy interface.
    |
    */

    'strategies' => [
        'default' => 'boolean',

        'available' => [
            'boolean' => BooleanStrategy::class,
            'time_based' => TimeBasedStrategy::class,
            'percentage' => PercentageStrategy::class,
            'scheduled' => ScheduledStrategy::class,
            'conditional' => ConditionalStrategy::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Time Bombs
    |--------------------------------------------------------------------------
    |
    | Time bombs are features that automatically expire after a certain date.
    | This configuration controls when warnings should be issued before a
    | feature expires.
    |
    */

    'time_bombs' => [
        'enabled' => env('FEATURE_FLAGS_TIME_BOMBS_ENABLED', true),
        'warn_days_before' => env('FEATURE_FLAGS_WARN_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Groups
    |--------------------------------------------------------------------------
    |
    | Feature groups allow you to organize related features together and
    | activate/deactivate them as a unit. Define your groups here.
    |
    */

    'groups' => [
        // 'experimental' => [
        //     'features' => ['new-ui', 'beta-api'],
        //     'description' => 'Experimental features for testing',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Dependencies
    |--------------------------------------------------------------------------
    |
    | Some features may depend on other features being active. Define those
    | dependencies here. When a feature is activated, its dependencies will
    | be checked automatically.
    |
    */

    'dependencies' => [
        // 'premium-dashboard' => ['user-authentication', 'premium-subscription'],
    ],
];
