# Route Middleware

Toggl provides middleware to protect routes based on feature activation status. Use these to ensure certain features are active (or inactive) before allowing access to routes.

## Available Middleware

### EnsureFeaturesAreActive

Aborts with 400 if any required features are inactive.

```php
use Cline\Cline\Toggl\Http\Middleware\EnsureFeaturesAreActive;

// Using static constructor
Route::get('/dashboard', DashboardController::class)
    ->middleware(EnsureFeaturesAreActive::using('new-dashboard'));

// Multiple features (all must be active)
Route::get('/analytics', AnalyticsController::class)
    ->middleware(EnsureFeaturesAreActive::using('analytics', 'reporting'));

// Middleware alias (register in bootstrap/app.php or Kernel)
Route::get('/dashboard', DashboardController::class)
    ->middleware('feature:new-dashboard,analytics');
```

### EnsureFeaturesAreInactive

Aborts with 400 if any specified features are active. Useful for legacy routes that should only be accessible when new features are disabled.

```php
use Cline\Cline\Toggl\Http\Middleware\EnsureFeaturesAreInactive;

// Legacy endpoint only available when new dashboard is off
Route::get('/old-dashboard', LegacyDashboardController::class)
    ->middleware(EnsureFeaturesAreInactive::using('new-dashboard'));

// Multiple features (all must be inactive)
Route::get('/legacy-api', LegacyApiController::class)
    ->middleware(EnsureFeaturesAreInactive::using('api-v2', 'api-v3'));
```

## Registering Middleware Aliases

In `bootstrap/app.php` (Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'feature' => \Cline\Toggl\Http\Middleware\EnsureFeaturesAreActive::class,
        'feature.inactive' => \Cline\Toggl\Http\Middleware\EnsureFeaturesAreInactive::class,
    ]);
})
```

Or in `app/Http/Kernel.php` (Laravel 10):

```php
protected $middlewareAliases = [
    // ...
    'feature' => \Cline\Toggl\Http\Middleware\EnsureFeaturesAreActive::class,
    'feature.inactive' => \Cline\Toggl\Http\Middleware\EnsureFeaturesAreInactive::class,
];
```

## Custom Response Handling

By default, middleware aborts with a 400 status. Customize this behavior:

### For Active Checks

```php
use Cline\Cline\Toggl\Http\Middleware\EnsureFeaturesAreActive;

// In a service provider boot method
EnsureFeaturesAreActive::whenInactive(function ($request, $features) {
    // Redirect to upgrade page
    return redirect('/upgrade')->with('required_features', $features);
});

// Or return a custom response
EnsureFeaturesAreActive::whenInactive(function ($request, $features) {
    return response()->json([
        'error' => 'Feature not available',
        'required_features' => $features,
    ], 403);
});

// Reset to default behavior
EnsureFeaturesAreActive::whenInactive(null);
```

### For Inactive Checks

```php
use Cline\Cline\Toggl\Http\Middleware\EnsureFeaturesAreInactive;

// Redirect when trying to access legacy route with new features enabled
EnsureFeaturesAreInactive::whenActive(function ($request, $features) {
    return redirect('/dashboard')->with('message', 'You have been upgraded!');
});

// Reset to default behavior
EnsureFeaturesAreInactive::whenActive(null);
```

## Route Group Examples

### Feature-Gated Section

```php
// All routes require premium feature
Route::middleware(EnsureFeaturesAreActive::using('premium'))->group(function () {
    Route::get('/premium/dashboard', PremiumDashboardController::class);
    Route::get('/premium/analytics', PremiumAnalyticsController::class);
    Route::get('/premium/reports', PremiumReportsController::class);
});
```

### Beta Features

```php
// Beta routes only accessible when beta is enabled
Route::prefix('beta')
    ->middleware(EnsureFeaturesAreActive::using('beta-program'))
    ->group(function () {
        Route::get('/new-editor', BetaEditorController::class);
        Route::get('/ai-assistant', BetaAiController::class);
    });
```

### Legacy Routes During Migration

```php
// Old routes only available when new features are off
Route::middleware(EnsureFeaturesAreInactive::using('checkout-v2'))->group(function () {
    Route::get('/checkout', LegacyCheckoutController::class);
    Route::post('/checkout/process', LegacyCheckoutProcessController::class);
});

// New routes require new features
Route::middleware(EnsureFeaturesAreActive::using('checkout-v2'))->group(function () {
    Route::get('/checkout', NewCheckoutController::class);
    Route::post('/checkout/process', NewCheckoutProcessController::class);
});
```

### API Versioning

```php
// API v1 - legacy (only when v2 is not enabled)
Route::prefix('api/v1')
    ->middleware(EnsureFeaturesAreInactive::using('api-v2'))
    ->group(function () {
        Route::apiResource('users', Api\V1\UserController::class);
    });

// API v2 - new version
Route::prefix('api/v2')
    ->middleware(EnsureFeaturesAreActive::using('api-v2'))
    ->group(function () {
        Route::apiResource('users', Api\V2\UserController::class);
    });
```

## Debug Mode

In debug mode (`APP_DEBUG=true`), error messages include the feature names:

```
Required features [analytics, reporting] are not active.
Features [legacy-api] must be inactive.
```

In production, generic error messages are shown for security.

## Testing Routes with Middleware

```php
use Cline\Toggl\Toggl;
use App\Models\User;

test('premium route requires premium feature', function () {
    // Arrange
    $user = User::factory()->create();
    Toggl::for($user)->deactivate('premium');

    // Act & Assert
    $this->actingAs($user)
        ->get('/premium/dashboard')
        ->assertStatus(400);
});

test('premium route accessible with feature active', function () {
    // Arrange
    $user = User::factory()->create();
    Toggl::for($user)->activate('premium');

    // Act & Assert
    $this->actingAs($user)
        ->get('/premium/dashboard')
        ->assertOk();
});

test('legacy route inaccessible when new feature is active', function () {
    // Arrange
    $user = User::factory()->create();
    Toggl::for($user)->activate('new-dashboard');

    // Act & Assert
    $this->actingAs($user)
        ->get('/old-dashboard')
        ->assertStatus(400);
});

test('legacy route accessible when new feature is inactive', function () {
    // Arrange
    $user = User::factory()->create();
    Toggl::for($user)->deactivate('new-dashboard');

    // Act & Assert
    $this->actingAs($user)
        ->get('/old-dashboard')
        ->assertOk();
});

test('different users can have different feature states', function () {
    // Arrange
    $premiumUser = User::factory()->create();
    $freeUser = User::factory()->create();

    Toggl::for($premiumUser)->activate('premium');
    Toggl::for($freeUser)->deactivate('premium');

    // Act & Assert
    $this->actingAs($premiumUser)
        ->get('/premium/dashboard')
        ->assertOk();

    $this->actingAs($freeUser)
        ->get('/premium/dashboard')
        ->assertStatus(400);
});
```

## Guest Context

For unauthenticated requests, the middleware uses a guest context (`TogglContext::simple('guest', 'guest')`). You can activate features globally for guests:

```php
// Activate globally for everyone including guests
Toggl::activateForEveryone('public-feature');

// Or define with a default value
Toggl::define('public-feature', true);
```
