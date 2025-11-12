# Strategies

Pole supports multiple resolution strategies for feature flags, allowing you to control when and how features are activated.

## Boolean Strategy

The simplest strategy - always returns the same value.

```php
use Cline\Pole\Feature;

// Always active
Feature::define('dark-mode', true);

// Always inactive
Feature::define('beta-features', false);

// Conditional based on scope
Feature::define('admin-panel', fn($user) => $user->isAdmin());
```

## Time-Based Strategy

Activate features between specific dates/times.

```php
use Cline\Pole\Feature;
use Cline\Pole\Strategies\TimeBasedStrategy;

Feature::define('holiday-theme')
    ->strategy(new TimeBasedStrategy(
        start: now()->startOfMonth(),
        end: now()->endOfMonth()
    ));

// Or use resolver with time logic
Feature::define('business-hours', function ($user) {
    $hour = now()->hour;
    return $hour >= 9 && $hour < 17;
});
```

## Percentage Strategy

Gradually roll out features to a percentage of users using consistent hashing.

```php
use Cline\Pole\Strategies\PercentageStrategy;

// 25% of users
Feature::define('new-checkout')
    ->strategy(new PercentageStrategy(25));

// Same user always gets same result (sticky)
Feature::for($user)->active('new-checkout'); // Consistent per user
```

The percentage is calculated using CRC32 hash of the scope, ensuring:
- Same user always gets same result
- Distribution matches the specified percentage
- No database lookups needed

## Scheduled Strategy

Activate features at specific times or on schedules.

```php
use Cline\Pole\Strategies\ScheduledStrategy;

// Black Friday sale
Feature::define('black-friday-sale')
    ->strategy(new ScheduledStrategy(
        start: '2025-11-29 00:00:00',
        end: '2025-12-02 23:59:59'
    ));

// Weekend feature
Feature::define('weekend-bonus', function () {
    return now()->isWeekend();
});
```

## Conditional Strategy

Custom logic for complex scenarios.

```php
use Cline\Pole\Strategies\ConditionalStrategy;

// Multi-factor decision
Feature::define('premium-feature')
    ->strategy(new ConditionalStrategy(function ($user) {
        return $user->subscription === 'premium' 
            && $user->email_verified 
            && !$user->suspended;
    }));

// Environment-based
Feature::define('debug-toolbar', function () {
    return app()->environment('local', 'staging');
});

// Complex business logic
Feature::define('bulk-discount', function ($order) {
    return $order->items->count() >= 10 
        && $order->total >= 1000 
        && $order->customer->tier === 'wholesale';
});
```

## Combining Strategies

Use dependencies and time bombs to combine strategy behaviors:

```php
// Percentage rollout that expires
Feature::define('experimental-ui')
    ->strategy(new PercentageStrategy(10))
    ->expiresAt(now()->addWeeks(2));

// Feature that requires another feature
Feature::define('advanced-reports')
    ->requires('basic-analytics')
    ->strategy(new ConditionalStrategy(fn($user) => $user->isPremium()));
```

## Custom Strategies

Implement the `Strategy` contract:

```php
namespace App\Strategies;

use Cline\Pole\Contracts\Strategy;

class RegionStrategy implements Strategy
{
    public function __construct(private array $allowedRegions) {}

    public function resolve(mixed $scope): bool
    {
        return in_array($scope?->region, $this->allowedRegions);
    }
}

// Use it
Feature::define('eu-features')
    ->strategy(new RegionStrategy(['EU', 'UK']));
```

## Next Steps

- [Time Bombs](time-bombs.md) - Auto-expiring features
- [Dependencies](dependencies.md) - Feature requirements
- [Variants](variants.md) - A/B testing
