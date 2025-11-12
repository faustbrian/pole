# Advanced Usage

## Events

Pole dispatches events that you can listen to for logging, analytics, or custom behavior.

### UnknownFeatureResolved

Triggered when an undefined feature is checked:

```php
use Cline\Pole\Events\UnknownFeatureResolved;

Event::listen(UnknownFeatureResolved::class, function ($event) {
    Log::warning('Unknown feature accessed', [
        'feature' => $event->feature,
        'scope' => $event->scope,
    ]);
});
```

### Custom Event Listeners

```php
// In EventServiceProvider
protected $listen = [
    UnknownFeatureResolved::class => [
        LogUnknownFeature::class,
        NotifyTeam::class,
    ],
];
```

## Middleware

### Require Features

```php
use Cline\Pole\Middleware\EnsureFeaturesAreActive;

Route::middleware([
    EnsureFeaturesAreActive::using('premium-access'),
])->group(function () {
    Route::get('/premium/dashboard', [PremiumController::class, 'dashboard']);
});

// Multiple features
Route::middleware([
    EnsureFeaturesAreActive::using(['feature-1', 'feature-2']),
])->group(function () {
    // Routes
});
```

### Custom Middleware

```php
namespace App\Http\Middleware;

use Cline\Pole\Feature;
use Closure;

class RequireBetaAccess
{
    public function handle($request, Closure $next)
    {
        if (! Feature::for($request->user())->active('beta-access')) {
            abort(403, 'Beta access required');
        }

        return $next($request);
    }
}
```

## Commands

### Purge Expired Features

```php
php artisan feature:purge

// Options
php artisan feature:purge --dry-run  // Preview what would be deleted
php artisan feature:purge --force    // Skip confirmation
```

### List Features

```php
php artisan feature:list

// Output:
// ┌─────────────────────┬────────┬───────────┐
// │ Feature             │ Status │ Expires   │
// ├─────────────────────┼────────┼───────────┤
// │ new-dashboard       │ ✓      │ -         │
// │ beta-features       │ ✗      │ -         │
// │ promo-sale          │ ✓      │ 2025-12-31│
// └─────────────────────┴────────┴───────────┘
```

### Activate/Deactivate via CLI

```php
php artisan feature:activate new-dashboard --everyone
php artisan feature:activate premium-support --user=123
php artisan feature:deactivate beta-test --everyone
```

## Custom Drivers

### Create Custom Driver

```php
namespace App\Drivers;

use Cline\Pole\Contracts\Driver;

class RedisDriver implements Driver
{
    public function __construct(
        protected \Illuminate\Redis\RedisManager $redis
    ) {}

    public function get(string $feature, mixed $scope): mixed
    {
        $key = "features:{$feature}:{$this->serializeScope($scope)}";
        return $this->redis->get($key);
    }

    public function set(string $feature, mixed $scope, mixed $value): void
    {
        $key = "features:{$feature}:{$this->serializeScope($scope)}";
        $this->redis->set($key, $value);
    }

    // Implement other Driver methods...
}
```

### Register Custom Driver

```php
// In AppServiceProvider
use Cline\Pole\Feature;
use App\Drivers\RedisDriver;

public function boot(): void
{
    Feature::extend('redis', function ($app, $config) {
        return new RedisDriver($app->make('redis'));
    });
}
```

### Use Custom Driver

```php
// config/pole.php
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
    ],
],
```

## Feature Discovery

### Auto-discover Feature Classes

```php
// In AppServiceProvider
use Cline\Pole\Feature;

public function boot(): void
{
    Feature::discover(
        namespace: 'App\\Features',
        path: app_path('Features')
    );
}
```

This automatically registers all feature classes in `app/Features/`:

```php
namespace App\Features;

class NewDashboard
{
    public function resolve(mixed $scope): bool
    {
        return $scope?->isAdmin() ?? false;
    }
}
```

## Caching Strategies

### Eager Loading

```php
// Load all features at once
$user = User::with('features')->find(1);

// Access without additional queries
Feature::for($user)->active('feature-1');
Feature::for($user)->active('feature-2');
```

### Cache Warming

```php
// Warm cache for common features
foreach ($users as $user) {
    Feature::for($user)->load([
        'premium-access',
        'beta-features',
        'advanced-analytics',
    ]);
}
```

### Manual Cache Control

```php
// Flush all cached feature states
Feature::flushCache();

// Forget specific feature
Feature::forget('feature-name');
```

## Testing

### Pest Helpers

```php
use Cline\Pole\Feature;

test('premium features require subscription', function () {
    $user = User::factory()->create(['subscription' => 'basic']);
    
    Feature::define('premium-support', fn($u) => $u->subscription === 'premium');
    
    expect(Feature::for($user)->active('premium-support'))->toBeFalse();
    
    $user->subscription = 'premium';
    $user->save();
    
    Feature::flushCache(); // Clear cached results
    
    expect(Feature::for($user)->active('premium-support'))->toBeTrue();
});
```

### Activate Features in Tests

```php
beforeEach(function () {
    Feature::activateForEveryone([
        'testing-mode',
        'debug-toolbar',
    ]);
});

test('feature is active in tests', function () {
    expect(Feature::active('testing-mode'))->toBeTrue();
});
```

### Fake Features

```php
test('can fake features', function () {
    Feature::define('feature-1', false);
    Feature::define('feature-2', true);
    
    // Override for test
    Feature::activate('feature-1');
    
    expect(Feature::active('feature-1'))->toBeTrue();
});
```

## Scheduled Tasks

### Auto-cleanup Expired Features

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Purge expired features daily
    $schedule->command('feature:purge --force')->daily();
}
```

### Monitor Feature Usage

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->call(function () {
        $features = Feature::stored();
        
        foreach ($features as $feature) {
            Metrics::gauge('feature.usage', 1, [
                'feature' => $feature,
                'active' => Feature::active($feature) ? 'true' : 'false',
            ]);
        }
    })->everyFiveMinutes();
}
```

### Warn About Expiring Features

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->call(function () {
        $expiring = Feature::expiringSoon(days: 7);
        
        if (count($expiring) > 0) {
            Notification::route('slack', config('slack.webhook'))
                ->notify(new FeatureExpiringNotification($expiring));
        }
    })->daily();
}
```

## Performance Tips

1. **Use array driver for ephemeral features**
   ```php
   // Fast, in-memory, no persistence
   'default' => 'array',
   ```

2. **Batch load features**
   ```php
   // ✅ Good - one query
   Feature::for($user)->load(['f1', 'f2', 'f3']);
   
   // ❌ Avoid - multiple queries
   Feature::for($user)->active('f1');
   Feature::for($user)->active('f2');
   Feature::for($user)->active('f3');
   ```

3. **Cache resolver results**
   ```php
   Feature::define('expensive-check', function ($user) use ($cache) {
       return $cache->remember(
           "feature-check-{$user->id}",
           3600,
           fn() => $this->expensiveCalculation($user)
       );
   });
   ```

4. **Use percentage strategy over database**
   ```php
   // ✅ Fast - no DB lookup
   Feature::define('rollout')
       ->strategy(new PercentageStrategy(25));
   
   // ❌ Slower - DB lookup per check
   Feature::define('rollout', fn($u) => 
       DB::table('rollouts')->where('user_id', $u->id)->exists()
   );
   ```

## Best Practices

1. **Centralize feature definitions**
   ```php
   // app/Providers/FeatureServiceProvider.php
   public function boot(): void
   {
       $this->defineAllFeatures();
   }
   
   private function defineAllFeatures(): void
   {
       // All features in one place
       Feature::define('feature-1', ...);
       Feature::define('feature-2', ...);
   }
   ```

2. **Document feature purpose**
   ```php
   // Purpose: Enable new checkout flow for Q1 2025 launch
   // Owner: Team Ecommerce
   // Rollout: 10% → 50% → 100% over 2 weeks
   Feature::define('new-checkout')
       ->strategy(new PercentageStrategy(10))
       ->expiresAfter(weeks: 2);
   ```

3. **Clean up old features**
   ```php
   // Schedule regular audits
   protected function schedule(Schedule $schedule): void
   {
       $schedule->command('feature:audit')->monthly();
   }
   ```

4. **Monitor feature flags**
   ```php
   Event::listen(UnknownFeatureResolved::class, function ($event) {
       // Alert if production code references undefined feature
       if (app()->environment('production')) {
           Sentry::captureMessage("Unknown feature: {$event->feature}");
       }
   });
   ```

## Next Steps

- [Getting Started](getting-started.md) - Installation and setup
- [Basic Usage](basic-usage.md) - Core operations
- [Strategies](strategies.md) - Resolution strategies
