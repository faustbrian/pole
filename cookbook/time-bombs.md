# Time Bombs

Time bombs are features that automatically expire after a specified date, preventing abandoned feature flags from cluttering your codebase.

## Setting Expiration Dates

### Using expiresAt()

```php
use Cline\Pole\Feature;

Feature::define('black-friday-sale')
    ->expiresAt(now()->parse('2025-12-02 23:59:59'))
    ->resolver(fn($user) => true);

// After expiration, the feature returns false regardless of resolver
```

### Using expiresAfter()

```php
Feature::define('trial-feature')
    ->expiresAfter(days: 30)
    ->resolver(fn($user) => $user->isTrialing());

// Relative expiration
Feature::define('temporary-access')
    ->expiresAfter(hours: 48)
    ->resolver(fn($user) => true);
```

## Checking Expiration

### Is Feature Expired?

```php
if (Feature::isExpired('black-friday-sale')) {
    // Clean up related code
}
```

### Get Expiration Date

```php
$expiresAt = Feature::expiresAt('trial-feature');
// Returns CarbonInterface|null

if ($expiresAt && $expiresAt->isPast()) {
    // Feature has expired
}
```

### Expiring Soon Warning

```php
// Features expiring within 3 days
$expiring = Feature::expiringSoon(days: 3);
// Returns array of feature names

// Send alerts
foreach ($expiring as $feature) {
    Log::warning("Feature '{$feature}' expiring soon", [
        'expires_at' => Feature::expiresAt($feature),
    ]);
}
```

## Automatic Cleanup

### Manual Cleanup

```php
// Remove all expired features from storage
Feature::purge(); // Removes expired features

// Or specific features
Feature::forget(['old-feature', 'deprecated-feature']);
```

### Scheduled Cleanup

Add to your scheduler in `app/Console/Kernel.php`:

```php
use Cline\Pole\Feature;

protected function schedule(Schedule $schedule): void
{
    // Clean up expired features weekly
    $schedule->call(function () {
        Feature::purge();
    })->weekly();

    // Warn about expiring features daily
    $schedule->call(function () {
        $expiring = Feature::expiringSoon(days: 7);
        
        foreach ($expiring as $feature) {
            Log::warning("Feature expiring soon: {$feature}");
        }
    })->daily();
}
```

## Use Cases

### Limited-Time Promotions

```php
Feature::define('summer-sale-2025')
    ->expiresAt('2025-08-31 23:59:59')
    ->resolver(fn() => true);

if (Feature::active('summer-sale-2025')) {
    $discount = 0.20; // 20% off
}
```

### Beta Testing Windows

```php
Feature::define('beta-v2-api')
    ->expiresAfter(days: 90)
    ->resolver(fn($user) => $user->isBetaTester());

// After 90 days, beta access automatically revoked
```

### Temporary Feature Access

```php
Feature::define('trial-premium-features')
    ->expiresAt($user->trial_ends_at)
    ->resolver(fn($user) => $user->onTrial());
```

### Emergency Toggles

```php
// Quick toggle that auto-expires
Feature::define('maintenance-bypass')
    ->expiresAfter(hours: 2)
    ->resolver(fn($user) => $user->isAdmin());

// Automatically reverts after 2 hours
```

## Combining with Other Features

### Time Bomb + Dependencies

```php
Feature::define('base-feature', true);

Feature::define('experimental-addon')
    ->requires('base-feature')
    ->expiresAfter(days: 30)
    ->resolver(fn($user) => true);
```

### Time Bomb + Percentage Rollout

```php
// 10% rollout for 2 weeks
Feature::define('new-ui-test')
    ->strategy(new PercentageStrategy(10))
    ->expiresAfter(weeks: 2);
```

### Time Bomb + Groups

```php
Feature::defineGroup('q4-features', [
    'holiday-theme',
    'gift-recommendations',
    'special-pricing',
]);

// All features in group expire together
Feature::define('holiday-theme')->expiresAt('2025-01-01');
Feature::define('gift-recommendations')->expiresAt('2025-01-01');
Feature::define('special-pricing')->expiresAt('2025-01-01');
```

## Best Practices

1. **Always set expiration for temporary features**
   ```php
   // ✅ Good
   Feature::define('experiment')->expiresAfter(days: 30);
   
   // ❌ Avoid - might forget to clean up
   Feature::define('experiment', true);
   ```

2. **Monitor expiring features**
   ```php
   // Schedule regular checks
   $expiring = Feature::expiringSoon(days: 7);
   if (count($expiring) > 0) {
       notify_team($expiring);
   }
   ```

3. **Document why features expire**
   ```php
   // Clear intent
   Feature::define('promo-code-double-points')
       ->expiresAt('2025-12-31') // End of promotional period
       ->resolver(fn($user) => true);
   ```

## Next Steps

- [Feature Groups](feature-groups.md) - Managing related features
- [Dependencies](dependencies.md) - Feature requirements
- [Advanced Usage](advanced-usage.md) - Commands and automation
