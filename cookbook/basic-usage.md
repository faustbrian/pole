# Basic Usage

This guide covers all the core operations you'll use daily with Pole feature flags.

## Defining Features

### Simple Boolean Features

The simplest way to define a feature is with a boolean value:

```php
use Cline\Pole\Feature;

// Always active
Feature::define('maintenance-mode', true);

// Always inactive
Feature::define('upcoming-feature', false);
```

### Closure-Based Features

For dynamic evaluation, use a closure that receives the current scope:

```php
// User-based feature
Feature::define('premium-dashboard', function ($user) {
    return $user?->subscription?->isPremium() ?? false;
});

// Environment-based feature
Feature::define('debug-mode', function () {
    return app()->environment('local');
});

// Complex logic
Feature::define('advanced-search', function ($user) {
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
Feature::define('api-version', 'v2');

// Numeric value
Feature::define('rate-limit', 1000);

// Array value
Feature::define('ui-config', [
    'theme' => 'dark',
    'sidebar' => 'collapsed',
    'layout' => 'grid',
]);
```

## Checking Features

### Active/Inactive Checks

```php
// Check if active
if (Feature::active('new-dashboard')) {
    // Feature is enabled
}

// Check if inactive
if (Feature::inactive('beta-feature')) {
    // Feature is disabled
}
```

### Scoped Checks

Check features for specific users, teams, or any scope:

```php
// For specific user
$user = User::find(123);
if (Feature::for($user)->active('premium-features')) {
    // User has premium features
}

// For team
if (Feature::for($team)->active('team-analytics')) {
    // Team has analytics
}

// For string scope
if (Feature::for('admin')->active('debug-panel')) {
    // Admin debug panel
}
```

### Multiple Feature Checks

```php
// All features must be active
if (Feature::allAreActive(['auth', 'api', 'dashboard'])) {
    // All three features are enabled
}

// At least one feature must be active
if (Feature::someAreActive(['beta-ui', 'new-ui', 'experimental-ui'])) {
    // At least one UI variation is enabled
}

// All features must be inactive
if (Feature::allAreInactive(['maintenance', 'outage'])) {
    // System is operational
}

// At least one feature is inactive
if (Feature::someAreInactive(['feature-a', 'feature-b'])) {
    // Not all features are enabled
}
```

## Retrieving Values

### Single Value

```php
// Get feature value (returns mixed)
$apiVersion = Feature::value('api-version'); // 'v2'
$rateLimit = Feature::value('rate-limit');   // 1000
$config = Feature::value('ui-config');       // ['theme' => 'dark', ...]

// With scope
$userTheme = Feature::for($user)->value('theme-preference');
```

### Multiple Values

```php
// Get multiple values at once
$values = Feature::values(['api-version', 'rate-limit', 'ui-config']);
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
if (Feature::value('api-version') === 'v2') {
    // Use v2 endpoints
}
```

## Activating and Deactivating Features

### Global Activation/Deactivation

```php
// Activate (sets to true)
Feature::activate('new-feature');

// Activate with custom value
Feature::activate('api-version', 'v3');

// Deactivate (sets to false)
Feature::deactivate('old-feature');

// Activate multiple features
Feature::activate(['feature-a', 'feature-b', 'feature-c']);

// Deactivate multiple features
Feature::deactivate(['old-ui', 'deprecated-api']);
```

### Scoped Activation/Deactivation

```php
// Activate for specific user
Feature::for($user)->activate('beta-access');

// Activate with value
Feature::for($user)->activate('theme', 'dark');

// Deactivate for specific user
Feature::for($user)->deactivate('beta-access');

// Activate for team
Feature::for($team)->activate('team-dashboard');
```

### Everyone Activation/Deactivation

```php
// Activate for all scopes
Feature::activateForEveryone('new-dashboard');

// Activate with value for everyone
Feature::activateForEveryone('api-version', 'v2');

// Deactivate for all scopes
Feature::deactivateForEveryone('maintenance-mode');

// Works with arrays too
Feature::activateForEveryone(['feature-1', 'feature-2']);
Feature::deactivateForEveryone(['old-feature-1', 'old-feature-2']);
```

## Conditional Execution

### When Active

Execute code only when a feature is active:

```php
Feature::when('new-analytics',
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
Feature::when('send-welcome-email', function () {
    Mail::to($user)->send(new WelcomeEmail());
});

// With scope
Feature::for($user)->when('premium-dashboard', function () {
    return view('dashboard.premium');
});
```

### Unless Inactive

Execute code only when a feature is inactive:

```php
Feature::unless('maintenance-mode',
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
Feature::unless('beta-ui', function () {
    // Show legacy UI when beta is off
    return view('ui.legacy');
});
```

### Practical Examples

```php
// API versioning
$response = Feature::when('api-v2',
    fn() => ApiV2::process($request),
    fn() => ApiV1::process($request)
);

// Different payment processors
$result = Feature::for($team)->when('stripe-payments',
    fn() => Stripe::charge($amount),
    fn() => PayPal::charge($amount)
);

// Feature-specific logging
Feature::when('detailed-logging', function () {
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

{{-- Scoped check --}}
@feature('premium-badge')
    <span class="badge badge-premium">Premium</span>
@endfeature
```

### @featureany Directive

Show content if ANY of the features are active:

```blade
@featureany(['beta-ui', 'new-ui', 'experimental-ui'])
    <div class="alert alert-info">
        You're using an experimental UI.
        <a href="/feedback">Share feedback</a>
    </div>
@endfeatureany
```

### @featureall Directive

Show content only if ALL features are active:

```blade
@featureall(['auth', 'payment', 'shipping'])
    <button class="btn-checkout">Complete Purchase</button>
@else
    <div class="alert alert-warning">
        Some features are unavailable. Please try again later.
    </div>
@endfeatureall
```

### Nested Directives

```blade
@feature('premium-access')
    <div class="premium-section">
        <h2>Premium Features</h2>

        @feature('advanced-analytics')
            <div class="analytics-panel">
                <!-- Advanced analytics -->
            </div>
        @endfeature

        @feature('priority-support')
            <div class="support-widget">
                <!-- Priority support widget -->
            </div>
        @endfeature
    </div>
@endfeature
```

## Managing Features

### List Defined Features

```php
// Get all defined feature names
$features = Feature::defined();
// ['new-dashboard', 'beta-api', 'premium-features', ...]
```

### Load Features into Memory

```php
// Pre-load specific features (optimization)
Feature::load(['feature-1', 'feature-2', 'feature-3']);

// Load all defined features
Feature::loadAll();

// Load only missing features
Feature::loadMissing(['feature-1', 'feature-2']);
```

### Forget Feature Values

Remove stored values, reverting to the resolver:

```php
// Forget specific feature
Feature::forget('beta-access');

// Forget multiple features
Feature::forget(['feature-a', 'feature-b']);

// Forget for specific scope
Feature::for($user)->forget('custom-setting');
```

### Purge Features

Completely remove features from storage:

```php
// Purge specific feature (all scopes)
Feature::purge('deprecated-feature');

// Purge multiple features
Feature::purge(['old-feature-1', 'old-feature-2']);

// Purge all features
Feature::purge();
```

## Working with Stored Features

When using the database driver, you can inspect stored features:

```php
// Get all stored features
$stored = Feature::stored();
// [
//     ['name' => 'beta-access', 'scope' => 'user-123', 'value' => true],
//     ['name' => 'theme', 'scope' => 'user-456', 'value' => 'dark'],
//     ...
// ]

// Get all features (defined + stored)
$all = Feature::all();
```

## Cache Management

Feature values are cached during the request lifecycle for performance. Manually flush the cache when needed:

```php
// Flush all cached feature values
Feature::flushCache();

// Useful after bulk operations
Feature::activateForEveryone('new-feature');
Feature::flushCache(); // Ensure fresh values
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
        return Feature::when('react-dashboard',
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
        $version = Feature::value('api-version');

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
Feature::for($user)->activate('email-notifications', [
    'marketing' => true,
    'updates' => true,
    'security' => true,
]);

// Retrieve preference
$notifications = Feature::for($user)->value('email-notifications');
if ($notifications['marketing']) {
    Mail::to($user)->send(new MarketingEmail());
}
```

## Next Steps

- **[Strategies](strategies.md)** - Learn about time-based, percentage, and conditional strategies
- **[Time Bombs](time-bombs.md)** - Set expiration dates on features
- **[Feature Groups](feature-groups.md)** - Manage related features together
- **[Variants](variants.md)** - Implement A/B testing
