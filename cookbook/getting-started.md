# Getting Started

Welcome to Toggl, a powerful Laravel feature flag package with a conductor-based API for enterprise applications. This guide will help you install, configure, and create your first feature flag.

## Installation

Install Toggl via Composer:

```bash
composer require cline/toggl
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=toggl-config
```

This creates `config/toggl.php` with the following structure:

```php
return [
    'default' => env('FEATURE_FLAGS_STORE', 'database'),

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
];
```

### Driver Selection

**Array Driver** (in-memory)
- Best for: Testing, temporary flags, development
- Data persists only during the current request
- No database required

**Database Driver** (recommended for production)
- Best for: Production environments, persistent flags
- Data stored in database table
- Survives application restarts

To use the database driver, set your `.env`:

```env
FEATURE_FLAGS_STORE=database
```

## Database Setup

If using the database driver, publish and run the migrations:

```bash
php artisan vendor:publish --tag=toggl-migrations
php artisan migrate
```

This creates a `features` table with the following structure:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `name` | string | Feature flag name |
| `context_type` | string | Polymorphic type (e.g., App\Models\User) |
| `context_id` | bigint/ulid/uuid | Polymorphic ID (type configurable) |
| `value` | text | Feature value (boolean, string, array, etc.) |
| `strategy` | string | Strategy class name (optional) |
| `expires_at` | timestamp | Time bomb expiration (optional) |
| `metadata` | json | Strategy-specific configuration (optional) |

The table uses polymorphic columns (`context_type`, `context_id`) to support different model types and a unique constraint on `(name, context_type, context_id)` to ensure each feature flag can only have one value per context.

## Your First Feature Flag

Let's create a simple feature flag to enable a new dashboard for admin users.

### 1. Define the Feature

In your `AppServiceProvider` or a dedicated `FeatureServiceProvider`:

```php
use Cline\Toggl\Toggl;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Toggl::define('new-dashboard', function ($user) {
            return $user?->isAdmin() ?? false;
        });
    }
}
```

### 2. Check the Feature

In your controller:

```php
use Cline\Toggl\Toggl;

class DashboardController extends Controller
{
    public function index()
    {
        if (Toggl::active('new-dashboard')) {
            return view('dashboard.new');
        }

        return view('dashboard.legacy');
    }
}
```

### 3. Use in Blade Templates

```blade
@feature('new-dashboard')
    <div class="new-dashboard">
        <!-- New dashboard content -->
    </div>
@else
    <div class="legacy-dashboard">
        <!-- Legacy dashboard content -->
    </div>
@endfeature
```

## Understanding Contexts

Feature flags in Toggl are **context-aware**. A context represents the context in which a feature is evaluated - typically a user, team, or organization.

### Default Context

By default, Toggl uses the currently authenticated user as the context:

```php
// These are equivalent when a user is authenticated
Toggl::active('new-dashboard');
Toggl::for(auth()->user())->active('new-dashboard');
```

### Explicit Contexts

You can explicitly specify a context:

```php
// Check for specific user
$user = User::find(123);
if (Toggl::for($user)->active('premium-features')) {
    // ...
}

// Check for team
$team = Team::find(456);
if (Toggl::for($team)->active('team-analytics')) {
    // ...
}

// String context
if (Toggl::for('admin')->active('debug-mode')) {
    // ...
}

// Numeric context
if (Toggl::for(999)->active('special-offer')) {
    // ...
}
```

### Custom Context Resolvers

You can customize how contexts are resolved by defining a context resolver in your service provider:

```php
use Cline\Toggl\Toggl;

Toggl::resolveContextUsing(function ($driver) {
    // Return the current tenant instead of user
    return Tenant::current();
});
```

## Feature Flag Lifecycle

Here's a typical lifecycle for a feature flag:

1. **Define** - Create the feature with a resolver
   ```php
   Toggl::define('new-api', fn($user) => $user->isAdmin());
   ```

2. **Test** - Test with specific users or contexts
   ```php
   Toggl::for($betaTester)->activate('new-api');
   ```

3. **Rollout** - Gradually enable for more users
   ```php
   // Enable for 25% of users
   Toggl::define('new-api', fn($user) => crc32($user->id) % 100 < 25);
   ```

4. **Full Launch** - Enable for everyone
   ```php
   Toggl::activateForEveryone('new-api');
   ```

5. **Cleanup** - Remove flag from code and database
   ```php
   Toggl::purge('new-api');
   ```

## Common Patterns

### Simple Boolean Flag

```php
// Always on
Toggl::define('maintenance-mode', true);

// Always off
Toggl::define('beta-feature', false);
```

### User-Based Flag

```php
Toggl::define('premium-dashboard', function ($user) {
    return $user->subscription?->plan === 'premium';
});
```

### Team-Based Flag

```php
Toggl::define('team-analytics', function ($team) {
    return $team->plan === 'enterprise';
});
```

### Email-Based Flag

```php
Toggl::define('early-access', function ($user) {
    return in_array($user->email, [
        'alice@example.com',
        'bob@example.com',
    ]);
});
```

### Environment-Based Flag

```php
Toggl::define('debug-toolbar', function () {
    return app()->environment('local', 'staging');
});
```

## Best Practices

1. **Naming Conventions**
   - Use lowercase with hyphens: `new-dashboard`, `premium-features`
   - Be descriptive: `ai-chat-assistant` instead of `feature-1`
   - Prefix by area: `api-v2-endpoints`, `ui-dark-mode`

2. **Organization**
   - Group related flags in a dedicated service provider
   - Document flags in comments explaining purpose and rollout plan
   - Remove flags after full rollout

3. **Testing**
   - Always test both active and inactive states
   - Use the array driver for unit tests
   - Test context variations (different users, teams, etc.)

4. **Performance**
   - Feature checks are cached during request lifecycle
   - Database driver is optimized with proper indexing
   - Avoid complex resolvers - keep logic simple

## Next Steps

Now that you have Toggl installed and understand the basics, explore more advanced features:

- **[Basic Usage](basic-usage.md)** - Learn all core operations
- **[Strategies](strategies.md)** - Time-based, percentage, and conditional strategies
- **[Time Bombs](time-bombs.md)** - Auto-expiring features
- **[Feature Groups](feature-groups.md)** - Manage related flags together
- **[Dependencies](dependencies.md)** - Create feature requirements
- **[Variants](variants.md)** - A/B testing with weighted distribution
- **[Advanced Usage](advanced-usage.md)** - Events, custom drivers, and more
