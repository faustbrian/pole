# Feature Groups

Feature groups allow you to manage related features together, enabling bulk operations, membership-based access control, and simplified testing. Groups can be stored either in configuration files (array storage) or in the database for dynamic management.

## Storage Configuration

Choose between array (in-memory) or database storage in `config/toggl.php`:

```php
return [
    // Storage driver for feature groups
    'group_storage' => env('FEATURE_GROUP_STORAGE', 'array'), // 'array' or 'database'
];
```

**Array Storage**: Groups defined in configuration file, loaded at runtime (best for static groups)
**Database Storage**: Groups persisted in database, manageable via API (best for dynamic groups)

## Defining Groups

### In Configuration (Array Storage)

Edit `config/toggl.php`:

```php
return [
    'group_storage' => 'array',

    'groups' => [
        'beta' => [
            'features' => [
                'new-dashboard',
                'advanced-search',
                'ai-recommendations',
            ],
            'description' => 'Beta testing features',
        ],

        'premium' => [
            'features' => [
                'priority-support',
                'advanced-analytics',
                'custom-branding',
            ],
            'description' => 'Premium tier features',
        ],
    ],
];
```

With BackedEnum for type safety:

```php
use App\Enums\FeatureFlag;

return [
    'group_storage' => 'array',

    'groups' => [
        'beta' => [
            'features' => [
                FeatureFlag::NewDashboard->value,
                FeatureFlag::AdvancedSearch->value,
                FeatureFlag::AiRecommendations->value,
            ],
            'description' => 'Beta testing features',
        ],

        'premium' => [
            'features' => [
                FeatureFlag::PrioritySupport->value,
                FeatureFlag::AdvancedAnalytics->value,
                FeatureFlag::CustomBranding->value,
            ],
            'description' => 'Premium tier features',
        ],
    ],
];
```

### Using Database Storage

```php
// config/toggl.php
return [
    'group_storage' => 'database',
];
```

Then define groups programmatically:

```php
use Cline\Toggl\Toggl;

// Fluent API
Toggl::groups()
    ->create('experimental')
    ->with('new-checkout-flow', 'product-recommendations', 'one-click-purchase')
    ->save();

// Direct definition
Toggl::groups()->define('beta', [
    'new-dashboard',
    'advanced-search',
    'ai-recommendations',
]);

// With BackedEnum
Toggl::groups()->define('beta', [
    FeatureFlag::NewDashboard,
    FeatureFlag::AdvancedSearch,
    FeatureFlag::AiRecommendations,
]);
```

## Managing Groups (Database Storage)

### Creating Groups

```php
// Fluent API
Toggl::groups()
    ->create('beta-testers')
    ->with('new-ui', 'dark-mode')
    ->save();

// Direct definition
Toggl::groups()->define('vip', ['exclusive-feature', 'early-access']);
```

### Updating Groups

```php
// Replace all features
Toggl::groups()->update('beta-testers', ['new-ui', 'dark-mode', 'advanced-api']);

// Add features
Toggl::groups()->add('beta-testers', ['feature-x', 'feature-y']);

// Remove features
Toggl::groups()->remove('beta-testers', ['old-feature']);
```

### Deleting Groups

```php
Toggl::groups()->delete('old-group');
```

### Checking Group Existence

```php
if (Toggl::groups()->exists('beta-testers')) {
    // Group exists
}
```

## Feature Group Membership

Groups support membership-based feature inheritance. When you assign users/teams to a group, they automatically inherit access to all features in that group.

### Assigning Contexts to Groups

Toggl offers two fluent APIs for feature group membership, inspired by Warden's conductor pattern:

```php
// Context-first API (recommended - more natural)
Toggl::groups()->for($user)->assign('beta-testers');
Toggl::groups()->for($team)->assign('premium');

// Traditional API (still available)
Toggl::groups()->assign('beta-testers', $user);
Toggl::groups()->assign('premium', $team);

// Assign multiple users at once
$users = User::where('beta_opt_in', true)->get();
Toggl::groups()->assignMany('beta-testers', $users->all());
```

### Checking Membership

```php
// Context-first API (recommended)
if (Toggl::groups()->for($user)->isIn('beta-testers')) {
    // User is a beta tester
}

// Get all groups for a user
$userGroups = Toggl::groups()->for($user)->groups();
// ['beta-testers', 'early-access']

// Traditional API (still available)
if (Toggl::groups()->isInGroup('beta-testers', $user)) {
    // User is a beta tester
}

$userGroups = Toggl::groups()->groupsFor($user);
// ['beta-testers', 'early-access']

// Get all members of a group
$members = Toggl::groups()->members('beta-testers');
```

### Removing from Groups

```php
// Context-first API (recommended)
Toggl::groups()->for($user)->unassign('beta-testers');

// Traditional API (still available)
Toggl::groups()->unassign('beta-testers', $user);

// Remove all members
Toggl::groups()->clearMembers('beta-testers');
```

## Feature Inheritance Through Groups

When a context belongs to a group, they automatically get access to features activated for that group:

```php
// 1. Define a group
Toggl::groups()->define('experimental', ['new-layout', 'dark-mode']);

// 2. Assign users to group
$users = User::whereIn('id', [1, 2, 3, 4, 5])->get();
Toggl::groups()->assignMany('experimental', $users->all());

// 3. Activate features for the group (using __all__ context)
Toggl::for('__all__')->activate('new-layout');
Toggl::for('__all__')->activate('dark-mode');

// 4. Check feature for user - automatically active via feature group membership!
Toggl::for($users->first())->active('new-layout'); // true
```

**How it works:**
1. User is assigned to group via membership system
2. Feature is activated for `__all__` context (group-level activation)
3. When checking if feature is active for user, Toggl checks:
   - Is feature directly active for this user? If yes, return true
   - If no, check all groups user belongs to
   - For each group, check if feature is in that group's feature list
   - If yes, check if feature is active for `__all__` context
   - If active for `__all__`, user inherits it through feature group membership

## Bulk Operations

### Activate Entire Group

```php
// Traditional context-first pattern
Toggl::for($user)->activateGroup('premium');

// Conductor pattern (group-first) - more natural for bulk operations
Toggl::activateGroupConductor('premium')->for($user);

// Activate for multiple contexts using conductor
Toggl::activateGroupConductor('premium')->for([$user1, $user2, $user3]);

// Both patterns work identically - choose what reads better
```

### Deactivate Entire Group

```php
// Traditional context-first pattern
Toggl::for($user)->deactivateGroup('premium');

// Conductor pattern (group-first)
Toggl::deactivateGroupConductor('premium')->for($user);

// Deactivate for multiple contexts
Toggl::deactivateGroupConductor('beta')->for([$user1, $user2]);
```

## Checking Group Status

### All Features Active

```php
// Check if all features in group are active
if (Toggl::for($user)->activeInGroup('premium')) {
    // User has all premium features
    return view('dashboard.premium');
}
```

### Any Feature Active

```php
// Check if any feature in group is active
if (Toggl::for($user)->someActiveInGroup('beta')) {
    // User has at least one beta feature
    $this->showBetaBadge();
}
```

## Real-World Use Cases

### Beta Program with Membership

```php
// 1. Define beta group
Toggl::groups()->define('beta-program', [
    'new-ui',
    'advanced-filters',
    'bulk-operations',
]);

// 2. Enroll users who opted in
$betaUsers = User::where('beta_opt_in', true)->get();
Toggl::groups()->assignMany('beta-program', $betaUsers->all());

// 3. Activate beta features for all beta group members
Toggl::for('__all__')->activate('new-ui');
Toggl::for('__all__')->activate('advanced-filters');
Toggl::for('__all__')->activate('bulk-operations');

// 4. Check if user sees beta features (via feature group membership)
if (Toggl::for($user)->active('new-ui')) {
    return view('beta.dashboard');
}

// 5. Remove user from beta
Toggl::groups()->unassign('beta-program', $user);
```

### Gradual Rollout with Random Selection

```php
// Select 10 random users for experimental features
$experimentalUsers = User::inRandomOrder()->limit(10)->get();

// Define experimental group
Toggl::groups()->define('experimental', ['new-checkout', 'ai-recommendations']);

// Assign to group
Toggl::groups()->assignMany('experimental', $experimentalUsers->all());

// Activate features for group
Toggl::for('__all__')->activate('new-checkout');
Toggl::for('__all__')->activate('ai-recommendations');

// These 10 users automatically see the features!
foreach ($experimentalUsers as $user) {
    Toggl::for($user)->active('new-checkout'); // true
}

// Others don't
$regularUser = User::where('id', '>', 10)->first();
Toggl::for($regularUser)->active('new-checkout'); // false
```

### Subscription Tiers with Dynamic Assignment

```php
// Define tier groups
Toggl::groups()->define('basic', ['core-features']);
Toggl::groups()->define('pro', ['core-features', 'advanced-analytics', 'api-access']);
Toggl::groups()->define('enterprise', ['core-features', 'advanced-analytics', 'api-access', 'sso', 'custom-branding']);

// Activate all tier features for __all__ context
Toggl::for('__all__')->activate('core-features');
Toggl::for('__all__')->activate('advanced-analytics');
Toggl::for('__all__')->activate('api-access');
Toggl::for('__all__')->activate('sso');
Toggl::for('__all__')->activate('custom-branding');

// Assign user to appropriate tier group based on subscription
match($user->subscription_tier) {
    'basic' => Toggl::groups()->assign('basic', $user),
    'pro' => Toggl::groups()->assign('pro', $user),
    'enterprise' => Toggl::groups()->assign('enterprise', $user),
};

// User automatically inherits features from their tier
Toggl::for($enterpriseUser)->active('sso'); // true
Toggl::for($basicUser)->active('sso'); // false

// When user upgrades
Toggl::groups()->unassign('basic', $user);
Toggl::groups()->assign('pro', $user);
// Now has pro features automatically
```

### Platform-Specific Features

```php
Toggl::defineGroup('mobile', [
    'push-notifications',
    'offline-sync',
    'biometric-auth',
]);

Toggl::defineGroup('desktop', [
    'keyboard-shortcuts',
    'multi-window',
    'system-tray',
]);

// Activate based on platform
if ($request->userAgent()->isMobile()) {
    Toggl::for($user)->activateGroup('mobile');
} else {
    Toggl::for($user)->activateGroup('desktop');
}
```

### Feature Releases

```php
// Q1 2025 features
Toggl::defineGroup('q1-2025', [
    'dark-mode',
    'export-improvements',
    'team-collaboration',
]);

// Enable all at once when ready
Toggl::activateForEveryone('q1-2025');

// Or gradual rollout
$percentage = 25; // 25% of users
Toggl::define('q1-2025-rollout')
    ->strategy(new PercentageStrategy($percentage));

if (Toggl::for($user)->active('q1-2025-rollout')) {
    Toggl::for($user)->activateGroup('q1-2025');
}
```

### Testing Scenarios

```php
// Enable all experimental features for testing
public function setUp(): void
{
    parent::setUp();
    
    Toggl::activateGroup('experimental');
}

// Test specific group combinations
test('premium features work together', function () {
    Toggl::for($user)->activateGroup('premium');
    
    expect(Toggl::for($user)->activeInGroup('premium'))->toBeTrue();
    // Test premium functionality
});
```

## Retrieving Groups and Features

```php
// Get all defined groups
$groups = Toggl::allGroups();
// ['beta' => [...], 'premium' => [...]]

// Get features in a specific group
$betaFeatures = Toggl::getGroup('beta');
// ['new-dashboard', 'advanced-search', 'ai-recommendations']

// Get all groups with database storage
$allGroups = Toggl::groups()->all();
// ['beta' => ['feat1', 'feat2'], 'premium' => ['feat3']]
```

## Combining with Other Features

### Groups + Time Bombs

```php
Toggl::groups()->define('holiday-2025', [
    'gift-wrap-option',
    'holiday-theme',
    'special-discounts',
]);

// Set expiration on all features
foreach (Toggl::getGroup('holiday-2025') as $feature) {
    Toggl::define($feature)
        ->expiresAt('2025-12-26')
        ->resolver(fn() => true);
}
```

### Groups + Dependencies

```php
// Base features required for advanced group
Toggl::define('advanced-analytics')
    ->requires('basic-analytics');

Toggl::groups()->define('analytics-suite', [
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
   Toggl::groups()->define('mobile-app-v2', [...]);

   // ❌ Avoid - too vague
   Toggl::groups()->define('stuff', [...]);
   ```

2. **Keep groups focused**
   ```php
   // ✅ Good - 3-8 related features
   Toggl::groups()->define('search-improvements', [
       'fuzzy-search',
       'search-suggestions',
       'search-history',
   ]);

   // ❌ Avoid - too many unrelated features
   Toggl::groups()->define('everything', [/* 50 features */]);
   ```

3. **Document group purpose**
   ```php
   // config/toggl.php
   'groups' => [
       // Features for Q1 2025 release
       'q1-2025' => [
           'features' => [...],
           'description' => 'Q1 2025 release features',
       ],

       // Beta tester access
       'beta-program' => [
           'features' => [...],
           'description' => 'Beta tester access',
       ],
   ],
   ```

4. **Choose appropriate storage**
   ```php
   // ✅ Use array storage for static, rarely-changing groups
   'group_storage' => 'array',

   // ✅ Use database storage for dynamic groups that change frequently
   'group_storage' => 'database',
   ```

5. **Choose between per-user flags vs feature group membership**
   ```php
   // ✅ Per-user flags for individual access (subscriptions, permissions)
   Toggl::for($user)->activate('premium-features');

   // ✅ Feature group membership for cohorts (beta testers, experimental rollouts)
   Toggl::groups()->assign('beta', $user);
   Toggl::for('__all__')->activate('beta-feature');
   ```

6. **Clean up memberships when users leave groups**
   ```php
   // When user cancels subscription
   Toggl::groups()->unassign('premium', $user);

   // When beta program ends
   Toggl::groups()->clearMembers('beta-program');
   ```

## Next Steps

- [Dependencies](dependencies.md) - Feature requirements
- [Variants](variants.md) - A/B testing
- [Advanced Usage](advanced-usage.md) - Automation and commands
