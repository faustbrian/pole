# Basic Usage

This guide covers all the core operations you'll use daily with Toggl feature flags.

## Defining Features

### Simple Boolean Features

The simplest way to define a feature is with a boolean value:

```php
use Cline\Toggl\Toggl;

// Always active
Toggl::define('maintenance-mode', true);

// Always inactive
Toggl::define('upcoming-feature', false);
```

### Using Enums for Type Safety

Define features using BackedEnum for better type safety and IDE autocompletion:

```php
enum FeatureFlag: string
{
    case NewDashboard = 'new-dashboard';
    case BetaApi = 'beta-api';
    case PremiumFeatures = 'premium-features';
}

// Define with enum (no need for ->value)
Toggl::define(FeatureFlag::NewDashboard, true);
Toggl::define(FeatureFlag::BetaApi, fn($user) => $user?->isBetaTester());
```

### Closure-Based Features

For dynamic evaluation, use a closure that receives the current context:

```php
// User-based feature
Toggl::define('premium-dashboard', function ($user) {
    return $user?->subscription?->isPremium() ?? false;
});

// Environment-based feature
Toggl::define('debug-mode', function () {
    return app()->environment('local');
});

// Complex logic
Toggl::define('advanced-search', function ($user) {
    if (!$user) {
        return false;
    }

    return $user->hasRole('admin') ||
           $user->subscription->plan === 'enterprise';
});
```

### Features with Values

Features can store any value, not just booleans:

```php
// String value
Toggl::define('api-version', 'v2');

// Numeric value
Toggl::define('rate-limit', 1000);

// Array value
Toggl::define('ui-config', [
    'theme' => 'dark',
    'sidebar' => 'collapsed',
    'layout' => 'grid',
]);
```

### Fluent Definition API

Define features with a fluent, chainable interface:

```php
// Define with resolver
Toggl::definition('premium')
    ->resolvedBy(fn($user) => $user->subscription === 'premium')
    ->register();

// Define with default value
Toggl::definition('theme')
    ->defaultTo('dark')
    ->register();

// Define with description metadata
Toggl::definition('api-access')
    ->describedAs('API access for integrations')
    ->resolvedBy(fn($user) => $user->hasApiKey())
    ->register();

// Chain description before or after
Toggl::definition('export-limit')
    ->describedAs('Maximum exports per month')
    ->resolvedBy(function ($user) {
        return match ($user->tier) {
            'pro' => 100,
            'enterprise' => 1000,
            default => 10,
        };
    })
    ->register();

// Define role-based feature
Toggl::definition('admin-panel')
    ->describedAs('Administrative panel access')
    ->resolvedBy(fn($user) => $user->role === 'admin')
    ->register();
```

**Use cases:**
- **Readable definitions**: Self-documenting feature definitions
- **Metadata tracking**: Associate descriptions with features
- **Complex resolvers**: Multi-step feature resolution logic
- **Type-safe defaults**: Define default values with type inference

**Behavior:**
- Requires either `resolvedBy()` or `defaultTo()`
- Description is optional metadata (not stored, only on conductor)
- Resolver receives context parameter
- Can redefine features (last registration wins)
- Terminal method: `register()`

## Checking Features

### Active/Inactive Checks

```php
// Check if active
if (Toggl::active('new-dashboard')) {
    // Feature is enabled
}

// Alias: isEnabled (reads more naturally in some contexts)
if (Toggl::isEnabled('new-dashboard')) {
    // Feature is enabled
}

// Check if inactive
if (Toggl::inactive('beta-feature')) {
    // Feature is disabled
}

// Alias: isDisabled
if (Toggl::isDisabled('beta-feature')) {
    // Feature is disabled
}

// With enums (type-safe)
if (Toggl::active(FeatureFlag::NewDashboard)) {
    // Feature is enabled
}
```

### Contextual Checks

Check features for specific users, teams, or any context:

```php
// For specific user
$user = User::find(123);
if (Toggl::for($user)->active('premium-features')) {
    // User has premium features
}

// For team
if (Toggl::for($team)->active('team-analytics')) {
    // Team has analytics
}

// For string context
if (Toggl::for('admin')->active('debug-panel')) {
    // Admin debug panel
}
```

### Multiple Feature Checks

```php
// All features must be active
if (Toggl::allAreActive(['auth', 'api', 'dashboard'])) {
    // All three features are enabled
}

// At least one feature must be active
if (Toggl::someAreActive(['beta-ui', 'new-ui', 'experimental-ui'])) {
    // At least one UI variation is enabled
}

// Alias: anyAreActive (matches Laravel Collection's any() method)
if (Toggl::anyAreActive(['beta-ui', 'new-ui', 'experimental-ui'])) {
    // At least one UI variation is enabled
}

// All features must be inactive
if (Toggl::allAreInactive(['maintenance', 'outage'])) {
    // System is operational
}

// At least one feature is inactive
if (Toggl::someAreInactive(['feature-a', 'feature-b'])) {
    // Not all features are enabled
}

// Alias: anyAreInactive
if (Toggl::anyAreInactive(['feature-a', 'feature-b'])) {
    // Not all features are enabled
}

// Works with enums and mixed arrays
if (Toggl::allAreActive([FeatureFlag::Auth, FeatureFlag::Api, 'legacy-feature'])) {
    // Mix enums and strings
}
```

### Batch Evaluation (Multiple Features × Multiple Contexts)

When you need to check multiple features for multiple contexts simultaneously, use batch evaluation. This is useful for dashboards, admin panels, or any scenario where you need to know the feature state for several users at once.

```php
// Create lazy evaluations
$results = Toggl::evaluate([
    Toggl::lazy('premium')->for($user1),
    Toggl::lazy('premium')->for($user2),
    Toggl::lazy('premium')->for($user3),
    Toggl::lazy('analytics')->for($user1),
    Toggl::lazy('analytics')->for($user2),
]);

// Aggregate checks
$results->all();    // true if ALL evaluations are truthy
$results->any();    // true if ANY evaluation is truthy
$results->none();   // true if ALL evaluations are falsy

// Counting
$results->count();          // Total evaluations (5)
$results->countActive();    // Number of truthy results
$results->countInactive();  // Number of falsy results

// Filter by feature
$premiumResults = $results->forFeature('premium');
$premiumResults->all();     // true if all users have premium
$premiumResults->any();     // true if any user has premium

// Filter by context
$user1Results = $results->forContext($user1);
$user1Results->all();       // true if user1 has all checked features

// Filter by state
$activeOnly = $results->active();     // Only truthy evaluations
$inactiveOnly = $results->inactive(); // Only falsy evaluations

// Chain filters
$results->forFeature('premium')->active()->count(); // Active premium users
```

**Data Access Methods:**

```php
// Simple key-value array
$results->toArray();
// ['premium|App\Models\User|1' => true, 'premium|App\Models\User|2' => false, ...]

// Group by feature
$results->groupByFeature();
// [
//     'premium' => ['App\Models\User|1' => true, 'App\Models\User|2' => false],
//     'analytics' => ['App\Models\User|1' => true, ...]
// ]

// Group by context
$results->groupByContext();
// [
//     'App\Models\User|1' => ['premium' => true, 'analytics' => true],
//     'App\Models\User|2' => ['premium' => false, 'analytics' => false],
// ]

// Get unique feature names
$results->features(); // ['premium', 'analytics']

// Get raw entries for custom processing
$results->entries();  // EvaluationEntry[]

// Laravel Collection for advanced operations
$results->collect()->filter(fn($e) => $e->isActive())->map(...);

// Empty checks
$results->isEmpty();
$results->isNotEmpty();
```

**Works with BackedEnum:**

```php
enum Feature: string
{
    case Premium = 'premium';
    case Analytics = 'analytics';
    case Reporting = 'reporting';
}

$results = Toggl::evaluate([
    Toggl::lazy(Feature::Premium)->for($user1),
    Toggl::lazy(Feature::Analytics)->for($user1),
    Toggl::lazy(Feature::Reporting)->for($user2),
]);
```

**Real-World Example - Admin Dashboard:**

```php
// Check feature access for all team members
$team = Team::with('users')->find($teamId);
$features = ['premium', 'analytics', 'api-access', 'export'];

$evaluations = [];
foreach ($team->users as $user) {
    foreach ($features as $feature) {
        $evaluations[] = Toggl::lazy($feature)->for($user);
    }
}

$results = Toggl::evaluate($evaluations);

// Build dashboard data
$dashboard = [
    'total_checks' => $results->count(),
    'active_count' => $results->countActive(),
    'by_feature' => [],
    'by_user' => [],
];

foreach ($features as $feature) {
    $featureResults = $results->forFeature($feature);
    $dashboard['by_feature'][$feature] = [
        'active' => $featureResults->countActive(),
        'total' => $featureResults->count(),
    ];
}

foreach ($team->users as $user) {
    $userResults = $results->forContext($user);
    $dashboard['by_user'][$user->id] = [
        'active_features' => $userResults->countActive(),
        'all_active' => $userResults->all(),
    ];
}
```

**When to use batch evaluation:**
- Checking features for multiple users (admin dashboards, reports)
- Bulk permission checks before operations
- Feature matrix displays
- Analytics and reporting on feature adoption
- Any scenario with multiple features × multiple contexts

**Difference from `batch()`:**
- `batch()` is for **activation/deactivation** (write operations)
- `evaluate()` is for **checking status** (read operations)

## Retrieving Values

### Single Value

```php
// Get feature value (returns mixed)
$apiVersion = Toggl::value('api-version'); // 'v2'
$rateLimit = Toggl::value('rate-limit');   // 1000
$config = Toggl::value('ui-config');       // ['theme' => 'dark', ...]

// With context
$userTheme = Toggl::for($user)->value('theme-preference');
```

### Multiple Values

```php
// Get multiple values at once
$values = Toggl::values(['api-version', 'rate-limit', 'ui-config']);
// [
//     'api-version' => 'v2',
//     'rate-limit' => 1000,
//     'ui-config' => [...]
// ]
```

### Value with Check

Check if feature matches a specific value:

```php
// In Blade
@feature('api-version', 'v2')
    <!-- Using API v2 -->
@endfeature

// In PHP
if (Toggl::value('api-version') === 'v2') {
    // Use v2 endpoints
}
```

## Activating and Deactivating Features

### Global Activation/Deactivation

```php
// Activate (sets to true)
Toggl::activate('new-feature');

// Activate with custom value
Toggl::activate('api-version', 'v3');

// Deactivate (sets to false)
Toggl::deactivate('old-feature');

// Activate multiple features
Toggl::activate(['feature-a', 'feature-b', 'feature-c']);

// Deactivate multiple features
Toggl::deactivate(['old-ui', 'deprecated-api']);
```

### Contextual Activation/Deactivation

```php
// Traditional context-first pattern
Toggl::for($user)->activate('beta-access');

// Activate with value
Toggl::for($user)->activate('theme', 'dark');

// Deactivate for specific user
Toggl::for($user)->deactivate('beta-access');

// Activate for team
Toggl::for($team)->activate('team-dashboard');
```

### Conductor Pattern (Feature-First)

For a more fluent API, use the conductor pattern where you specify the feature first:

```php
// Activate feature for single context
Toggl::activate('premium')->for($user);

// Activate for multiple contexts
Toggl::activate('premium')->for([$user1, $user2, $user3]);

// Activate with custom value - use withValue() chain
Toggl::activate('theme')->withValue('dark')->for($user);

// Activate with array/object values
Toggl::activate('settings')->withValue([
    'notifications' => true,
    'theme' => 'dark',
    'language' => 'en',
])->for($user);

// Activate multiple features for context
Toggl::activate(['feat-1', 'feat-2', 'feat-3'])->for($user);

// Deactivate feature for context(s)
Toggl::deactivate('beta')->for($user);
Toggl::deactivate('beta')->for([$user1, $user2]);
```

**Why use the conductor pattern?**
- More natural when activating the same feature(s) for multiple contexts
- Mirrors common patterns from packages like Warden
- Reads naturally: "activate premium for these users"
- The `withValue()` chain makes value assignment explicit and discoverable
- Both patterns work identically - use whichever reads better for your use case

### Context Grouping (Within)

When performing multiple operations on the same context, use `within()` to avoid repetition:

```php
// Traditional approach (repetitive)
Toggl::for($team)->activate('dashboard');
Toggl::for($team)->activate('analytics');
Toggl::for($team)->activate('reporting');
Toggl::for($team)->deactivate('legacy-ui');

// Context grouping approach (cleaner)
Toggl::within($team)
    ->activate('dashboard')
    ->activate('analytics')
    ->activate('reporting')
    ->deactivate('legacy-ui');

// Mix with values
Toggl::within($user)
    ->activate('premium')
    ->activateWithValue('theme', 'dark')
    ->activateWithValue('language', 'es')
    ->deactivate('beta-features');

// Works with groups too
Toggl::within($team)
    ->activateGroup('premium-features')
    ->deactivate('old-feature')
    ->activate('new-feature');
```

### Bulk Values

Set multiple feature/value pairs at once for one or more contexts:

```php
// Set multiple user preferences
Toggl::bulk([
    'theme' => 'dark',
    'language' => 'es',
    'timezone' => 'America/New_York',
])->for($user);

// Set configuration for multiple contexts
Toggl::bulk([
    'plan' => 'enterprise',
    'max-members' => 100,
    'features-enabled' => ['analytics', 'reporting', 'api-access'],
])->for([$team1, $team2, $team3]);

// Complex values supported
Toggl::bulk([
    'string-val' => 'text',
    'int-val' => 42,
    'bool-val' => true,
    'array-val' => ['a', 'b', 'c'],
    'null-val' => null,
])->for($user);

// Common scenarios
// New user onboarding
Toggl::bulk([
    'theme' => 'light',
    'language' => 'en',
    'timezone' => 'UTC',
    'email-verified' => false,
    'onboarding-completed' => false,
])->for($newUser);

// API configuration
Toggl::bulk([
    'api-enabled' => true,
    'api-version' => 'v2',
    'rate-limit' => 1000,
    'allowed-endpoints' => ['users', 'posts', 'comments'],
])->for($user);
```

**Difference from batch():**
- `bulk()` sets multiple feature/value pairs → single context(s)
- `batch()` does Cartesian product: features × contexts

### Batch Operations (Cartesian Product)

Activate/deactivate multiple features for multiple contexts efficiently:

```php
// Single feature for multiple users
Toggl::batch()
    ->activate('premium')
    ->for([$user1, $user2, $user3]);

// Multiple features for single user
Toggl::batch()
    ->activate(['premium', 'analytics', 'api-access'])
    ->for($user);

// Cartesian product: all features × all contexts (9 operations)
Toggl::batch()
    ->activate(['premium', 'analytics', 'api-access'])
    ->for([$user1, $user2, $user3]);

// With custom values
Toggl::batch()
    ->activate('theme', 'dark')
    ->for([$user1, $user2]);

// Deactivation
Toggl::batch()
    ->deactivate(['trial-1', 'trial-2'])
    ->for($expiredUsers);

// Mass activation for new cohort
Toggl::batch()
    ->activate(['premium-ui', 'advanced-search', 'export', 'analytics'])
    ->for($newPremiumUsers);
```

**How it works:**
- Executes all features × all contexts (Cartesian product)
- Single batch operation for efficiency
- Useful for mass rollouts, cohort management, trial expirations

### Permission-Style API (Warden-inspired)

Grant or revoke feature access with permission-style syntax:

```php
// Allow single feature for single user
Toggl::allow($user)->to('premium-dashboard');

// Allow multiple features for single user
Toggl::allow($user)->to(['premium', 'analytics', 'api-access']);

// Allow single feature for multiple users
Toggl::allow([$user1, $user2, $user3])->to('beta-access');

// Allow multiple features for multiple users (Cartesian product)
Toggl::allow([$user1, $user2])->to(['feature-1', 'feature-2', 'feature-3']);

// Deny (revoke) access
Toggl::deny($user)->to('beta-features');
Toggl::deny([$user1, $user2])->to(['trial-1', 'trial-2']);

// Group operations
Toggl::allow($user)->toGroup('premium');
Toggl::deny($user)->toGroup('experimental');

// Grant premium to new subscribers
Toggl::allow($newSubscribers)->to([
    'premium-ui',
    'advanced-search',
    'export',
    'analytics',
    'priority-support',
]);

// Revoke trial access on expiration
Toggl::deny($expiredUsers)->to(['trial-1', 'trial-2', 'trial-3']);
```

**When to use:**
- Onboarding flows: "allow user to features"
- Access revocation: "deny user from features"
- Clear permission semantics
- Bulk user management operations

**How it works:**
- `allow()` activates features (same as `activate()`)
- `deny()` deactivates features (same as `deactivate()`)
- Supports Cartesian product for bulk operations
- More intuitive for permission-based scenarios

### Strategy-Based Activation

Apply intelligent activation strategies for gradual rollouts and experiments:

```php
// Percentage-based rollout (0-100%)
Toggl::strategy('new-dashboard')
    ->percentage(25)
    ->for($user);

// Time-based activation (scheduled features)
Toggl::strategy('holiday-sale')
    ->from('2025-12-01')
    ->until('2025-12-31')
    ->for($user);

// Global time-based activation (no context)
Toggl::strategy('scheduled-maintenance')
    ->from('2025-06-01')
    ->until('2025-06-02')
    ->activate();

// Only start date (runs forever after start)
Toggl::strategy('permanent-feature')
    ->from('2025-01-01')
    ->for($user);

// Only end date (active until expiration)
Toggl::strategy('temporary-promo')
    ->until('2025-03-31')
    ->for($user);

// Variant distribution for A/B/n testing
Toggl::strategy('ui-experiment')
    ->variants(['control' => 50, 'variant-a' => 30, 'variant-b' => 20])
    ->for($user);
```

**Use cases:**
- **Percentage rollout**: Gradual feature deployment (start 10%, increase to 100%)
- **Time-based**: Seasonal features, promotions, scheduled releases
- **Variants**: A/B testing, multivariate experiments

**How it works:**
- Percentage uses CRC32 hashing for consistent user assignment
- Time-based checks current date against `from`/`until` range
- Variant strategy integrates with variant conductor
- All assignments are deterministic per user

### Feature Dependencies

Enforce prerequisite features before activating dependent features:

```php
// Basic dependency pattern
Toggl::require('basic-analytics')
    ->before('advanced-analytics')
    ->for($user);

// Multiple prerequisites (all must be active)
Toggl::require(['auth', 'payment', 'subscription'])
    ->before('premium-suite')
    ->for($user);

// Alternative pattern: activate with requirements
Toggl::activate('checkout')
    ->requires(['auth', 'payment'])
    ->for($user);

// Single prerequisite
Toggl::activate('analytics')
    ->requires('basic-plan')
    ->for($user);
```

**Use cases:**
- **Tiered access**: Require lower tiers before granting higher tiers
- **Workflow progression**: Ensure onboarding steps completed
- **Feature gates**: Prevent advanced features without prerequisites

**Behavior:**
- Throws `RuntimeException` if any prerequisite is missing
- Error message lists all missing prerequisites
- Only activates dependent feature if all requirements met
- Works with both single and multiple prerequisites

**Example errors:**
```php
// Missing one prerequisite
"Cannot activate 'premium-suite': missing prerequisites [subscription]"

// Missing multiple prerequisites
"Cannot activate 'premium-suite': missing prerequisites [payment, subscription]"
```

### Copy Features Between Contexts

Copy features from one context to another efficiently:

```php
// Copy all features from source to target
Toggl::from($adminTemplate)->copyTo($newAdmin);

// Selective copy with only() - whitelist approach
Toggl::from($prodUser)
    ->only(['premium-ui', 'advanced-search', 'export'])
    ->copyTo($testUser);

// Filtered copy with except() - blacklist approach
Toggl::from($oldAccount)
    ->except(['trial-banner', 'onboarding-wizard'])
    ->copyTo($newAccount);
```

**Use cases:**
- **Template users**: Copy features from admin template to new admins
- **Account migration**: Transfer features excluding temporary ones
- **Testing**: Copy production-safe features to test accounts

**Behavior:**
- Copies all active features with their values
- `only()` creates whitelist (only specified features copied)
- `except()` creates blacklist (all except specified copied)
- Overwrites existing features on target
- Does nothing if source has no features

### Cascade

Activate or deactivate a primary feature along with all dependent features:

```php
// Activate premium and all dependent features
Toggl::cascade('premium')
    ->activating(['analytics', 'export', 'api-access'])
    ->for($user);

// Deactivate premium and cascade to dependents
Toggl::cascade('premium')
    ->deactivating(['analytics', 'export', 'api-access'])
    ->for($user);

// Subscription upgrade with all features
Toggl::cascade('enterprise-plan')
    ->activating([
        'advanced-analytics',
        'priority-support',
        'custom-integrations',
        'api-access',
        'white-label',
    ])
    ->for($organization);

// Module activation with sub-features
Toggl::cascade('crm-module')
    ->activating(['contacts', 'deals', 'tasks', 'calendar'])
    ->for($user);
```

**Use cases:**
- **Subscription changes**: Upgrade/downgrade with all tier features
- **Module management**: Enable/disable feature bundles
- **Beta programs**: Activate experimental features together
- **Dependency cleanup**: Remove features and their dependents

### Testing / Fakes

Create test doubles for features during testing:

```php
// Fake single feature as enabled
Toggl::testing('premium')
    ->fake(true)
    ->for($user);

// Fake feature with specific value
Toggl::testing('api-limit')
    ->fake(100)
    ->for($user);

// Fake multiple features at once
Toggl::testing()
    ->fakeMany([
        'premium' => true,
        'analytics' => true,
        'api-limit' => 100,
        'theme' => 'dark',
    ])
    ->for($user);

// Fake globally for all contexts
Toggl::testing('debug-mode')
    ->fake(true)
    ->globally();

// Test premium features
Toggl::testing()
    ->fakeMany([
        'premium' => true,
        'export-limit' => 1000,
        'priority-support' => true,
    ])
    ->for($testUser);
```

**Use cases:**
- **Unit testing**: Test premium-only code paths
- **Integration testing**: Set up complete feature environments
- **Edge case testing**: Test specific limits and values
- **Fallback testing**: Disable features to test fallback behavior

### Pipeline

Chain multiple feature operations in a single fluent pipeline:

```php
// Subscription upgrade - remove old, add new
Toggl::pipeline()
    ->deactivate(['basic-dashboard', 'basic-support'])
    ->activate(['premium-dashboard', 'analytics', 'export', 'priority-support'])
    ->for($user);

// Feature migration with logging
Toggl::pipeline()
    ->tap(fn() => logger('Starting migration'))
    ->deactivate('old-api')
    ->tap(fn() => logger('Deactivated old API'))
    ->activate('new-api')
    ->tap(fn() => logger('Activated new API'))
    ->for($user);

// Staged rollout
Toggl::pipeline()
    ->activate(['new-ui-phase-1', 'new-ui-phase-2', 'new-ui-phase-3'])
    ->deactivate('old-ui')
    ->for($user);

// Beta enrollment with cleanup
Toggl::pipeline()
    ->deactivate('old-beta')
    ->activate(['new-beta-ui', 'new-beta-api', 'debug-mode'])
    ->tap(fn($context) => event(new BetaEnrolled($context)))
    ->for($user);
```

**Use cases:**
- **Subscription changes**: Atomic upgrade/downgrade operations
- **Feature migrations**: Replace old features with new ones
- **Staged rollouts**: Activate multiple phases in order
- **Complex workflows**: Chain activations, deactivations, and callbacks

**Behavior:**
- Operations execute in order (activate → deactivate → tap)
- Cascade: Primary feature activated first, then dependents
- Cascade: Dependents deactivated first, then primary
- Idempotent operations (safe to cascade already-active features)
- Empty dependent array only affects primary feature
- Tap callbacks receive context parameter

### Tap (Side Effects)

Execute callbacks without breaking the fluent chain:

```php
// Log during activation
Toggl::activate('premium')
    ->tap(fn($conductor) => Log::info("Activating: {$conductor->features()}"))
    ->for($user);

// Multiple taps in chain
Toggl::activate('premium')
    ->tap(fn() => Cache::forget('user-features'))
    ->tap(fn() => event(new PremiumActivated($user)))
    ->tap(fn() => Log::info('Premium activated'))
    ->for($user);

// Access conductor data in tap
Toggl::activate('theme')
    ->withValue('dark')
    ->tap(function($conductor) {
        Log::info('Setting value', [
            'feature' => $conductor->features(),
            'value' => $conductor->value(),
        ]);
    })
    ->for($user);

// Audit trail
Toggl::activate('premium')
    ->tap(function($conductor) use ($user) {
        AuditLog::create([
            'action' => 'feature_activation',
            'feature' => $conductor->features(),
            'user_id' => $user->id,
        ]);
    })
    ->for($user);

// Cache invalidation
Toggl::activate('settings')
    ->withValue(['theme' => 'dark', 'lang' => 'es'])
    ->tap(fn() => Cache::tags(['user-settings'])->flush())
    ->for($user);
```

## Transaction Conductor

Execute atomic feature operations with automatic rollback on failure:

```php
// Basic transaction
Toggl::transaction()
    ->activate('premium')
    ->activate('analytics')
    ->commit($user);

// Subscription upgrade
Toggl::transaction()
    ->deactivate(['basic-plan', 'basic-support'])
    ->activate(['premium-plan', 'priority-support', 'analytics', 'export'])
    ->commit($user);

// Manual rollback capability
$transaction = Toggl::transaction()
    ->deactivate('v1-api')
    ->activate('v2-api');

$transaction = $transaction->commit($user);

// If something goes wrong, rollback
if ($migrationFailed) {
    $transaction->rollback($user);
}

// Failure handling with callback
Toggl::transaction()
    ->activate('premium')
    ->activate('analytics')
    ->onFailure(function($exception, $context) {
        Log::error('Transaction failed', [
            'error' => $exception->getMessage(),
            'user' => $context->id,
        ]);
    })
    ->commit($user);
```

**Key Features:**
- Atomic operations: All succeed or all rollback
- Initial state captured on commit
- `rollback()` restores original state
- `onFailure()` callback for error handling
- Returns new instance with captured state

## Metadata Conductor

Manage feature metadata with fluent API for set, merge, forget, and clear operations:

```php
// Set metadata (replaces existing)
Toggl::metadata('premium')
    ->with([
        'plan' => 'monthly',
        'price' => 9.99,
        'trial_ends' => now()->addDays(14),
    ])
    ->for($user);

// Merge with existing metadata
Toggl::metadata('premium')
    ->merge([
        'upgraded_at' => now(),
        'previous_plan' => 'basic',
    ])
    ->for($user);

// Forget specific keys
Toggl::metadata('premium')
    ->forget(['trial_ends', 'previous_plan'])
    ->for($user);

// Clear all metadata
Toggl::metadata('premium')
    ->clear()
    ->for($user);

// Subscription lifecycle example
Toggl::metadata('premium')
    ->with(['plan' => 'monthly', 'price' => 9.99])
    ->for($user);

// Upgrade
Toggl::metadata('premium')
    ->merge(['plan' => 'yearly', 'price' => 99.99, 'upgraded_at' => now()])
    ->for($user);

// Cleanup
Toggl::metadata('premium')
    ->forget(['upgraded_at'])
    ->for($user);
```

**Key Features:**
- `with()` replaces all metadata
- `merge()` merges with existing
- `forget()` removes specific keys
- `clear()` removes all metadata
- Works with nested arrays

**Note:** `tap()` executes before the terminal `for()` method, making it perfect for logging, event dispatching, cache invalidation, and other side effects.

## Audit Conductor

Track feature state changes with audit history logging for compliance and debugging:

```php
// Log activation with reason
Toggl::audit('premium')
    ->activate()
    ->withReason('Subscription upgraded')
    ->for($user);

// Log deactivation with actor
Toggl::audit('trial')
    ->deactivate()
    ->withReason('Trial period ended')
    ->withActor($admin)
    ->for($user);

// Retrieve audit history
$history = Toggl::audit('premium')->history($user);
// Returns: [
//   [
//     'action' => 'activate',
//     'reason' => 'Subscription upgraded',
//     'timestamp' => '2024-01-15T10:30:00Z'
//   ],
//   ...
// ]

// Clear audit history
Toggl::audit('premium')->clearHistory($user);

// Compliance tracking
Toggl::audit('data-export')
    ->deactivate()
    ->withReason('GDPR compliance - data retention policy')
    ->withActor($admin)
    ->for($user);

// Beta enrollment audit trail
Toggl::audit('beta-ui')
    ->activate()
    ->withReason('User opted into beta program')
    ->for($user);
```

**Key Features:**
- `activate()` / `deactivate()` - Set the action to log
- `withReason()` - Add context for the change
- `withActor()` - Track who made the change
- `history()` - Retrieve chronological audit trail
- `clearHistory()` - Remove audit logs
- Automatic timestamp on all entries
- Each feature has separate history

**Use Cases:**
- Compliance and regulatory requirements
- Debugging feature state changes
- User behavior tracking
- Admin action logging
- Subscription lifecycle tracking

## Snapshot Conductor

Capture and restore complete feature states for backups, rollbacks, and testing with comprehensive audit trails:

```php
// Capture current state
$snapshotId = Toggl::snapshot()->capture($user);

// Restore from snapshot
Toggl::snapshot()->restore($snapshotId, $user);

// Named snapshots with metadata
$backupId = Toggl::snapshot()
    ->withLabel('pre-migration')
    ->withMetadata(['reason' => 'v2 API migration', 'team' => 'backend'])
    ->capture($user, createdBy: $admin);

// Restore specific features only (granular restore)
Toggl::snapshot()->restorePartial($snapshotId, $user, [
    'api-version',
    'feature-flags',
], restoredBy: $admin);

// List all snapshots
$snapshots = Toggl::snapshot()->list($user);
// Returns: [
//   [
//     'id' => 'snapshot_...',
//     'label' => 'pre-migration',
//     'timestamp' => '2024-01-15T10:30:00Z',
//     'features' => [...],
//     'metadata' => [...],
//     'created_by' => ['type' => 'App\Models\User', 'id' => 1],
//     'restored_at' => '2024-01-15T11:00:00Z',
//     'restored_by' => ['type' => 'App\Models\User', 'id' => 1]
//   ],
//   ...
// ]

// Get specific snapshot
$snapshot = Toggl::snapshot()->get($snapshotId, $user);

// Get event history (audit trail)
$events = Toggl::snapshot()->getEventHistory($snapshotId);
// Returns: [
//   [
//     'id' => 'event_...',
//     'type' => 'created',
//     'performed_by' => ['type' => 'App\Models\User', 'id' => 1],
//     'metadata' => ['feature_count' => 5],
//     'created_at' => '2024-01-15T10:30:00Z'
//   ],
//   [
//     'id' => 'event_...',
//     'type' => 'restored',
//     'performed_by' => ['type' => 'App\Models\User', 'id' => 2],
//     'metadata' => ['features_restored' => ['api-version', 'auth']],
//     'created_at' => '2024-01-15T11:00:00Z'
//   ],
//   ...
// ]

// Delete snapshot with audit
Toggl::snapshot()->delete($snapshotId, $user, deletedBy: $admin);

// Clear all snapshots with audit
Toggl::snapshot()->clearAll($user, deletedBy: $admin);

// Migration backup example with full audit trail
$backup = Toggl::snapshot()
    ->withLabel('pre-v2-migration')
    ->withMetadata([
        'migration_id' => 'v2-2024-01',
        'rollback_plan' => 'Restore API v1 endpoints',
    ])
    ->capture($user, createdBy: $admin);

// Perform migration
Toggl::for($user)->deactivate(['v1-api', 'v1-ui']);
Toggl::for($user)->activate(['v2-api', 'v2-ui']);

// Rollback if needed
if ($migrationFailed) {
    Toggl::snapshot()->restore($backup, $user, restoredBy: $admin);

    // Review what was restored
    $events = Toggl::snapshot()->getEventHistory($backup);
    Log::info('Migration rolled back', $events);
}
```

**Key Features:**
- `capture($context, $createdBy)` - Save current feature state with audit
- `restore($id, $context, $restoredBy)` - Revert to saved state with audit
- `restorePartial($id, $context, $features, $restoredBy)` - Restore specific features only
- `withLabel($label)` - Add descriptive label
- `withMetadata($metadata)` - Attach custom metadata
- `getEventHistory($id)` - Retrieve complete audit trail
- `list($context)` - Get all snapshots
- `get($id, $context)` - Retrieve specific snapshot
- `delete($id, $context, $deletedBy)` - Remove snapshot with audit
- `clearAll($context, $deletedBy)` - Delete all snapshots with audit
- Captures both feature activation and values
- Unique IDs for each snapshot

**Storage Configuration:**
Configure snapshot functionality with dedicated storage driver for optimized performance and complete historical tracking:

```php
// In config/toggl.php
use Cline\Toggl\Enums\SnapshotDriver;

'snapshots' => [
    // Enable or disable snapshot functionality
    'enabled' => env('TOGGL_SNAPSHOTS_ENABLED', true),

    // Use database for complete historical tracking (recommended)
    'driver' => SnapshotDriver::Database,

    // Or use array for in-memory snapshots (testing)
    'driver' => SnapshotDriver::Array,

    // Or use cache for temporary snapshots (TTL-based)
    'driver' => SnapshotDriver::Cache,

    // null = use same driver as main feature store
    'driver' => null,
],
```

**Driver Capabilities:**

| Driver   | Historical Tracking | Event Audit | Granular Restore | Persistence |
|----------|-------------------|-------------|-----------------|-------------|
| Database | Full              | Complete    | Yes             | Permanent   |
| Array    | Session           | In-memory   | Yes             | Request     |
| Cache    | TTL-based         | In-memory   | Yes             | TTL         |

**Database Driver** (Recommended for Production):
- Dedicated tables: `feature_snapshots`, `feature_snapshot_entries`, `feature_snapshot_events`
- Complete audit trail with who/when/why
- Granular restore of individual features
- Event history: created, restored, deleted, partial_restore
- Permanent storage for compliance and debugging

**Array/Cache Drivers** (Development/Testing):
- Stores snapshots in `__snapshots__` key
- Basic event tracking in memory
- Suitable for temporary states
- Faster for ephemeral use cases

**Use Cases:**
- Migration backup and rollback (database driver)
- A/B test variant switching (any driver)
- Testing environment setup (array driver)
- State recovery after errors (database driver)
- Feature configuration versioning (database driver)
- Compliance audit trails (database driver)
- Debugging feature state changes (database driver)
- Temporary state captures (cache/array driver)

## Cleanup Conductor

Remove stale data and old records based on retention policies:

```php
// Clean old snapshots (older than 30 days)
$removed = Toggl::cleanup()
    ->snapshots()
    ->olderThan(30)
    ->for($user);

// Keep only latest 5 snapshots
$removed = Toggl::cleanup()
    ->snapshots()
    ->keepLatest(5)
    ->for($user);

// Clean old audit history
$removed = Toggl::cleanup()
    ->auditHistory()
    ->olderThan(90)
    ->for($user);


// Combine filters (remove old + keep latest)
$removed = Toggl::cleanup()
    ->snapshots()
    ->olderThan(30)
    ->keepLatest(10)
    ->for($user);

// Scheduled maintenance example
// Run daily: clean snapshots older than 30 days, keep latest 5
$snapshotsRemoved = Toggl::cleanup()
    ->snapshots()
    ->olderThan(30)
    ->keepLatest(5)
    ->for($user);

// Compliance retention: keep audit logs for 7 years
$auditRemoved = Toggl::cleanup()
    ->auditHistory()
    ->olderThan(2555) // ~7 years
    ->for($user);
```

**Key Features:**
- `snapshots()` - Clean old snapshots
- `auditHistory()` - Clean old audit logs
- `olderThan($days)` - Keep only items newer than N days
- `keepLatest($count)` - Keep only N most recent items
- Filters can be combined
- Returns count of items removed

**Use Cases:**
- Scheduled maintenance cleanup
- Compliance retention policies
- Migration cleanup after rollback
- Database size management
- Removing stale test data

## Schedule Conductor

Schedule time-based feature activation and deactivation:

```php
// Activate at specific time
Toggl::schedule('promotion')
    ->activateAt(new DateTime('2024-12-25 00:00:00'))
    ->for($user);

// Deactivate at specific time
Toggl::schedule('trial')
    ->deactivateAt(new DateTime('+30 days'))
    ->for($user);

// Active only within time window
$isActive = Toggl::schedule('flash-sale')
    ->between(
        new DateTime('2024-12-25 00:00:00'),
        new DateTime('2024-12-25 23:59:59')
    )
    ->for($user);

// Use string dates (supports strtotime)
Toggl::schedule('beta-enrollment')
    ->between('-3 days', '+4 days')
    ->for($user);

// Activate with custom value
Toggl::schedule('premium')
    ->activateAt('-1 hour')
    ->withValue(['tier' => 'gold', 'credits' => 100])
    ->for($user);

// Save schedule for later evaluation
$scheduleId = Toggl::schedule('maintenance-mode')
    ->between('+8 hours', '+10 hours')
    ->withValue(['message' => 'System maintenance in progress'])
    ->save($user);

// List all saved schedules
$schedules = Toggl::schedule('any')->listSchedules($user);

// Delete saved schedule
Toggl::schedule('maintenance-mode')->deleteSchedule($scheduleId, $user);

// Apply all saved schedules (returns count of state changes)
$updated = Toggl::schedule('any')->applyAll($user);

// Flash sale example
$isActive = Toggl::schedule('christmas-sale')
    ->between('2024-12-25 00:00:00', '2024-12-25 23:59:59')
    ->withValue(['discount' => 50])
    ->for($user);

if ($isActive) {
    $discount = Toggl::for($user)->value('christmas-sale')['discount'];
}

// Trial expiration
Toggl::schedule('trial')
    ->deactivateAt((new DateTime())->modify('+30 days'))
    ->for($user);

// Scheduled maintenance window
$scheduleId = Toggl::schedule('maintenance-mode')
    ->between('2024-12-31 22:00:00', '2024-12-31 23:59:59')
    ->withValue(['message' => 'Scheduled maintenance'])
    ->save($user);
```

**Key Features:**
- `activateAt($time)` - Activate at specific time
- `deactivateAt($time)` - Deactivate at specific time
- `between($start, $end)` - Active only within time window
- `withValue($value)` - Set custom value when activated
- `save($context)` - Save schedule for later evaluation
- `listSchedules($context)` - List all saved schedules
- `deleteSchedule($id, $context)` - Delete saved schedule
- `applyAll($context)` - Apply all saved schedules
- Accepts DateTime objects or strings
- Returns boolean (is feature active)

**Use Cases:**
- Flash sales and limited-time promotions
- Trial period expirations
- Scheduled maintenance windows
- Beta enrollment periods
- Time-limited feature access
- Holiday-specific features
- External scheduler integration via `save()` and `applyAll()`

## Rollout Conductor

Gradual feature rollouts with percentage-based activation:

```php
// Roll out to 25% of users
Toggl::rollout('new-dashboard')
    ->toPercentage(25)
    ->for($user);

// Gradually increase rollout
Toggl::rollout('new-dashboard')
    ->toPercentage(50)
    ->for($user);

// Full rollout
Toggl::rollout('new-dashboard')
    ->toPercentage(100)
    ->for($user);

// Emergency rollback
Toggl::rollout('problematic-feature')
    ->toPercentage(0)
    ->for($user);

// Sticky rollouts (consistent user assignment)
$isActive = Toggl::rollout('beta-features')
    ->toPercentage(10)
    ->withStickiness(true)
    ->for($user);

// Custom seed for deterministic rollouts
$isActive = Toggl::rollout('experiment')
    ->toPercentage(50)
    ->withStickiness(true)
    ->withSeed('experiment-1')
    ->for($user);

// Non-sticky rollout (random each time)
$isActive = Toggl::rollout('temporary-banner')
    ->toPercentage(30)
    ->withStickiness(false)
    ->for($user);

// Canary deployment (1% of users)
Toggl::rollout('api-v2')
    ->toPercentage(1)
    ->withStickiness(true)
    ->withSeed('api-migration')
    ->for($user);

// A/B test with 50/50 split
$inVariantA = Toggl::rollout('experiment-variant-a')
    ->toPercentage(50)
    ->withStickiness(true)
    ->withSeed('ab-test-1')
    ->for($user);

if ($inVariantA) {
    // Show variant A
} else {
    // Show variant B (control)
}

// Gradual UI rollout phases
// Phase 1: Internal testing (10%)
Toggl::rollout('new-ui')
    ->toPercentage(10)
    ->withStickiness(true)
    ->withSeed('ui-v2')
    ->for($user);

// Phase 2: Early adopters (25%)
Toggl::rollout('new-ui')
    ->toPercentage(25)
    ->withStickiness(true)
    ->withSeed('ui-v2')
    ->for($user);

// Phase 3: General availability (100%)
Toggl::rollout('new-ui')
    ->toPercentage(100)
    ->for($user);
```

**Key Features:**
- `toPercentage($percent)` - Set rollout percentage (0-100)
- `withStickiness($bool)` - Enable consistent user assignment (default: true)
- `withSeed($seed)` - Custom seed for deterministic hashing
- Sticky rollouts maintain same users as percentage increases
- Consistent hashing ensures predictable user assignment
- Supports objects with `id` property, strings, or numeric contexts

**Use Cases:**
- Gradual new feature rollouts
- Canary deployments (1-5% of users)
- A/B testing and experiments
- Beta program enrollments
- Risk mitigation for new features
- Emergency rollbacks (set to 0%)
- Phased migrations
- Infrastructure capacity testing

## Dependency Conductor

Manage feature dependencies and prerequisites:

```php
// Require database-v2 before enabling reporting
Toggl::dependency('database-v2')
    ->before('advanced-reporting')
    ->for($user);

// Require multiple prerequisites
Toggl::dependency(['api-v2', 'auth-service'])
    ->before('new-dashboard')
    ->for($user);

// Single prerequisite (string or array)
Toggl::dependency('payment-gateway')
    ->before('checkout')
    ->for($user);

// Complex dependency chain
// Step 1: Ensure API v2 is active
Toggl::for($user)->activate('api-v2');

// Step 2: Activate features that depend on API v2
Toggl::dependency('api-v2')
    ->before('real-time-notifications')
    ->for($user);

Toggl::dependency('api-v2')
    ->before('websocket-support')
    ->for($user);

// Step 3: Activate features requiring multiple dependencies
Toggl::dependency(['api-v2', 'websocket-support'])
    ->before('live-collaboration')
    ->for($user);

// Beta program with prerequisites
Toggl::for($user)->activate('beta-enrollment');
Toggl::for($user)->activate('feature-flags-v2');

Toggl::dependency(['beta-enrollment', 'feature-flags-v2'])
    ->before('experimental-features')
    ->for($user);

// Service-based dependencies
Toggl::dependency('payment-processor')
    ->before('subscription-management')
    ->for($organization);

Toggl::dependency('email-service')
    ->before('notification-center')
    ->for($organization);

// Microservice dependencies
Toggl::dependency(['user-service', 'auth-service'])
    ->before('single-sign-on')
    ->for($tenant);
```

**Key Features:**
- `dependency($prerequisites)` - Define required feature(s)
- `before($feature)` - Specify dependent feature
- `for($context)` - Enforce dependencies (throws if not met)
- Supports single or multiple prerequisites
- Throws RuntimeException if prerequisites missing
- Ensures features activate in correct order

**Use Cases:**
- Feature activation ordering
- Service dependency management
- Microservice prerequisites
- Infrastructure readiness checks
- Beta program gating
- API versioning dependencies
- Module loading sequences
- Configuration prerequisites

## Strategy Conductor

Unified API for multiple feature activation strategies.

Gradual feature rollouts with percentage-based activation:

```php
use Cline\Toggl\Toggl;

// Percentage-based rollout (roll out to 25% of users)
Toggl::strategy('new-ui')
    ->percentage(25)
    ->for($user);

// Increase to 50%
Toggl::strategy('new-ui')
    ->percentage(50)
    ->for($user);

// Full rollout
Toggl::strategy('new-ui')
    ->percentage(100)
    ->for($user);
```

Time-based feature activation:

```php
// Activate from specific date
Toggl::strategy('holiday-theme')
    ->from('2024-12-01')
    ->for($user);

// Activate for date range
Toggl::strategy('summer-sale')
    ->from('2024-06-01')
    ->until('2024-08-31')
    ->for($user);

// Global time-based activation (no context needed)
Toggl::strategy('maintenance-mode')
    ->from('2024-01-15')
    ->until('2024-01-16')
    ->activate();
```

Variant distribution:

```php
// Define and assign variants in one step
Toggl::strategy('checkout-flow')
    ->variants([
        'original' => 50,
        'simplified' => 50,
    ])
    ->for($user);

// Multi-variate test
Toggl::strategy('pricing-experiment')
    ->variants([
        'basic' => 25,
        'pro' => 50,
        'enterprise' => 25,
    ])
    ->for($user);
```

**Key Features:**
- `percentage($percent)` - Percentage-based rollout (0-100)
- `from($date)` - Start date for time-based activation
- `until($date)` - End date for time-based activation
- `variants($weights)` - Variant distribution (weights must sum to 100)
- `for($context)` - Apply strategy to context (terminal method)
- `activate()` - Apply global time-based strategy (terminal method)

**Use Cases:**
- Progressive feature rollouts
- Seasonal feature activation
- Time-limited promotions
- A/B testing setup
- Canary deployments

## Pipeline Conductor

Execute multiple feature operations in sequence for a context.

Basic pipeline operations:

```php
use Cline\Toggl\Toggl;

// Chain multiple activations and deactivations
Toggl::pipeline()
    ->activate('premium-dashboard')
    ->activate('advanced-analytics')
    ->deactivate('trial-banner')
    ->for($user);

// Activate multiple features at once
Toggl::pipeline()
    ->activate(['feat-1', 'feat-2', 'feat-3'])
    ->deactivate(['old-feat-1', 'old-feat-2'])
    ->for($user);
```

Pipeline with side effects using tap:

```php
// Execute callbacks between operations
Toggl::pipeline()
    ->activate('premium')
    ->tap(fn($context) => Log::info("Activated premium for {$context->email}"))
    ->activate('analytics')
    ->tap(fn($context) => Cache::forget("user-{$context->id}-features"))
    ->deactivate('trial')
    ->for($user);

// Complex upgrade flow
Toggl::pipeline()
    ->deactivate('free-tier')
    ->tap(fn($user) => event(new UpgradeStarted($user)))
    ->activate(['premium-dashboard', 'priority-support', 'advanced-features'])
    ->tap(fn($user) => event(new UpgradeCompleted($user)))
    ->tap(fn($user) => Mail::to($user)->send(new WelcomeToPremium()))
    ->for($user);
```

**Key Features:**
- `activate($features)` - Add activation operation (single or array)
- `deactivate($features)` - Add deactivation operation (single or array)
- `tap($callback)` - Execute side effect callback receiving context
- `for($context)` - Execute all pipeline operations (terminal method)

**Use Cases:**
- User onboarding flows
- Subscription upgrades/downgrades
- Feature migrations
- Bulk feature management
- Coordinated feature changes with logging

## Cascade Conductor

Activate or deactivate a feature along with all its dependent features.

Cascade activation (primary feature + dependents):

```php
use Cline\Toggl\Toggl;

// Activate premium tier with all dependent features
Toggl::cascade('premium-tier')
    ->activating([
        'premium-dashboard',
        'advanced-analytics',
        'priority-support',
        'api-access',
    ])
    ->for($user);

// Activate enterprise suite with dependencies
Toggl::cascade('enterprise')
    ->activating([
        'sso-authentication',
        'audit-logging',
        'custom-branding',
        'dedicated-support',
        'advanced-security',
    ])
    ->for($organization);
```

Cascade deactivation (dependents first, then primary):

```php
// Downgrade: deactivate premium features then tier
Toggl::cascade('premium-tier')
    ->deactivating([
        'advanced-analytics',
        'priority-support',
        'api-access',
    ])
    ->for($user);

// Remove feature bundle
Toggl::cascade('beta-program')
    ->deactivating([
        'experimental-ui',
        'advanced-features',
        'early-access',
    ])
    ->for($user);
```

**Key Features:**
- `activating($features)` - Activate primary then dependent features
- `deactivating($features)` - Deactivate dependents then primary
- `for($context)` - Execute cascade operation (terminal method)

**Operation Order:**
- Activation: Primary feature first, then dependents
- Deactivation: Dependents first, then primary feature

**Use Cases:**
- Tier-based feature bundles
- Feature package upgrades/downgrades
- Coordinated feature rollouts
- Subscription plan changes
- Access level management

## Variant Conductor

A/B testing and feature variants with weight-based distribution.

```php
use Cline\Toggl\Toggl;

// Get variant for user (weight-based distribution)
$result = Toggl::variant('checkout-flow')->for($user);
$variant = $result->get(); // 'original' or 'simplified'

// Check which variant is assigned
if ($result->is('simplified')) {
    // Show simplified checkout
}

// Get variant with default fallback
$color = Toggl::variant('button-color')->for($user)->getOr('blue');

// Assign specific variant (override distribution)
Toggl::variant('checkout-flow')->use('simplified')->for($user);
```

Multi-variate testing:

```php
// Define variant weights (in FeatureManager configuration)
Toggl::defineVariant('pricing-tier', [
    'basic' => 25,
    'pro' => 50,
    'enterprise' => 25,
]);

// Get assigned variant
$tier = Toggl::variant('pricing-tier')->for($user)->get();

// Gradual rollout example
Toggl::defineVariant('new-ui', [
    'old' => 90,
    'new' => 10,  // 10% get new UI
]);

$uiVersion = Toggl::variant('new-ui')->for($user)->get();
```

Force specific variants for testing:

```php
// Override weight distribution for specific users
Toggl::variant('experiment')->use('variant-b')->for($testUser);

// Verify assignment
expect(Toggl::variant('experiment')->for($testUser)->is('variant-b'))->toBeTrue();

// Internal testing scenario
if ($user->isInternalTester()) {
    Toggl::variant('beta-features')->use('enabled')->for($user);
}
```

**Key Features:**
- `use($variant)` - Assign specific variant (override distribution)
- `for($context)` - Apply variant to context, returns VariantResult (terminal method)
- `get()` - Get assigned variant name (on VariantResult)
- `is($name)` - Check if specific variant is assigned (on VariantResult)
- `getOr($default)` - Get variant or default value (on VariantResult)

**How It Works:**
- Uses CRC32 hashing for consistent assignment
- Same feature+context always gets same variant
- Weights determine distribution percentages
- `use()` overrides weight-based assignment

**Use Cases:**
- A/B testing UI changes
- Gradual feature rollouts
- Multi-variate experiments
- Algorithmic testing
- Price testing
- Internal testing overrides

### Variants (A/B Testing)

Create A/B tests and multivariate experiments with weight-based distribution:

```php
// Define variant with weights (must sum to 100)
Toggl::defineVariant('checkout-flow', [
    'original' => 50,
    'simplified' => 50,
]);

// Get assigned variant (consistent per context)
$variant = Toggl::variant('checkout-flow')->for($user)->get();
// Returns: 'original' or 'simplified'

// Check which variant is assigned
$result = Toggl::variant('checkout-flow')->for($user);
if ($result->is('simplified')) {
    // Show simplified checkout
}

// Get variant or default
$color = Toggl::variant('button-color')->for($user)->getOr('blue');

// Assign specific variant (override distribution)
Toggl::variant('checkout-flow')->use('simplified')->for($user);
$variant = Toggl::variant('checkout-flow')->for($user)->get();
// Always returns: 'simplified'

// Multi-variate test
Toggl::defineVariant('pricing-tier', [
    'basic' => 25,
    'pro' => 50,
    'enterprise' => 25,
]);

$tier = Toggl::variant('pricing-tier')->for($user)->get();

// Gradual rollout (10% get new UI)
Toggl::defineVariant('new-ui', [
    'old' => 90,
    'new' => 10,
]);

// Force variant for testing
Toggl::variant('new-feature')->use('on')->for($testUser);
expect(Toggl::variant('new-feature')->for($testUser)->is('on'))->toBeTrue();
```

**How it works:**
- Variants use CRC32 hashing for consistent assignment
- Same feature+context always gets same variant
- Weights determine distribution percentages
- `use()` overrides weight-based assignment

### Conditional Activation

Activate features only when conditions are met:

```php
// Activate only if condition is true
Toggl::activate('admin-panel')
    ->onlyIf(fn($user) => $user->role === 'admin')
    ->for($user);

// Activate unless condition is true
Toggl::activate('trial-banner')
    ->unless(fn($user) => $user->subscribed)
    ->for($user);

// Chain multiple conditions (AND logic)
Toggl::activate('enterprise-suite')
    ->onlyIf(fn($user) => $user->role === 'admin')
    ->onlyIf(fn($user) => $user->verified)
    ->for($user);

// Mix onlyIf and unless
Toggl::activate('advanced-features')
    ->onlyIf(fn($user) => $user->role === 'admin')
    ->unless(fn($user) => $user->banned)
    ->for($user);

// Works with values too
Toggl::activate('theme')
    ->withValue('dark-pro')
    ->onlyIf(fn($user) => $user->subscription === 'pro')
    ->for($user);

// Subscription-based access
Toggl::activate('premium-features')
    ->onlyIf(fn($user) => in_array($user->subscription, ['pro', 'enterprise']))
    ->for($user);
```

**How it works:**
- Conditions execute in order, short-circuiting on first failure
- `onlyIf()` must evaluate to `true` for activation to proceed
- `unless()` must evaluate to `false` for activation to proceed
- All conditions must pass for feature to activate
- If any condition fails, feature remains inactive

### Everyone Activation/Deactivation

```php
// Activate for all contexts
Toggl::activateForEveryone('new-dashboard');

// Alias: enableGlobally (more concise)
Toggl::enableGlobally('new-dashboard');

// Activate with value for everyone
Toggl::activateForEveryone('api-version', 'v2');
Toggl::enableGlobally('api-version', 'v2'); // Alias

// Deactivate for all contexts
Toggl::deactivateForEveryone('maintenance-mode');

// Alias: disableGlobally
Toggl::disableGlobally('maintenance-mode');

// Works with arrays too
Toggl::activateForEveryone(['feature-1', 'feature-2']);
Toggl::enableGlobally(['feature-1', 'feature-2']); // Alias

Toggl::deactivateForEveryone(['old-feature-1', 'old-feature-2']);
Toggl::disableGlobally(['old-feature-1', 'old-feature-2']); // Alias
```

## Inherit Conductor

Context scope inheritance where child contexts inherit features from parent contexts.

```php
use Cline\Toggl\Toggl;

// User inherits all team features
Toggl::inherit($user)->from($team);

// Organization → Team → User cascade
Toggl::inherit($team)->from($organization);
Toggl::inherit($user)->from($team);
```

**Child Precedence**: Child's own settings always take precedence over inherited features.

```php
// Team has dark theme
Toggl::for($team)->activate('theme', 'dark');

// User has their own theme preference
Toggl::for($user)->activate('theme', 'light');

// Inheritance doesn't override user's preference
Toggl::inherit($user)->from($team);

expect(Toggl::for($user)->value('theme'))->toBe('light'); // User's value preserved
```

**Selective Inheritance** with `only()`:

```php
// Inherit only specific features
Toggl::inherit($user)
    ->only(['advanced-analytics', 'priority-support'])
    ->from($premiumTemplate);

// User gets only specified features from template
expect(Toggl::for($user)->active('advanced-analytics'))->toBeTrue();
expect(Toggl::for($user)->active('priority-support'))->toBeTrue();
```

**Exclude Features** with `except()`:

```php
// Inherit all except admin features
Toggl::inherit($user)
    ->except(['admin-panel', 'user-management'])
    ->from($organization);

// User gets all organization features except excluded ones
expect(Toggl::for($user)->active('premium'))->toBeTrue();
expect(Toggl::for($user)->active('admin-panel'))->toBeFalse();
```

**Key Features:**
- `only($features)` - Inherit only specified features (whitelist)
- `except($features)` - Inherit all except specified features (blacklist)
- `from($parentContext)` - Execute inheritance from parent context (terminal method)
- Child settings always take precedence over parent
- Supports cascading multi-level inheritance

**Use Cases:**
- User inheriting team/organization features
- Template-based feature assignment
- Role-based feature inheritance
- Multi-tenant feature hierarchies
- Subscription tier inheritance
- Department/group feature propagation

## Observe Conductor

Monitor feature changes and execute callbacks when features are activated, deactivated, or changed.

```php
use Cline\Toggl\Toggl;

// Create observer and check for changes
$observer = Toggl::observe('premium')
    ->onActivate(function ($feature, $value) {
        Log::info("Feature {$feature} activated with value {$value}");
    })
    ->for($user);

// Later, check for changes
Toggl::for($user)->activate('premium');
$observer->check(); // Triggers onActivate callback
```

**Callback Types:**

```php
// onChange - fires on any state or value change
$observer = Toggl::observe('theme')
    ->onChange(function ($feature, $oldValue, $newValue, $isActive) {
        Log::info("Theme changed from {$oldValue} to {$newValue}");
    })
    ->for($user);

// onActivate - fires when feature is activated
$observer = Toggl::observe('premium')
    ->onActivate(function ($feature, $value) {
        Mail::send(new PremiumActivatedEmail($user));
    })
    ->for($user);

// onDeactivate - fires when feature is deactivated
$observer = Toggl::observe('premium')
    ->onDeactivate(function ($feature, $oldValue) {
        Log::warning("Premium downgraded, was: {$oldValue}");
    })
    ->for($user);
```

**Callback Precedence:** Specific callbacks (onActivate/onDeactivate) take precedence over the general onChange callback.

**Observer State Tracking:**

```php
$observer = Toggl::observe('premium')->for($user);

// Query current state
if ($observer->isActive()) {
    $currentValue = $observer->value();
}

// Check for changes
$observer->check(); // Only fires callbacks if state changed
$observer->check(); // Won't fire again without new changes
```

**Chaining Multiple Callbacks:**

```php
$observer = Toggl::observe('premium')
    ->onActivate(function () {
        Metrics::increment('premium_activations');
    })
    ->onDeactivate(function () {
        Metrics::increment('premium_cancellations');
    })
    ->for($user);
```

**Key Features:**
- `onChange($callback)` - Fire on any state or value change
- `onActivate($callback)` - Fire when feature activates
- `onDeactivate($callback)` - Fire when feature deactivates
- `for($context)` - Create observer for context (terminal method)
- Observer has `check()` method to detect changes
- Observer has `isActive()` and `value()` to query state
- Callbacks only fire once per change
- Specific callbacks override general onChange

**Use Cases:**
- Logging feature activation/deactivation
- Sending notifications on feature changes
- Tracking subscription upgrades/downgrades
- Triggering side effects when features change
- Monitoring A/B test variant assignments
- Auditing feature usage patterns

## Comparison Conductor

Compare feature states between contexts to identify differences, unique features, and value changes.

```php
use Cline\Toggl\Toggl;

// Compare two contexts directly
$diff = Toggl::compare($user1, $user2)->get();

// Or use deferred comparison
$diff = Toggl::compare($user)->against($team);
```

**Comparison Result Structure:**

```php
[
    'only_context1' => ['feature-a' => true, 'feature-b' => 'value'],
    'only_context2' => ['feature-c' => true],
    'different_values' => [
        'theme' => [
            'context1' => 'dark',
            'context2' => 'light',
        ],
    ],
]
```

**Real-World Examples:**

```php
// Compare user against team baseline
$diff = Toggl::compare($user)->against($team);

if (!empty($diff['only_context1'])) {
    // User has custom features not in team baseline
    Log::info('User customizations:', $diff['only_context1']);
}

if (!empty($diff['different_values'])) {
    // User has overridden team defaults
    foreach ($diff['different_values'] as $feature => $values) {
        Log::info("{$feature}: team={$values['context2']}, user={$values['context1']}");
    }
}

// Compare environments for drift detection
$diff = Toggl::compare($production, $staging)->get();

if (!empty($diff['only_context1']) || !empty($diff['only_context2'])) {
    Alert::send('Environment feature drift detected!');
}

// Track subscription tier differences
$basicFeatures = Toggl::for($basicTemplate)->stored();
$premiumFeatures = Toggl::for($premiumTemplate)->stored();
$diff = Toggl::compare($basicTemplate, $premiumTemplate)->get();

// $diff['only_context2'] shows premium-exclusive features
$premiumExclusive = $diff['only_context2'];

// Feature rollout progress
$target = Toggl::for($targetState)->stored();
$current = Toggl::for($currentState)->stored();
$diff = Toggl::compare($current, $target)->get();

$remaining = $diff['only_context2']; // Features still to be rolled out
$progress = count($current) / (count($current) + count($remaining)) * 100;
```

**Key Features:**
- `compare($context1, $context2)` - Compare two contexts directly
- `compare($context1)->against($context2)` - Deferred comparison
- `get()` - Execute comparison (terminal method)
- Returns differences in three categories
- Only compares active features (filters out false/inactive)
- Useful for auditing, drift detection, and synchronization

**Use Cases:**
- Comparing user settings against team defaults
- Detecting environment configuration drift
- Auditing subscription tier differences
- Tracking feature rollout progress
- Finding customizations and overrides
- Identifying missing or extra features
- Synchronization planning

## Conditional Execution

### When Active

Execute code only when a feature is active:

```php
Toggl::when('new-analytics',
    function () {
        // Feature is active - new analytics
        return Analytics::newVersion()->track();
    },
    function () {
        // Feature is inactive - fallback
        return Analytics::legacy()->track();
    }
);

// Without fallback
Toggl::when('send-welcome-email', function () {
    Mail::to($user)->send(new WelcomeEmail());
});

// Conductor pattern (more chainable)
Toggl::when('premium')
    ->for($user)
    ->then(function () {
        // Feature is active for this user
        return view('dashboard.premium');
    })
    ->otherwise(function () {
        // Feature is inactive for this user
        return view('dashboard.basic');
    });

// Without otherwise clause
Toggl::when('send-notification')
    ->for($user)
    ->then(function () {
        Notification::send($user, new FeatureActivated());
    });

// With BackedEnum
Toggl::when(FeatureFlag::PremiumFeatures)
    ->for($user)
    ->then(fn() => $this->showPremiumUI())
    ->otherwise(fn() => $this->showBasicUI());

// With context
Toggl::for($user)->when('premium-dashboard', function () {
    return view('dashboard.premium');
});
```

### Unless Inactive

Execute code only when a feature is inactive:

```php
Toggl::unless('maintenance-mode',
    function () {
        // Not in maintenance - proceed normally
        return $this->processRequest();
    },
    function () {
        // In maintenance mode - show message
        return response()->view('maintenance', [], 503);
    }
);

// Without active callback
Toggl::unless('beta-ui', function () {
    // Show legacy UI when beta is off
    return view('ui.legacy');
});
```

### Practical Examples

```php
// API versioning
$response = Toggl::when('api-v2',
    fn() => ApiV2::process($request),
    fn() => ApiV1::process($request)
);

// Different payment processors
$result = Toggl::for($team)->when('stripe-payments',
    fn() => Stripe::charge($amount),
    fn() => PayPal::charge($amount)
);

// Feature-specific logging
Toggl::when('detailed-logging', function () {
    Log::debug('User action', [
        'user_id' => $user->id,
        'action' => 'purchase',
        'details' => $details,
    ]);
});
```

## Blade Directives

### @feature Directive

```blade
{{-- Simple check --}}
@feature('new-dashboard')
    <div class="new-dashboard">
        <h1>Welcome to the new dashboard!</h1>
    </div>
@else
    <div class="legacy-dashboard">
        <h1>Dashboard</h1>
    </div>
@endfeature

{{-- Check with specific value --}}
@feature('theme', 'dark')
    <link rel="stylesheet" href="/css/dark-theme.css">
@endfeature

{{-- Contextual check --}}
@feature('premium-badge')
    <span class="badge badge-premium">Premium</span>
@endfeature
```

### Positive Check Directives

#### @hasFeature - Check if a single feature is active

```blade
@hasFeature('premium')
    <span class="badge">Premium Member</span>
@endhasFeature
```

#### @hasAnyFeature - Check if any of the given features are active

```blade
@hasAnyFeature(['beta-ui', 'new-ui', 'experimental-ui'])
    <div class="alert alert-info">
        You're using an experimental UI.
        <a href="/feedback">Share feedback</a>
    </div>
@endhasAnyFeature
```

#### @hasAllFeatures - Check if all of the given features are active

```blade
@hasAllFeatures(['auth', 'payment', 'shipping'])
    <button class="btn-checkout">Complete Purchase</button>
@else
    <div class="alert alert-warning">
        Some features are unavailable. Please try again later.
    </div>
@endhasAllFeatures
```

### Negative Check Directives

#### @missingFeature - Check if a single feature is inactive

```blade
@missingFeature('premium')
    <div class="upgrade-prompt">
        <p>Upgrade to Premium for more features!</p>
        <a href="/upgrade" class="btn">Upgrade Now</a>
    </div>
@endmissingFeature
```

#### @missingAnyFeature - Check if any of the given features are inactive

```blade
@missingAnyFeature(['api-v2', 'webhooks'])
    <div class="alert alert-warning">
        Some advanced features are not yet enabled for your account.
    </div>
@endmissingAnyFeature
```

#### @missingAllFeatures - Check if all of the given features are inactive

```blade
@missingAllFeatures(['premium', 'trial'])
    <div class="free-tier-notice">
        You're on the free tier. Consider upgrading!
    </div>
@endmissingAllFeatures
```

### Unless Variants (Alternative Naming)

For teams who prefer "unless" wording:

```blade
{{-- Same as @missingFeature --}}
@unlessFeature('maintenance-mode')
    <main>Normal content here</main>
@endunlessFeature

{{-- Same as @missingAnyFeature --}}
@unlessAnyFeature(['api-v2', 'webhooks'])
    <div class="legacy-api-notice">Using legacy API</div>
@endunlessAnyFeature

{{-- Same as @missingAllFeatures --}}
@unlessAllFeatures(['premium', 'trial'])
    <div class="free-tier-notice">Free tier user</div>
@endunlessAllFeatures
```

### Directive Reference Table

| Directive | Purpose | Example |
|-----------|---------|---------|
| `@feature` | Single feature active (with optional value) | `@feature('premium')` or `@feature('theme', 'dark')` |
| `@hasFeature` | Single feature active | `@hasFeature('premium')` |
| `@hasAnyFeature` | Any feature active | `@hasAnyFeature(['a', 'b'])` |
| `@hasAllFeatures` | All features active | `@hasAllFeatures(['a', 'b'])` |
| `@missingFeature` | Single feature inactive | `@missingFeature('premium')` |
| `@missingAnyFeature` | Any feature inactive | `@missingAnyFeature(['a', 'b'])` |
| `@missingAllFeatures` | All features inactive | `@missingAllFeatures(['a', 'b'])` |
| `@unlessFeature` | Single feature inactive (alias) | `@unlessFeature('maintenance')` |
| `@unlessAnyFeature` | Any feature inactive (alias) | `@unlessAnyFeature(['a', 'b'])` |
| `@unlessAllFeatures` | All features inactive (alias) | `@unlessAllFeatures(['a', 'b'])` |

### Nested Directives

```blade
@hasFeature('premium-access')
    <div class="premium-section">
        <h2>Premium Features</h2>

        @hasFeature('advanced-analytics')
            <div class="analytics-panel">
                <!-- Advanced analytics -->
            </div>
        @endhasFeature

        @hasFeature('priority-support')
            <div class="support-widget">
                <!-- Priority support widget -->
            </div>
        @endhasFeature
    </div>
@else
    @missingFeature('trial')
        <div class="upgrade-prompt">
            <p>Start your free trial today!</p>
        </div>
    @endmissingFeature
@endhasFeature
```

## Managing Features

### List Defined Features

```php
// Get all defined feature names
$features = Toggl::defined();
// ['new-dashboard', 'beta-api', 'premium-features', ...]
```

### Load Features into Memory

```php
// Pre-load specific features (optimization)
Toggl::load(['feature-1', 'feature-2', 'feature-3']);

// Load all defined features
Toggl::loadAll();

// Load only missing features
Toggl::loadMissing(['feature-1', 'feature-2']);
```

### Forget Feature Values

Remove stored values, reverting to the resolver:

```php
// Forget specific feature
Toggl::forget('beta-access');

// Forget multiple features
Toggl::forget(['feature-a', 'feature-b']);

// Forget for specific context
Toggl::for($user)->forget('custom-setting');
```

### Purge Features

Completely remove features from storage:

```php
// Purge specific feature (all contexts)
Toggl::purge('deprecated-feature');

// Purge multiple features
Toggl::purge(['old-feature-1', 'old-feature-2']);

// Purge all features
Toggl::purge();
```

## Working with Stored Features

When using the database driver, you can inspect stored features:

```php
// Get all stored features
$stored = Toggl::stored();
// [
//     ['name' => 'beta-access', 'context' => 'user-123', 'value' => true],
//     ['name' => 'theme', 'context' => 'user-456', 'value' => 'dark'],
//     ...
// ]

// Get all features (defined + stored)
$all = Toggl::all();
```

## Cache Management

Feature values are cached during the request lifecycle for performance. Manually flush the cache when needed:

```php
// Flush all cached feature values
Toggl::flushCache();

// Useful after bulk operations
Toggl::activateForEveryone('new-feature');
Toggl::flushCache(); // Ensure fresh values
```

The cache is automatically flushed:
- Between requests (Laravel Octane support)
- After queue jobs complete
- When changing drivers

## Real-World Examples

### Feature Toggle Pattern

```php
class DashboardController extends Controller
{
    public function index()
    {
        return Toggl::when('react-dashboard',
            fn() => Inertia::render('Dashboard/New'),
            fn() => view('dashboard.blade')
        );
    }
}
```

### Progressive Enhancement

```blade
<div class="search-container">
    <input type="text" name="q" placeholder="Search...">

    @feature('advanced-search')
        <div class="search-filters">
            <select name="category">...</select>
            <input type="date" name="from">
            <input type="date" name="to">
        </div>
    @endfeature

    <button type="submit">Search</button>
</div>
```

### API Versioning

```php
class ApiController extends Controller
{
    public function process(Request $request)
    {
        $version = Toggl::value('api-version');

        return match($version) {
            'v3' => $this->processV3($request),
            'v2' => $this->processV2($request),
            default => $this->processV1($request),
        };
    }
}
```

### User Preferences

```php
// Store user preference
Toggl::for($user)->activate('email-notifications', [
    'marketing' => true,
    'updates' => true,
    'security' => true,
]);

// Retrieve preference
$notifications = Toggl::for($user)->value('email-notifications');
if ($notifications['marketing']) {
    Mail::to($user)->send(new MarketingEmail());
}
```

### Syncing Features (Replace All)

The sync conductor replaces all existing features/groups for a context (similar to Laravel's relationship sync):

```php
// Replace all features for a user
Toggl::sync($user)->features(['premium', 'analytics', 'reports']);
// User now has ONLY these 3 features, all others are removed

// Remove all features
Toggl::sync($user)->features([]);

// Sync with values
Toggl::sync($user)->withValues([
    'theme' => 'dark',
    'language' => 'es',
    'notifications' => ['email' => true, 'sms' => false],
]);

// Sync group memberships
Toggl::groups()->define('beta', ['feature-1']);
Toggl::groups()->define('premium', ['feature-2']);

Toggl::sync($user)->groups(['premium']);
// User now belongs to ONLY the premium group
```

**When to use sync:**
- User subscription changes (replace tier-specific features)
- Import user settings from external source
- Reset user to default state
- Batch updates that need clean slate

## Next Steps

- **[Strategies](strategies.md)** - Learn about time-based, percentage, and conditional strategies
- **[Time Bombs](time-bombs.md)** - Set expiration dates on features
- **[Feature Groups](feature-groups.md)** - Manage related features together
- **[Variants](variants.md)** - Implement A/B testing
