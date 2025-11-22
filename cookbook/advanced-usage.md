# Advanced Usage

## Events

Toggl dispatches events that you can listen to for logging, analytics, or custom behavior.

### UnknownFeatureResolved

Triggered when an undefined feature is checked:

```php
use Cline\Toggl\Events\UnknownFeatureResolved;

Event::listen(UnknownFeatureResolved::class, function ($event) {
    Log::warning('Unknown feature accessed', [
        'feature' => $event->feature,
        'context' => $event->context,
    ]);
});
```

### Custom Event Listeners

```php
// In EventServiceProvider
protected $listen = [
    UnknownFeatureResolved::class => [
        LogUnknownToggl::class,
        NotifyTeam::class,
    ],
];
```

## Middleware

### Custom Middleware

```php
namespace App\Http\Middleware;

use Cline\Toggl\Toggl;
use Closure;

class RequireBetaAccess
{
    public function handle($request, Closure $next)
    {
        // Works with strings or enums
        if (! Toggl::for($request->user())->active('beta-access')) {
            abort(403, 'Beta access required');
        }

        return $next($request);
    }
}
```

Using an enum for type safety:

```php
namespace App\Http\Middleware;

use App\Enums\FeatureFlag;
use Cline\Toggl\Toggl;
use Closure;

class RequireBetaAccess
{
    public function handle($request, Closure $next)
    {
        if (! Toggl::for($request->user())->active(FeatureFlag::BetaAccess)) {
            abort(403, 'Beta access required');
        }

        return $next($request);
    }
}
```

You can then register this middleware in your routes:

```php
Route::middleware([RequireBetaAccess::class])->group(function () {
    Route::get('/beta/dashboard', [BetaController::class, 'dashboard']);
});
```

## Custom Drivers

### Create Custom Driver

```php
namespace App\Drivers;

use Cline\Toggl\Contracts\Driver;

class RedisDriver implements Driver
{
    public function __construct(
        protected \Illuminate\Redis\RedisManager $redis
    ) {}

    public function get(string $feature, mixed $context): mixed
    {
        $key = "features:{$feature}:{$this->serializeContext($context)}";
        return $this->redis->get($key);
    }

    public function set(string $feature, mixed $context, mixed $value): void
    {
        $key = "features:{$feature}:{$this->serializeContext($context)}";
        $this->redis->set($key, $value);
    }

    // Implement other Driver methods...
}
```

### Register Custom Driver

```php
// In AppServiceProvider
use Cline\Toggl\Toggl;
use App\Drivers\RedisDriver;

public function boot(): void
{
    Toggl::extend('redis', function ($app, $config) {
        return new RedisDriver($app->make('redis'));
    });
}
```

### Use Custom Driver

```php
// config/toggl.php
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
    ],
],
```

## Caching Strategies

### Eager Loading

```php
// Load all features at once
$user = User::with('features')->find(1);

// Access without additional queries
Toggl::for($user)->active('feature-1');
Toggl::for($user)->active('feature-2');
```

### Cache Warming

```php
// Warm cache for common features
foreach ($users as $user) {
    Toggl::for($user)->load([
        'premium-access',
        'beta-features',
        'advanced-analytics',
    ]);
}

// With enums for type safety
foreach ($users as $user) {
    Toggl::for($user)->load([
        FeatureFlag::PremiumAccess,
        FeatureFlag::BetaFeatures,
        FeatureFlag::AdvancedAnalytics,
    ]);
}
```

### Manual Cache Control

```php
// Flush all cached feature states
Toggl::flushCache();

// Forget specific feature
Toggl::forget('feature-name');
```

## Testing

### Pest Helpers

```php
use Cline\Toggl\Toggl;

test('premium features require subscription', function () {
    $user = User::factory()->create(['subscription' => 'basic']);
    
    Toggl::define('premium-support', fn($u) => $u->subscription === 'premium');
    
    expect(Toggl::for($user)->active('premium-support'))->toBeFalse();
    
    $user->subscription = 'premium';
    $user->save();
    
    Toggl::flushCache(); // Clear cached results
    
    expect(Toggl::for($user)->active('premium-support'))->toBeTrue();
});
```

### Activate Features in Tests

```php
beforeEach(function () {
    Toggl::activateForEveryone([
        'testing-mode',
        'debug-toolbar',
    ]);
});

test('feature is active in tests', function () {
    expect(Toggl::active('testing-mode'))->toBeTrue();
});

// With enums
beforeEach(function () {
    Toggl::activateForEveryone([
        FeatureFlag::TestingMode,
        FeatureFlag::DebugToolbar,
    ]);
});

test('feature is active in tests', function () {
    expect(Toggl::active(FeatureFlag::TestingMode))->toBeTrue();
});
```

### Fake Features

```php
test('can fake features', function () {
    Toggl::define('feature-1', false);
    Toggl::define('feature-2', true);
    
    // Override for test
    Toggl::activate('feature-1');
    
    expect(Toggl::active('feature-1'))->toBeTrue();
});
```

## Scheduled Tasks

### Monitor Feature Usage

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->call(function () {
        $features = Toggl::stored();
        
        foreach ($features as $feature) {
            Metrics::gauge('feature.usage', 1, [
                'feature' => $feature,
                'active' => Toggl::active($feature) ? 'true' : 'false',
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
        $expiring = Toggl::expiringSoon(days: 7);
        
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
   Toggl::for($user)->load(['f1', 'f2', 'f3']);
   
   // ❌ Avoid - multiple queries
   Toggl::for($user)->active('f1');
   Toggl::for($user)->active('f2');
   Toggl::for($user)->active('f3');
   ```

3. **Cache resolver results**
   ```php
   Toggl::define('expensive-check', function ($user) use ($cache) {
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
   Toggl::define('rollout')
       ->strategy(new PercentageStrategy(25));
   
   // ❌ Slower - DB lookup per check
   Toggl::define('rollout', fn($u) => 
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
       Toggl::define('feature-1', ...);
       Toggl::define('feature-2', ...);
   }
   ```

2. **Document feature purpose**
   ```php
   // Purpose: Enable new checkout flow for Q1 2025 launch
   // Owner: Team Ecommerce
   // Rollout: 10% → 50% → 100% over 2 weeks
   Toggl::define('new-checkout')
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
