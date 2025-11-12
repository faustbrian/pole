# Feature Groups

Feature groups allow you to manage related features together, enabling bulk operations and simplified testing.

## Defining Groups

### In Configuration

Edit `config/pole.php`:

```php
return [
    // ...
    
    'groups' => [
        'beta' => [
            'new-dashboard',
            'advanced-search',
            'ai-recommendations',
        ],
        
        'premium' => [
            'priority-support',
            'advanced-analytics',
            'custom-branding',
        ],
        
        'mobile-v2' => [
            'swipe-gestures',
            'offline-mode',
            'push-notifications',
        ],
    ],
];
```

### Programmatically

```php
use Cline\Pole\Feature;

Feature::defineGroup('experimental', [
    'new-checkout-flow',
    'product-recommendations',
    'one-click-purchase',
]);
```

## Bulk Operations

### Activate Entire Group

```php
// Activate all features in a group for everyone
Feature::activateGroup('beta');

// Activate for specific scope
Feature::for($user)->activateGroup('premium');
```

### Deactivate Entire Group

```php
Feature::deactivateGroup('beta');
Feature::for($user)->deactivateGroup('premium');
```

## Checking Group Status

### All Features Active

```php
// Check if all features in group are active
if (Feature::for($user)->activeInGroup('premium')) {
    // User has all premium features
    return view('dashboard.premium');
}
```

### Any Feature Active

```php
// Check if any feature in group is active
if (Feature::for($user)->someActiveInGroup('beta')) {
    // User has at least one beta feature
    $this->showBetaBadge();
}
```

## Use Cases

### Beta Program Management

```php
// Define beta features
Feature::defineGroup('beta-program', [
    'new-ui',
    'advanced-filters',
    'bulk-operations',
]);

// Enroll user in beta
Feature::for($user)->activateGroup('beta-program');

// Check eligibility
if (Feature::for($user)->activeInGroup('beta-program')) {
    return 'Full beta access';
}

// Unenroll from beta
Feature::for($user)->deactivateGroup('beta-program');
```

### Subscription Tiers

```php
// Define tier features
Feature::defineGroup('basic', ['core-features']);
Feature::defineGroup('pro', ['core-features', 'advanced-analytics', 'api-access']);
Feature::defineGroup('enterprise', ['core-features', 'advanced-analytics', 'api-access', 'sso', 'custom-branding']);

// Activate based on subscription
match($user->subscription_tier) {
    'basic' => Feature::for($user)->activateGroup('basic'),
    'pro' => Feature::for($user)->activateGroup('pro'),
    'enterprise' => Feature::for($user)->activateGroup('enterprise'),
};

// Check tier
if (Feature::for($user)->activeInGroup('enterprise')) {
    $this->enableSso();
}
```

### Platform-Specific Features

```php
Feature::defineGroup('mobile', [
    'push-notifications',
    'offline-sync',
    'biometric-auth',
]);

Feature::defineGroup('desktop', [
    'keyboard-shortcuts',
    'multi-window',
    'system-tray',
]);

// Activate based on platform
if ($request->userAgent()->isMobile()) {
    Feature::for($user)->activateGroup('mobile');
} else {
    Feature::for($user)->activateGroup('desktop');
}
```

### Feature Releases

```php
// Q1 2025 features
Feature::defineGroup('q1-2025', [
    'dark-mode',
    'export-improvements',
    'team-collaboration',
]);

// Enable all at once when ready
Feature::activateForEveryone('q1-2025');

// Or gradual rollout
$percentage = 25; // 25% of users
Feature::define('q1-2025-rollout')
    ->strategy(new PercentageStrategy($percentage));

if (Feature::for($user)->active('q1-2025-rollout')) {
    Feature::for($user)->activateGroup('q1-2025');
}
```

### Testing Scenarios

```php
// Enable all experimental features for testing
public function setUp(): void
{
    parent::setUp();
    
    Feature::activateGroup('experimental');
}

// Test specific group combinations
test('premium features work together', function () {
    Feature::for($user)->activateGroup('premium');
    
    expect(Feature::for($user)->activeInGroup('premium'))->toBeTrue();
    // Test premium functionality
});
```

## Retrieving Groups

```php
// Get all defined groups
$groups = Feature::groups();
// ['beta' => [...], 'premium' => [...]]

// Get features in a group
$betaFeatures = Feature::groups()['beta'];
// ['new-dashboard', 'advanced-search', 'ai-recommendations']
```

## Combining with Other Features

### Groups + Time Bombs

```php
Feature::defineGroup('holiday-2025', [
    'gift-wrap-option',
    'holiday-theme',
    'special-discounts',
]);

// Set expiration on all features
foreach (Feature::groups()['holiday-2025'] as $feature) {
    Feature::define($feature)
        ->expiresAt('2025-12-26')
        ->resolver(fn() => true);
}
```

### Groups + Dependencies

```php
// Base features required for advanced group
Feature::define('advanced-analytics')
    ->requires('basic-analytics');

Feature::defineGroup('analytics-suite', [
    'basic-analytics',
    'advanced-analytics', // Will check dependency
    'custom-reports',
]);
```

## Blade Directives

```blade
@featureall('premium')
    <x-premium-dashboard />
@endfeatureall

@featureany('beta-program')
    <div class="beta-badge">Beta Tester</div>
@endfeatureany
```

## Best Practices

1. **Group by logical boundaries**
   ```php
   // ✅ Good - clear purpose
   Feature::defineGroup('mobile-app-v2', [...]);
   
   // ❌ Avoid - too vague
   Feature::defineGroup('stuff', [...]);
   ```

2. **Keep groups focused**
   ```php
   // ✅ Good - 3-8 related features
   Feature::defineGroup('search-improvements', [
       'fuzzy-search',
       'search-suggestions',
       'search-history',
   ]);
   
   // ❌ Avoid - too many unrelated features
   Feature::defineGroup('everything', [/* 50 features */]);
   ```

3. **Document group purpose**
   ```php
   // config/pole.php
   'groups' => [
       // Features for Q1 2025 release
       'q1-2025' => [...],
       
       // Beta tester access
       'beta-program' => [...],
   ],
   ```

## Next Steps

- [Dependencies](dependencies.md) - Feature requirements
- [Variants](variants.md) - A/B testing
- [Advanced Usage](advanced-usage.md) - Automation and commands
