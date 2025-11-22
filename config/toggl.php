<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
|--------------------------------------------------------------------------
| Toggl Feature Flag Configuration
|--------------------------------------------------------------------------
|
| This file defines the configuration for Toggl, a Laravel feature flag
| management package. It controls how feature flags are stored, evaluated,
| and managed across your application. The configuration supports multiple
| storage drivers, evaluation strategies, and advanced features like groups,
| dependencies, and time bombs for automatic feature expiration.
|
*/

use Cline\Toggl\Database\Feature;
use Cline\Toggl\Database\FeatureGroup;
use Cline\Toggl\Database\FeatureSnapshot;
use Cline\Toggl\Database\FeatureSnapshotEntry;
use Cline\Toggl\Database\FeatureSnapshotEvent;
use Cline\Toggl\Database\GroupMembership;
use Cline\Toggl\Enums\SnapshotDriver;
use Cline\Toggl\Strategies\BooleanStrategy;
use Cline\Toggl\Strategies\ConditionalStrategy;
use Cline\Toggl\Strategies\PercentageStrategy;
use Cline\Toggl\Strategies\ScheduledStrategy;
use Cline\Toggl\Strategies\TimeBasedStrategy;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Feature Flags Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default feature flags store that will be used
    | by the framework. This connection is utilised if another isn't
    | explicitly specified when checking a feature flag in the application.
    |
    */

    'default' => env('FEATURE_FLAGS_STORE', 'database'),
    /*
    |--------------------------------------------------------------------------
    | Primary Key Type
    |--------------------------------------------------------------------------
    |
    | This option controls the type of primary key used in Toggl's database
    | tables. You may use traditional auto-incrementing integers or choose
    | ULIDs or UUIDs for distributed systems or enhanced privacy.
    |
    | Supported: "id", "ulid", "uuid"
    |
    */

    'primary_key_type' => env('TOGGL_PRIMARY_KEY_TYPE', 'id'),
    /*
    |--------------------------------------------------------------------------
    | Morph Type
    |--------------------------------------------------------------------------
    |
    | This option controls the type of polymorphic relationship columns used
    | for context relationships in Toggl's database tables. This determines how
    | features are associated with different model types (users, teams, etc).
    |
    | Supported: "morph", "uuidMorph", "ulidMorph"
    |
    */

    'morph_type' => env('TOGGL_MORPH_TYPE', 'morph'),
    /*
    |--------------------------------------------------------------------------
    | Eloquent Models
    |--------------------------------------------------------------------------
    |
    | When using the database driver or snapshot features, Toggl needs to know
    | which Eloquent models should be used to interact with the database. You
    | may extend these models with your own implementations whilst ensuring
    | they extend the base classes provided by Toggl.
    |
    */

    'models' => [
        /*
        |--------------------------------------------------------------------------
        | Feature Model
        |--------------------------------------------------------------------------
        |
        | This model is used to retrieve your feature flags from the database.
        | The model you specify must extend the `Cline\Toggl\Database\Feature`
        | class. This allows you to customise the feature model behaviour whilst
        | maintaining compatibility with Toggl's internal operations.
        |
        */

        'feature' => Feature::class,
        /*
        |--------------------------------------------------------------------------
        | Feature Group Model
        |--------------------------------------------------------------------------
        |
        | This model is used to retrieve your feature groups from the database.
        | The model you specify must extend the `Cline\Toggl\Database\FeatureGroup`
        | class. Groups allow you to organise multiple features under a single
        | logical unit for easier management and activation.
        |
        */

        'feature_group' => FeatureGroup::class,
        /*
        |--------------------------------------------------------------------------
        | Group Membership Model
        |--------------------------------------------------------------------------
        |
        | This model is used to retrieve group memberships from the database. The
        | model you specify must extend the `Cline\Toggl\Database\GroupMembership`
        | class. This model maintains the relationships between features and the
        | groups they belong to.
        |
        */

        'group_membership' => GroupMembership::class,
        /*
        |--------------------------------------------------------------------------
        | Feature Snapshot Model
        |--------------------------------------------------------------------------
        |
        | This model is used to retrieve feature snapshots from the database. The
        | model you specify must extend the `Cline\Toggl\Database\FeatureSnapshot`
        | class. Snapshots capture the complete state of your feature flags at
        | a specific point in time for backup and restore purposes.
        |
        */

        'feature_snapshot' => FeatureSnapshot::class,
        /*
        |--------------------------------------------------------------------------
        | Feature Snapshot Entry Model
        |--------------------------------------------------------------------------
        |
        | This model is used to retrieve feature snapshot entries. The model you
        | specify must extend the `Cline\Toggl\Database\FeatureSnapshotEntry`
        | class. Each entry represents a single feature's state within a snapshot,
        | including its name, value, and activation status.
        |
        */

        'feature_snapshot_entry' => FeatureSnapshotEntry::class,
        /*
        |--------------------------------------------------------------------------
        | Feature Snapshot Event Model
        |--------------------------------------------------------------------------
        |
        | This model is used to retrieve feature snapshot events. The model you
        | specify must extend the `Cline\Toggl\Database\FeatureSnapshotEvent`
        | class. This model maintains an audit trail of all snapshot operations,
        | including who performed them and when.
        |
        */

        'feature_snapshot_event' => FeatureSnapshotEvent::class,
    ],
    /*
    |--------------------------------------------------------------------------
    | Database Table Names
    |--------------------------------------------------------------------------
    |
    | When using the database driver or snapshot features, Toggl needs to know
    | which table names should be used to store your feature flags, groups,
    | memberships, and snapshots. These table names are used by both the
    | migrations and Eloquent models.
    |
    */

    'table_names' => [
        /*
        |--------------------------------------------------------------------------
        | Features Table
        |--------------------------------------------------------------------------
        |
        | This table stores feature flag definitions, values, strategies, and
        | expiration settings with polymorphic context support. It serves as
        | the central repository for all feature flags when using the database
        | driver.
        |
        */

        'features' => env('TOGGL_FEATURES_TABLE', 'features'),
        /*
        |--------------------------------------------------------------------------
        | Feature Groups Table
        |--------------------------------------------------------------------------
        |
        | This table stores named groups of related features with optional
        | metadata for batch operations. Groups allow you to activate or
        | deactivate multiple features as a cohesive unit.
        |
        */

        'feature_groups' => env('TOGGL_GROUPS_TABLE', 'feature_groups'),
        /*
        |--------------------------------------------------------------------------
        | Group Memberships Table
        |--------------------------------------------------------------------------
        |
        | This table stores context assignments to feature groups, enabling batch
        | feature activation for specific contexts. This junction table maintains
        | the many-to-many relationship between contexts and feature groups.
        |
        */

        'group_memberships' => env('TOGGL_MEMBERSHIPS_TABLE', 'group_memberships'),
        /*
        |--------------------------------------------------------------------------
        | Feature Snapshots Table
        |--------------------------------------------------------------------------
        |
        | This table stores feature snapshot metadata including who created or
        | restored them, when they were created, and the context they belong to.
        | Snapshots provide a mechanism for backing up and restoring feature
        | flag states.
        |
        */

        'feature_snapshots' => env('TOGGL_SNAPSHOTS_TABLE', 'feature_snapshots'),
        /*
        |--------------------------------------------------------------------------
        | Feature Snapshot Entries Table
        |--------------------------------------------------------------------------
        |
        | This table stores individual feature states captured at snapshot time,
        | including feature name, value, and activation status. Each entry
        | represents a single feature's state within a snapshot.
        |
        */

        'feature_snapshot_entries' => env('TOGGL_SNAPSHOT_ENTRIES_TABLE', 'feature_snapshot_entries'),
        /*
        |--------------------------------------------------------------------------
        | Feature Snapshot Events Table
        |--------------------------------------------------------------------------
        |
        | This table stores an audit trail of all snapshot operations (created,
        | restored, deleted) including who performed them and when. This provides
        | full accountability for snapshot-related actions.
        |
        */

        'feature_snapshot_events' => env('TOGGL_SNAPSHOT_EVENTS_TABLE', 'feature_snapshot_events'),
    ],
    /*
    |--------------------------------------------------------------------------
    | Polymorphic Key Mapping
    |--------------------------------------------------------------------------
    |
    | This option allows you to specify which column should be used as the
    | foreign key for each model in polymorphic relationships. This is
    | particularly useful when different models in your application use
    | different primary key column names, which is common in legacy systems
    | or when using ULIDs and UUIDs alongside traditional auto-incrementing
    | integer keys.
    |
    | For example, if your User model uses 'id' but your Organization model
    | uses 'ulid', you can map each model to its appropriate key column here.
    | Toggl will then use the correct column when storing foreign keys.
    |
    | Note: You may only configure either 'morphKeyMap' or 'enforceMorphKeyMap',
    | not both. Choose the non-enforced variant if you want to allow models
    | without explicit mappings to use their default primary key.
    |
    */

    'morphKeyMap' => [
        // App\Models\User::class => 'id',
        // App\Models\Organization::class => 'id',
    ],
    /*
    |--------------------------------------------------------------------------
    | Enforced Polymorphic Key Mapping
    |--------------------------------------------------------------------------
    |
    | This option works identically to 'morphKeyMap' above, but enables strict
    | enforcement of your key mappings. When configured, any model referenced
    | in a polymorphic relationship without an explicit mapping defined here
    | will throw a MorphKeyViolationException.
    |
    | This enforcement is useful in production environments where you want to
    | ensure all models participating in polymorphic relationships have been
    | explicitly configured, preventing potential bugs from unmapped models.
    |
    | Note: Only configure either 'morphKeyMap' or 'enforceMorphKeyMap'. Using
    | both simultaneously is not supported. Choose this enforced variant when
    | you want strict type safety for your polymorphic relationships.
    |
    */

    'enforceMorphKeyMap' => [
        // App\Models\User::class => 'id',
        // App\Models\Organization::class => 'id',
    ],
    /*
    |--------------------------------------------------------------------------
    | Feature Flag Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the feature flag stores for your application
    | as well as their drivers. You may even define multiple stores for the
    | same driver to group types of flags stored in your application.
    |
    | Supported drivers: "array", "database", "cache", "gate"
    |
    */

    'stores' => [
        /*
        |--------------------------------------------------------------------------
        | Array Store
        |--------------------------------------------------------------------------
        |
        | The array store keeps feature flags in memory for the duration of the
        | request. This is ideal for testing environments or when you need
        | temporary flags that don't persist between requests. Perfect for
        | unit tests and development environments.
        |
        */

        'array' => [
            'driver' => 'array',
        ],
        /*
        |--------------------------------------------------------------------------
        | Database Store
        |--------------------------------------------------------------------------
        |
        | The database store persists feature flags to your database, allowing
        | them to be managed dynamically without code deployments. This is the
        | recommended approach for production environments where feature flags
        | need to be toggled by administrators or automated systems.
        |
        | You may specify a custom database connection to isolate feature flag
        | data from your primary application database.
        |
        */

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CONNECTION', null),
        ],
        /*
        |--------------------------------------------------------------------------
        | Cache Store
        |--------------------------------------------------------------------------
        |
        | The cache store leverages your application's cache system to store
        | feature flags with configurable time-to-live. This provides excellent
        | performance for read-heavy workloads whilst maintaining reasonable
        | flexibility for updates. Consider using this with Redis or Memcached
        | for optimal performance in distributed systems.
        |
        */

        'cache' => [
            /*
            |----------------------------------------------------------------------
            | Cache Driver
            |----------------------------------------------------------------------
            |
            | This value determines which cache driver will be used to store your
            | feature flags. This should match one of the cache stores defined in
            | your cache configuration file.
            |
            */

            'driver' => 'cache',
            /*
            |----------------------------------------------------------------------
            | Cache Key Prefix
            |----------------------------------------------------------------------
            |
            | When utilising a cache store, you may wish to prefix your feature
            | flag keys to avoid collisions with other cached data. This prefix
            | is prepended to all feature flag keys stored in the cache.
            |
            */

            'prefix' => env('TOGGL_CACHE_PREFIX', 'features'),
            /*
            |----------------------------------------------------------------------
            | Time To Live
            |----------------------------------------------------------------------
            |
            | This value determines how many seconds feature flags should remain
            | in the cache before they are automatically removed. A longer TTL
            | improves performance but may delay updates. The default is 600
            | seconds (10 minutes).
            |
            */

            'ttl' => env('TOGGL_CACHE_TTL', 600),
        ],
        /*
        |--------------------------------------------------------------------------
        | Gate Store
        |--------------------------------------------------------------------------
        |
        | The gate store integrates with Laravel's authorization system, allowing
        | you to manage feature flags through Gates. This is useful when feature
        | access should be controlled by your existing authorization policies.
        |
        */

        'gate' => [
            /*
            |----------------------------------------------------------------------
            | Gate Driver
            |----------------------------------------------------------------------
            |
            | This value indicates that the Gate authorization system should be
            | used to evaluate feature flags. Feature checks will be delegated
            | to Laravel's Gate facade.
            |
            */

            'driver' => 'gate',
            /*
            |----------------------------------------------------------------------
            | Gate Name
            |----------------------------------------------------------------------
            |
            | This option specifies the gate name that will be used to check
            | feature flags. When checking a feature, Toggl will call the gate
            | with the feature name as the argument.
            |
            */

            'gate' => env('TOGGL_GATE_NAME', 'feature'),
            /*
            |----------------------------------------------------------------------
            | Authentication Guard
            |----------------------------------------------------------------------
            |
            | This option allows you to specify which authentication guard should
            | be used when evaluating gates. This is useful if you have multiple
            | authentication systems in your application.
            |
            */

            'guard' => env('TOGGL_GATE_GUARD'),
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | Feature Flag Strategies
    |--------------------------------------------------------------------------
    |
    | Here you may define the default strategy and all available strategies
    | for your feature flags. Each strategy determines how feature flags are
    | evaluated when checked. You may extend this with custom strategies by
    | implementing the Strategy contract.
    |
    */

    'strategies' => [
        /*
        |--------------------------------------------------------------------------
        | Default Strategy
        |--------------------------------------------------------------------------
        |
        | This option determines which strategy will be used as the default when
        | creating new feature flags without explicitly specifying a strategy.
        | The boolean strategy is recommended for most use cases as it provides
        | simple on/off toggling behaviour for features.
        |
        */

        'default' => 'boolean',
        /*
        |--------------------------------------------------------------------------
        | Available Strategies
        |--------------------------------------------------------------------------
        |
        | Here you may define all available strategies that can be assigned to
        | feature flags. Each strategy implements different evaluation logic:
        |
        | - boolean: Simple on/off toggle for features
        | - time_based: Activates features based on time windows
        | - percentage: Gradually rolls out features to a percentage of users
        | - scheduled: Activates features at specific scheduled times
        | - conditional: Evaluates custom conditions for feature activation
        |
        | You may add custom strategies by implementing the Strategy contract
        | and registering them here.
        |
        */

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
    | Time bombs are features that automatically expire after a specified
    | date. This is useful for temporary features, limited-time promotions,
    | or ensuring cleanup of experimental code. Here you may configure
    | whether time bombs are enabled and warning thresholds.
    |
    */

    'time_bombs' => [
        /*
        |--------------------------------------------------------------------------
        | Time Bombs Enabled
        |--------------------------------------------------------------------------
        |
        | This option determines whether the time bomb functionality is active.
        | When enabled, feature flags can be assigned expiration dates, after
        | which they will automatically deactivate. This helps prevent feature
        | flags from becoming permanent fixtures in your codebase and ensures
        | temporary features are properly cleaned up.
        |
        */

        'enabled' => env('FEATURE_FLAGS_TIME_BOMBS_ENABLED', true),
        /*
        |--------------------------------------------------------------------------
        | Warning Period
        |--------------------------------------------------------------------------
        |
        | This option specifies how many days before a feature flag's expiration
        | date warnings should be emitted. This gives your team advance notice
        | to either extend the feature, remove the flag, or make the feature
        | permanent. The default is 7 days, providing a full week of warning.
        |
        */

        'warn_days_before' => env('FEATURE_FLAGS_WARN_DAYS', 7),
    ],
    /*
    |--------------------------------------------------------------------------
    | Snapshot Configuration
    |--------------------------------------------------------------------------
    |
    | These options control the snapshot functionality for feature flags.
    | When enabled, you can create point-in-time snapshots of your feature
    | flags for backup and restore purposes.
    |
    */

    'snapshots' => [
        /*
        |--------------------------------------------------------------------------
        | Snapshots Enabled
        |--------------------------------------------------------------------------
        |
        | This option determines whether the snapshot functionality is active.
        | When disabled, all snapshot operations (create, restore, prune) will
        | be unavailable. This is useful for environments where snapshots aren't
        | needed or to reduce overhead.
        |
        */

        'enabled' => env('TOGGL_SNAPSHOTS_ENABLED', true),
        /*
        |--------------------------------------------------------------------------
        | Snapshot Storage Driver
        |--------------------------------------------------------------------------
        |
        | This option controls which driver is used to store feature flag
        | snapshots. By default, snapshots use the same driver as the main
        | feature store, but you can specify a different driver here for
        | optimized snapshot storage.
        |
        | The database driver provides complete historical tracking with dedicated
        | tables for snapshots, entries, and events. This enables full audit trails
        | and granular restore capabilities for compliance and debugging.
        |
        | The array and cache drivers store snapshots in memory or cache with basic
        | event tracking for the session/TTL duration. These are suitable for
        | temporary snapshots or development environments.
        |
        | Supported: null (use default store), SnapshotDriver::Database,
        |            SnapshotDriver::Array, SnapshotDriver::Cache
        |
        */

        'driver' => env('TOGGL_SNAPSHOT_DRIVER') ? SnapshotDriver::tryFrom(env('TOGGL_SNAPSHOT_DRIVER')) : null,
        /*
        |--------------------------------------------------------------------------
        | Snapshot Pruning Configuration
        |--------------------------------------------------------------------------
        |
        | Configure automatic pruning of old feature snapshots. When enabled, the
        | toggl:prune-snapshots artisan command will delete snapshots older than
        | the specified retention period. You can schedule this command to run
        | daily to keep your database clean.
        |
        | Example scheduler entry in app/Console/Kernel.php:
        |   $schedule->command('toggl:prune-snapshots')->daily();
        |
        */

        'pruning' => [
            /*
            |----------------------------------------------------------------------
            | Retention Days
            |----------------------------------------------------------------------
            |
            | The number of days to retain snapshots before they become eligible for
            | pruning. Snapshots older than this value will be deleted when running
            | the toggl:prune-snapshots command. Set to 0 to disable pruning.
            |
            */

            'retention_days' => env('TOGGL_SNAPSHOT_RETENTION_DAYS', 365),
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | Octane Reset Listener
    |--------------------------------------------------------------------------
    |
    | When set to true, Toggl will automatically register an event listener for
    | Laravel\Octane\Contracts\OperationTerminated to flush the feature flag
    | cache after each Octane operation (request, task, tick). This ensures
    | fresh feature evaluations in long-running processes. Disable this in
    | testing environments or when you need manual cache control.
    |
    */

    'register_octane_reset_listener' => env('TOGGL_REGISTER_OCTANE_RESET_LISTENER', true),
    /*
    |--------------------------------------------------------------------------
    | Events Configuration
    |--------------------------------------------------------------------------
    |
    | Configure event dispatching behavior for feature flag operations.
    |
    | enabled: When true, Toggl will dispatch FeatureActivated,
    |          FeatureDeactivated, and UnknownFeatureResolved events during
    |          feature flag operations. This enables event-driven workflows
    |          such as logging, notifications, or automated responses to
    |          feature state changes. Disable this if you don't need
    |          event-based functionality to reduce overhead.
    |
    | Events that will fire when features are checked:
    | - \Cline\Toggl\Events\FeatureActivated
    | - \Cline\Toggl\Events\FeatureDeactivated
    | - \Cline\Toggl\Events\UnknownFeatureResolved
    |
    */

    'events' => [
        'enabled' => env('TOGGL_EVENTS_ENABLED', true),
    ],
    /*
    |--------------------------------------------------------------------------
    | Display Feature Names in Exceptions
    |--------------------------------------------------------------------------
    |
    | When set to true, feature names are included in exception messages when
    | prerequisite checks fail. This can be helpful for debugging but could be
    | considered an information leak in some security contexts, as it reveals
    | which features protect specific functionality. The default is false for
    | optimal security.
    |
    | When false: "Cannot activate feature: missing prerequisites"
    | When true:  "Cannot activate 'admin-panel': missing prerequisites [superuser]"
    |
    */

    'display_feature_in_exception' => env('TOGGL_DISPLAY_FEATURE_IN_EXCEPTION', false),
    /*
    |--------------------------------------------------------------------------
    | Model Observers Configuration
    |--------------------------------------------------------------------------
    |
    | Configure Eloquent model observer behavior for feature flag operations.
    |
    | enabled: When true, Toggl's Eloquent model observers will be registered
    |          to react to database model changes. These observers handle tasks
    |          like cache invalidation and maintaining data consistency. Disable
    |          this if you're managing these concerns through other means or want
    |          to reduce overhead in high-throughput scenarios.
    |
    */

    'observers' => [
        'enabled' => env('TOGGL_OBSERVERS_ENABLED', true),
    ],
    /*
    |--------------------------------------------------------------------------
    | Hierarchical Feature Resolution
    |--------------------------------------------------------------------------
    |
    | These options control how Toggl handles scoped feature resolution.
    | Hierarchical features allow you to activate features at organizational
    | levels (company, division, org, team) that automatically apply to all
    | matching contexts without duplicating database records.
    |
    */

    'scope' => [
        /*
        |--------------------------------------------------------------------------
        | Enable Scope by Default
        |--------------------------------------------------------------------------
        |
        | When set to true, Toggl will automatically use scoped feature
        | resolution for all contexts that implement TogglContextable with
        | scope support. This means features activated
        | at organizational levels will automatically apply to matching contexts.
        |
        | When false, you must explicitly call withScope() to enable
        | scoped resolution:
        |   Toggl::for($user)->withScope()->active('premium')
        |
        | Set this to true for applications where scoped features are the
        | norm, or false when you want explicit control over scope usage.
        |
        */

        'enabled' => env('TOGGL_SCOPES_ENABLED', false),
    ],
    /*
    |--------------------------------------------------------------------------
    | Feature Group Storage
    |--------------------------------------------------------------------------
    |
    | This option controls how feature groups are stored and managed. When
    | using "array" storage, groups are defined in this configuration file.
    | When using "database" storage, groups are persisted to the database,
    | allowing for dynamic management without code changes.
    |
    | Supported: "array", "database"
    |
    */

    'group_storage' => env('FEATURE_GROUP_STORAGE', 'array'),
    /*
    |--------------------------------------------------------------------------
    | Feature Groups
    |--------------------------------------------------------------------------
    |
    | Feature groups allow you to organise related features together and
    | activate or deactivate them as a cohesive unit. This is particularly
    | useful for managing experimental features, beta programmes, or feature
    | sets tied to specific user segments.
    |
    | When using array storage, define your groups here with their associated
    | features and descriptions. When using database storage, manage groups
    | via the fluent API or your application's administrative interface.
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
    | Some features may depend on other features being active in order to
    | function correctly. Define those dependencies here as an array where
    | the key is the feature name and the value is an array of required
    | feature names. When a feature is checked, its dependencies will be
    | automatically verified to ensure system consistency.
    |
    */

    'dependencies' => [
        // 'premium-dashboard' => ['user-authentication', 'premium-subscription'],
    ],
    /*
    |--------------------------------------------------------------------------
    | Migration Configuration
    |--------------------------------------------------------------------------
    |
    | These options configure Toggl's migration tools for importing data from
    | other popular Laravel feature flag packages. Toggl provides migration
    | support for Laravel Pennant and YLSIdeas Feature Flags. The migrator
    | will read from your existing tables and import flags into Toggl.
    |
    */

    'migrators' => [
        /*
        |--------------------------------------------------------------------------
        | Laravel Pennant Migrator
        |--------------------------------------------------------------------------
        |
        | This configuration enables migration from Laravel Pennant to Toggl.
        | Specify the table name where Pennant stores its feature flags and
        | optionally a different database connection if Pennant uses a separate
        | database. The migrator will read Pennant's feature data and import
        | it into Toggl's format, preserving flag states and configurations.
        |
        */

        'pennant' => [
            /*
            |----------------------------------------------------------------------
            | Pennant Features Table
            |----------------------------------------------------------------------
            |
            | This value specifies the table name where Laravel Pennant stores
            | its feature flag data. The migrator will read from this table when
            | importing features into Toggl.
            |
            */

            'table' => env('TOGGL_PENNANT_TABLE', 'features'),
            /*
            |----------------------------------------------------------------------
            | Pennant Database Connection
            |----------------------------------------------------------------------
            |
            | If your Pennant features are stored in a different database, you
            | may specify the connection name here. Leave this null to use the
            | default database connection.
            |
            */

            'connection' => env('TOGGL_PENNANT_CONNECTION'),
        ],
        /*
        |--------------------------------------------------------------------------
        | YlsIdeas Feature Flags Migrator
        |--------------------------------------------------------------------------
        |
        | This configuration enables migration from YlsIdeas Feature Flags to
        | Toggl. Specify the table name, the field used to determine if a flag
        | is active, and optionally a custom database connection. The migrator
        | will transform YlsIdeas flag data into Toggl's feature flag structure,
        | maintaining the activation state of each feature.
        |
        */

        'ylsideas' => [
            /*
            |----------------------------------------------------------------------
            | YlsIdeas Features Table
            |----------------------------------------------------------------------
            |
            | This value specifies the table name where YlsIdeas stores its
            | feature flag data. The migrator will read from this table when
            | importing features into Toggl.
            |
            */

            'table' => env('TOGGL_YLSIDEAS_TABLE', 'features'),
            /*
            |----------------------------------------------------------------------
            | YlsIdeas Active Field
            |----------------------------------------------------------------------
            |
            | This value specifies which field in the YlsIdeas table indicates
            | whether a feature is active. Typically, this is a timestamp field
            | where null means inactive and a timestamp means active.
            |
            */

            'field' => env('TOGGL_YLSIDEAS_FIELD', 'active_at'),
            /*
            |----------------------------------------------------------------------
            | YlsIdeas Database Connection
            |----------------------------------------------------------------------
            |
            | If your YlsIdeas features are stored in a different database, you
            | may specify the connection name here. Leave this null to use the
            | default database connection.
            |
            */

            'connection' => env('TOGGL_YLSIDEAS_CONNECTION'),
        ],
    ],
];

// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
// Here endeth thy configuration, noble developer!                            //
// Beyond: code so wretched, even wyrms learned the scribing arts.            //
// Forsooth, they but penned "// TODO: remedy ere long"                       //
// Three realms have fallen since...                                          //
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
//                                                  .~))>>                    //
//                                                 .~)>>                      //
//                                               .~))))>>>                    //
//                                             .~))>>             ___         //
//                                           .~))>>)))>>      .-~))>>         //
//                                         .~)))))>>       .-~))>>)>          //
//                                       .~)))>>))))>>  .-~)>>)>              //
//                   )                 .~))>>))))>>  .-~)))))>>)>             //
//                ( )@@*)             //)>))))))  .-~))))>>)>                 //
//              ).@(@@               //))>>))) .-~))>>)))))>>)>               //
//            (( @.@).              //))))) .-~)>>)))))>>)>                   //
//          ))  )@@*.@@ )          //)>))) //))))))>>))))>>)>                 //
//       ((  ((@@@.@@             |/))))) //)))))>>)))>>)>                    //
//      )) @@*. )@@ )   (\_(\-\b  |))>)) //)))>>)))))))>>)>                   //
//    (( @@@(.@(@ .    _/`-`  ~|b |>))) //)>>)))))))>>)>                      //
//     )* @@@ )@*     (@)  (@) /\b|))) //))))))>>))))>>                       //
//   (( @. )@( @ .   _/  /    /  \b)) //))>>)))))>>>_._                       //
//    )@@ (@@*)@@.  (6///6)- / ^  \b)//))))))>>)))>>   ~~-.                   //
// ( @jgs@@. @@@.*@_ VvvvvV//  ^  \b/)>>))))>>      _.     `bb                //
//  ((@@ @@@*.(@@ . - | o |' \ (  ^   \b)))>>        .'       b`,             //
//   ((@@).*@@ )@ )   \^^^/  ((   ^  ~)_        \  /           b `,           //
//     (@@. (@@ ).     `-'   (((   ^    `\ \ \ \ \|             b  `.         //
//       (*.@*              / ((((        \| | |  \       .       b `.        //
//                         / / (((((  \    \ /  _.-~\     Y,      b  ;        //
//                        / / / (((((( \    \.-~   _.`" _.-~`,    b  ;        //
//                       /   /   `(((((()    )    (((((~      `,  b  ;        //
//                     _/  _/      `"""/   /'                  ; b   ;        //
//                 _.-~_.-~           /  /'              _.'~bb _.'         //
//               ((((~~              / /'              _.'~bb.--~             //
//                                  ((((          __.-~bb.-~                  //
//                                              .'  b .~~                     //
//                                              :bb ,'                        //
//                                              ~~~~                          //
// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ //
