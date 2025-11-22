# Dependencies

Feature dependencies allow you to create relationships between features, ensuring that advanced features are only active when their prerequisites are met.

## Defining Dependencies

### Single Dependency

```php
use Cline\Toggl\Toggl;

Toggl::define('basic-analytics', fn($user) => $user->hasSubscription());

Toggl::define('advanced-analytics')
    ->requires('basic-analytics')
    ->resolver(fn($user) => $user->subscription === 'premium');

// With BackedEnum
Toggl::define(FeatureFlag::AdvancedAnalytics)
    ->requires(FeatureFlag::BasicAnalytics)
    ->resolver(fn($user) => $user->subscription === 'premium');
```

If `basic-analytics` is inactive, `advanced-analytics` will automatically return `false`, even if its resolver returns `true`.

### Multiple Dependencies

```php
Toggl::define('team-collaboration')
    ->requires('user-management', 'real-time-sync')
    ->resolver(fn($team) => $team->size >= 5);

// With BackedEnum (mixed or all enums)
Toggl::define(FeatureFlag::TeamCollaboration)
    ->requires(FeatureFlag::UserManagement, FeatureFlag::RealtimeSync)
    ->resolver(fn($team) => $team->size >= 5);
```

All dependencies must be active for the feature to be active.

## Checking Dependencies

### Get Dependencies

```php
$deps = Toggl::getDependencies('advanced-analytics');
// ['basic-analytics']

$deps = Toggl::getDependencies('team-collaboration');
// ['user-management', 'real-time-sync']
```

### Check if Dependencies are Met

```php
if (Toggl::dependenciesMet('advanced-analytics')) {
    // All dependencies are active
}

// With BackedEnum
if (Toggl::dependenciesMet(FeatureFlag::AdvancedAnalytics)) {
    // All dependencies are active
}
```

## Transitive Dependencies

Dependencies are checked recursively:

```php
Toggl::define('level-1', true);

Toggl::define('level-2')
    ->requires('level-1')
    ->resolver(fn() => true);

Toggl::define('level-3')
    ->requires('level-2')
    ->resolver(fn() => true);

// level-3 checks: level-2 → level-1
Toggl::active('level-3'); // true only if all levels active
```

## Circular Dependency Protection

Toggl detects and prevents circular dependencies:

```php
Toggl::define('feature-a')
    ->requires('feature-b')
    ->resolver(fn() => true);

Toggl::define('feature-b')
    ->requires('feature-a')
    ->resolver(fn() => true);

// Both return false - circular dependency detected
Toggl::active('feature-a'); // false
Toggl::active('feature-b'); // false
```

## Use Cases

### Feature Tiers

```php
// Basic → Pro → Enterprise scope
Toggl::define('basic-features', fn($user) => $user->hasAnySubscription());

Toggl::define('pro-features')
    ->requires('basic-features')
    ->resolver(fn($user) => in_array($user->plan, ['pro', 'enterprise']));

Toggl::define('enterprise-features')
    ->requires('pro-features')
    ->resolver(fn($user) => $user->plan === 'enterprise');
```

### Progressive Feature Unlocking

```php
// Tutorial completion required
Toggl::define('advanced-tools')
    ->requires('tutorial-completed')
    ->resolver(fn($user) => $user->level >= 5);

Toggl::define('expert-mode')
    ->requires('advanced-tools')
    ->resolver(fn($user) => $user->level >= 10);
```

### Platform Capabilities

```php
Toggl::define('offline-mode', fn() => true);

Toggl::define('background-sync')
    ->requires('offline-mode')
    ->resolver(fn($device) => $device->hasNetwork());

Toggl::define('real-time-collaboration')
    ->requires('background-sync')
    ->resolver(fn($user) => $user->isPremium());
```

### API Versioning

```php
Toggl::define('api-v1', fn() => true);

Toggl::define('api-v2')
    ->requires('api-v1')
    ->resolver(fn($client) => $client->hasOptedIn());

Toggl::define('api-v3')
    ->requires('api-v2')
    ->resolver(fn($client) => $client->isEarlyAdopter());
```

## Contextual Dependencies

Dependencies work with contextual features:

```php
Toggl::define('team-features', fn($user) => $user->hasTeam());

Toggl::define('team-admin-panel')
    ->requires('team-features')
    ->resolver(fn($user) => $user->isTeamAdmin());

// Check for specific user
Toggl::for($user)->active('team-admin-panel');
// Returns true only if:
// 1. User has a team (team-features)
// 2. User is team admin (team-admin-panel resolver)
```

## Combining with Other Features

### Dependencies + Time Bombs

```php
Toggl::define('base-feature', fn() => true);

Toggl::define('experimental-addon')
    ->requires('base-feature')
    ->expiresAfter(days: 30)
    ->resolver(fn($user) => $user->isBetaTester());
```

### Dependencies + Groups

```php
Toggl::defineGroup('advanced-suite', [
    'advanced-analytics',
    'custom-reports',
    'api-access',
]);

// Each feature in group has same dependency
Toggl::define('advanced-analytics')->requires('premium-subscription');
Toggl::define('custom-reports')->requires('premium-subscription');
Toggl::define('api-access')->requires('premium-subscription');

// Activate group only if dependency is met
if (Toggl::for($user)->active('premium-subscription')) {
    Toggl::for($user)->activateGroup('advanced-suite');
}
```

### Dependencies + Percentage Rollout

```php
Toggl::define('new-ui', fn() => true);

// Gradual rollout of advanced features requiring new UI
Toggl::define('advanced-ui-features')
    ->requires('new-ui')
    ->strategy(new PercentageStrategy(25));
```

## Manual Override

You can still manually activate a feature even if dependencies aren't met, but it will still check dependencies when evaluated:

```php
Toggl::define('dependency', false);

Toggl::define('dependent')
    ->requires('dependency')
    ->resolver(fn() => true);

// Manually activate
Toggl::activate('dependent');

// Still returns false because dependency not met
Toggl::active('dependent'); // false

// Activate dependency
Toggl::activate('dependency');

// Now it works
Toggl::active('dependent'); // true
```

## Best Practices

1. **Document dependency chains**
   ```php
   // Clear scope
   Toggl::define('level-1', true); // Base level
   Toggl::define('level-2')->requires('level-1'); // Requires base
   Toggl::define('level-3')->requires('level-2'); // Requires level 2
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
   Toggl::define('sso-integration')
       ->requires('user-authentication');
   
   // ❌ Unclear
   Toggl::define('feature-x')
       ->requires('feature-y');
   ```

4. **Test dependency chains**
   ```php
   test('dependency chain works correctly', function () {
       Toggl::define('base', false);
       Toggl::define('dependent')->requires('base');
       
       expect(Toggl::active('dependent'))->toBeFalse();
       
       Toggl::activate('base');
       expect(Toggl::active('dependent'))->toBeTrue();
   });
   ```

## Next Steps

- [Variants](variants.md) - A/B testing and value variants
- [Advanced Usage](advanced-usage.md) - Events, middleware, and commands
