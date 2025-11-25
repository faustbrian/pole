# Strategies

Toggl supports multiple resolution strategies for feature flags, allowing you to control when and how features are activated.

## Boolean Strategy

The simplest strategy - always returns the same value.

```php
use Cline\Toggl\Toggl;

// Always active
Toggl::define('dark-mode', true);

// Always inactive
Toggl::define('beta-features', false);

// Conditional based on context
Toggl::define('admin-panel', fn($user) => $user->isAdmin());
```

## Time-Based Strategy

Activate features between specific dates/times.

```php
use Cline\Toggl\Toggl;
use Cline\Toggl\Strategies\TimeBasedStrategy;

Toggl::define('holiday-theme')
    ->strategy(new TimeBasedStrategy(
        start: now()->startOfMonth(),
        end: now()->endOfMonth()
    ));

// Or use resolver with time logic
Toggl::define('business-hours', function ($user) {
    $hour = now()->hour;
    return $hour >= 9 && $hour < 17;
});
```

## Percentage Strategy

Gradually roll out features to a percentage of users using consistent hashing.

```php
use Cline\Toggl\Strategies\PercentageStrategy;

// 25% of users
Toggl::define('new-checkout')
    ->strategy(new PercentageStrategy(25));

// Same user always gets same result (sticky)
Toggl::for($user)->active('new-checkout'); // Consistent per user
```

The percentage is calculated using CRC32 hash of the context, ensuring:
- Same user always gets same result
- Distribution matches the specified percentage
- No database lookups needed

## Scheduled Strategy

Schedule features to activate and/or deactivate at specific times.

```php
use Cline\Toggl\Strategies\ScheduledStrategy;

// Feature activates on Black Friday and deactivates after Cyber Monday
Toggl::define('black-friday-sale')
    ->strategy(new ScheduledStrategy(
        activateAt: now()->parse('2025-11-29 00:00:00'),
        deactivateAt: now()->parse('2025-12-02 23:59:59')
    ));

// Feature is already active, deactivates at end of month
Toggl::define('limited-time-offer')
    ->strategy(new ScheduledStrategy(
        deactivateAt: now()->endOfMonth()
    ));

// Feature activates next Monday, never deactivates
Toggl::define('new-feature-launch')
    ->strategy(new ScheduledStrategy(
        activateAt: now()->next('Monday')
    ));

// Weekend feature using custom logic
Toggl::define('weekend-bonus', function () {
    return now()->isWeekend();
});
```

**Parameters:**
- `activateAt` (optional): When the feature becomes active. If null, active immediately.
- `deactivateAt` (optional): When the feature becomes inactive. If null, never deactivates.

## Conditional Strategy

Custom logic for complex scenarios.

```php
use Cline\Toggl\Strategies\ConditionalStrategy;

// Multi-factor decision
Toggl::define('premium-feature')
    ->strategy(new ConditionalStrategy(function ($user) {
        return $user->subscription === 'premium' 
            && $user->email_verified 
            && !$user->suspended;
    }));

// Environment-based
Toggl::define('debug-toolbar', function () {
    return app()->environment('local', 'staging');
});

// Complex business logic
Toggl::define('bulk-discount', function ($order) {
    return $order->items->count() >= 10 
        && $order->total >= 1000 
        && $order->customer->tier === 'wholesale';
});
```

## Combining Strategies

Use dependencies and time bombs to combine strategy behaviors:

```php
// Percentage rollout that expires
Toggl::define('experimental-ui')
    ->strategy(new PercentageStrategy(10))
    ->expiresAt(now()->addWeeks(2));

// Feature that requires another feature
Toggl::define('advanced-reports')
    ->requires('basic-analytics')
    ->strategy(new ConditionalStrategy(fn($user) => $user->isPremium()));
```

## Custom Strategies

Implement the `Strategy` contract:

```php
namespace App\Strategies;

use Cline\Toggl\Contracts\Strategy;

class RegionStrategy implements Strategy
{
    public function __construct(private array $allowedRegions) {}

    public function resolve(mixed $context): bool
    {
        return in_array($context?->region, $this->allowedRegions);
    }
}

// Use it
Toggl::define('eu-features')
    ->strategy(new RegionStrategy(['EU', 'UK']));
```

## Next Steps

- [Time Bombs](time-bombs.md) - Auto-expiring features
- [Dependencies](dependencies.md) - Feature requirements
- [Variants](variants.md) - A/B testing
