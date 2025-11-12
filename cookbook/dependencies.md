# Dependencies

Feature dependencies allow you to create relationships between features, ensuring that advanced features are only active when their prerequisites are met.

## Defining Dependencies

### Single Dependency

```php
use Cline\Pole\Feature;

Feature::define('basic-analytics', fn($user) => $user->hasSubscription());

Feature::define('advanced-analytics')
    ->requires('basic-analytics')
    ->resolver(fn($user) => $user->subscription === 'premium');
```

If `basic-analytics` is inactive, `advanced-analytics` will automatically return `false`, even if its resolver returns `true`.

### Multiple Dependencies

```php
Feature::define('team-collaboration')
    ->requires('user-management', 'real-time-sync')
    ->resolver(fn($team) => $team->size >= 5);
```

All dependencies must be active for the feature to be active.

## Checking Dependencies

### Get Dependencies

```php
$deps = Feature::getDependencies('advanced-analytics');
// ['basic-analytics']

$deps = Feature::getDependencies('team-collaboration');
// ['user-management', 'real-time-sync']
```

### Check if Dependencies are Met

```php
if (Feature::dependenciesMet('advanced-analytics')) {
    // All dependencies are active
}
```

## Transitive Dependencies

Dependencies are checked recursively:

```php
Feature::define('level-1', true);

Feature::define('level-2')
    ->requires('level-1')
    ->resolver(fn() => true);

Feature::define('level-3')
    ->requires('level-2')
    ->resolver(fn() => true);

// level-3 checks: level-2 → level-1
Feature::active('level-3'); // true only if all levels active
```

## Circular Dependency Protection

Pole detects and prevents circular dependencies:

```php
Feature::define('feature-a')
    ->requires('feature-b')
    ->resolver(fn() => true);

Feature::define('feature-b')
    ->requires('feature-a')
    ->resolver(fn() => true);

// Both return false - circular dependency detected
Feature::active('feature-a'); // false
Feature::active('feature-b'); // false
```

## Use Cases

### Feature Tiers

```php
// Basic → Pro → Enterprise hierarchy
Feature::define('basic-features', fn($user) => $user->hasAnySubscription());

Feature::define('pro-features')
    ->requires('basic-features')
    ->resolver(fn($user) => in_array($user->plan, ['pro', 'enterprise']));

Feature::define('enterprise-features')
    ->requires('pro-features')
    ->resolver(fn($user) => $user->plan === 'enterprise');
```

### Progressive Feature Unlocking

```php
// Tutorial completion required
Feature::define('advanced-tools')
    ->requires('tutorial-completed')
    ->resolver(fn($user) => $user->level >= 5);

Feature::define('expert-mode')
    ->requires('advanced-tools')
    ->resolver(fn($user) => $user->level >= 10);
```

### Platform Capabilities

```php
Feature::define('offline-mode', fn() => true);

Feature::define('background-sync')
    ->requires('offline-mode')
    ->resolver(fn($device) => $device->hasNetwork());

Feature::define('real-time-collaboration')
    ->requires('background-sync')
    ->resolver(fn($user) => $user->isPremium());
```

### API Versioning

```php
Feature::define('api-v1', fn() => true);

Feature::define('api-v2')
    ->requires('api-v1')
    ->resolver(fn($client) => $client->hasOptedIn());

Feature::define('api-v3')
    ->requires('api-v2')
    ->resolver(fn($client) => $client->isEarlyAdopter());
```

## Scoped Dependencies

Dependencies work with scoped features:

```php
Feature::define('team-features', fn($user) => $user->hasTeam());

Feature::define('team-admin-panel')
    ->requires('team-features')
    ->resolver(fn($user) => $user->isTeamAdmin());

// Check for specific user
Feature::for($user)->active('team-admin-panel');
// Returns true only if:
// 1. User has a team (team-features)
// 2. User is team admin (team-admin-panel resolver)
```

## Combining with Other Features

### Dependencies + Time Bombs

```php
Feature::define('base-feature', fn() => true);

Feature::define('experimental-addon')
    ->requires('base-feature')
    ->expiresAfter(days: 30)
    ->resolver(fn($user) => $user->isBetaTester());
```

### Dependencies + Groups

```php
Feature::defineGroup('advanced-suite', [
    'advanced-analytics',
    'custom-reports',
    'api-access',
]);

// Each feature in group has same dependency
Feature::define('advanced-analytics')->requires('premium-subscription');
Feature::define('custom-reports')->requires('premium-subscription');
Feature::define('api-access')->requires('premium-subscription');

// Activate group only if dependency is met
if (Feature::for($user)->active('premium-subscription')) {
    Feature::for($user)->activateGroup('advanced-suite');
}
```

### Dependencies + Percentage Rollout

```php
Feature::define('new-ui', fn() => true);

// Gradual rollout of advanced features requiring new UI
Feature::define('advanced-ui-features')
    ->requires('new-ui')
    ->strategy(new PercentageStrategy(25));
```

## Manual Override

You can still manually activate a feature even if dependencies aren't met, but it will still check dependencies when evaluated:

```php
Feature::define('dependency', false);

Feature::define('dependent')
    ->requires('dependency')
    ->resolver(fn() => true);

// Manually activate
Feature::activate('dependent');

// Still returns false because dependency not met
Feature::active('dependent'); // false

// Activate dependency
Feature::activate('dependency');

// Now it works
Feature::active('dependent'); // true
```

## Best Practices

1. **Document dependency chains**
   ```php
   // Clear hierarchy
   Feature::define('level-1', true); // Base level
   Feature::define('level-2')->requires('level-1'); // Requires base
   Feature::define('level-3')->requires('level-2'); // Requires level 2
   ```

2. **Avoid deep chains**
   ```php
   // ✅ Good - 2-3 levels
   basic → advanced → expert
   
   // ❌ Avoid - too complex
   a → b → c → d → e → f
   ```

3. **Use meaningful names**
   ```php
   // ✅ Good
   Feature::define('sso-integration')
       ->requires('user-authentication');
   
   // ❌ Unclear
   Feature::define('feature-x')
       ->requires('feature-y');
   ```

4. **Test dependency chains**
   ```php
   test('dependency chain works correctly', function () {
       Feature::define('base', false);
       Feature::define('dependent')->requires('base');
       
       expect(Feature::active('dependent'))->toBeFalse();
       
       Feature::activate('base');
       expect(Feature::active('dependent'))->toBeTrue();
   });
   ```

## Next Steps

- [Variants](variants.md) - A/B testing and value variants
- [Advanced Usage](advanced-usage.md) - Events, middleware, and commands
