# Global Context Management

## Overview

Toggl supports global context management similar to the Warden package, allowing you to set an additional context layer for feature evaluation. This is particularly useful for multi-tenancy scenarios where features should behave differently based on the current organizational context (team, account, workspace, etc.).

## Global Context vs Entity Context

Understanding the difference between global context and entity context is crucial:

- **Entity Context**: The entity being checked (e.g., a specific user, model instance)
- **Global Context**: The global contextual environment (e.g., which team, account, or workspace is active)

## Basic Usage

### Setting Global Context

```php
use Cline\Toggl\Toggl;

// Set context to a team ID
Toggl::context()->to('team-123');

// Set context to an organization
Toggl::context()->to($organization);

// Clear context
Toggl::context()->clear();
```

### Checking Current Context

```php
// Check if context is set
if (Toggl::context()->hasContext()) {
    $context = Toggl::context()->current();
}
```

### Using Context in Feature Resolvers

Feature resolvers receive both the entity context and global context parameters:

```php
Toggl::define('premium-api', function ($entityContext, $globalContext = null) {
    // $entityContext = the user being checked
    // $globalContext = the current team/organization

    return $entityContext->team_id === $globalContext;
});

// Set team context
Toggl::context()->to(5);

// Check if user can access premium API in their team context
if (Toggl::for($user)->active('premium-api')) {
    // User can access premium API within team 5
}
```

## Multi-Tenancy Scenarios

### Team-Based Features

```php
// Define a feature that's only active for users within a specific team
Toggl::define('advanced-analytics', function ($user, $globalContext = null) {
    // Feature is active if user belongs to the current team context
    // and the team has the premium plan
    return $user->team_id === $globalContext
        && Team::find($globalContext)?->hasPremiumPlan();
});

// In your middleware or controller
Toggl::context()->to($currentTeam->id);

// Check feature for user
if (Toggl::active('advanced-analytics')) {
    // Show advanced analytics
}
```

### Account-Based Features

```php
Toggl::define('white-label', function ($user, $globalContext = null) {
    if ($globalContext === null) {
        return false; // Not active without account context
    }

    return Account::find($globalContext)?->hasWhiteLabel() ?? false;
});

// Set account context
Toggl::context()->to($request->account()->id);

// Feature will be evaluated within account context
if (Toggl::active('white-label')) {
    // Apply white-label branding
}
```

### Workspace-Based Features

```php
Toggl::define('collaboration-tools', function ($user, $globalContext = null) {
    // User must be in a workspace and workspace must have collaboration enabled
    return $globalContext !== null
        && $user->workspaces->contains($globalContext)
        && Workspace::find($globalContext)?->hasCollaboration();
});

// Set workspace context in middleware
public function handle($request, Closure $next)
{
    if ($workspace = $request->route('workspace')) {
        Toggl::context()->to($workspace);
    }

    return $next($request);
}
```

## Context with Different Entity Types

### Object Entities with Properties

```php
// Use objects for entities with multiple properties
$user = (object) ['id' => 1, 'team_id' => 5];

Toggl::define('team-feature', function ($entityContext, $globalContext = null) {
    // $entityContext is an object with team information
    return isset($entityContext->team_id) && $entityContext->team_id === $globalContext;
});

Toggl::context()->to(5);

$isActive = Toggl::for($user)->active('team-feature'); // true
```

**Note**: Associative arrays are treated as multiple entities by the `for()` method, not as a single entity with properties. Use objects or implement `TogglContextable` for complex entity types.

### Model Entities

```php
Toggl::define('org-admin', function ($user, $globalContext = null) {
    if ($globalContext === null) {
        return false;
    }

    return $user->isAdminOf($globalContext);
});

// Using Eloquent models
Toggl::context()->to($organization);

if (Toggl::for($user)->active('org-admin')) {
    // User is admin in this organization context
}
```

### Context as Objects

```php
// You can use objects as context to pass structured data
$teamContext = (object) ['id' => 99, 'tier' => 'enterprise'];

Toggl::define('enterprise-features', function ($user, $context = null) {
    return is_object($globalContext)
        && isset($globalContext->tier)
        && $globalContext->tier === 'enterprise';
});

Toggl::context()->to($teamContext);

if (Toggl::active('enterprise-features')) {
    // Show enterprise features
}
```

## Cache Behavior

Context changes automatically flush the feature cache to ensure fresh evaluation:

```php
Toggl::context()->to('team-123');
$result1 = Toggl::active('premium-api'); // Evaluated fresh

Toggl::context()->to('team-456');
// Cache is automatically flushed when context changes
$result2 = Toggl::active('premium-api'); // Re-evaluated with new context

Toggl::context()->clear();
// Cache is flushed when context is cleared
$result3 = Toggl::active('premium-api'); // Evaluated without context
```

## Integration with Strategies

Context works seamlessly with all built-in strategies:

### Conditional Strategy with Context

```php
use Cline\Toggl\Strategies\ConditionalStrategy;

Toggl::define('team-export', new ConditionalStrategy(
    fn ($user, $team = null) => $team !== null && $user->team_id === $globalContext,
    true,  // Value when condition is true
    false  // Value when condition is false
));
```

### Custom Strategies with Context

```php
use Cline\Toggl\Contracts\Strategy;

class TeamBasedStrategy implements Strategy
{
    public function __construct(
        private int $requiredTeamTier,
    ) {}

    public function resolve(mixed $entityContext, mixed $globalContext = null): mixed
    {
        if ($globalContext === null) {
            return false;
        }

        $team = Team::find($globalContext);

        return $team && $team->tier >= $this->requiredTeamTier;
    }
}

Toggl::define('advanced-features', new TeamBasedStrategy(requiredTeamTier: 2));
```

## Real-World Example: SaaS Application

```php
// Middleware to set account context
class SetAccountContext
{
    public function handle($request, Closure $next)
    {
        if ($account = $request->user()?->currentAccount()) {
            Toggl::context()->to($account->id);
        }

        return $next($request);
    }
}

// Feature definitions
Toggl::define('api-access', function ($user, $globalContext = null) {
    if ($globalContext === null) {
        return false;
    }

    $account = Account::find($globalContext);

    return $account && $account->plan->hasApiAccess();
});

Toggl::define('team-collaboration', function ($user, $globalContext = null) {
    if ($globalContext === null) {
        return false;
    }

    $account = Account::find($globalContext);

    return $account
        && $account->plan->hasCollaboration()
        && $user->isTeamMember($account);
});

// In your controller
public function index()
{
    // Context is already set by middleware

    if (Toggl::active('api-access')) {
        // Show API documentation link
    }

    if (Toggl::active('team-collaboration')) {
        // Show team features
    }
}
```

## Testing with Context

```php
use Cline\Toggl\Toggl;

test('users can access team features within their team context', function () {
    $user = User::factory()->create(['team_id' => 5]);

    Toggl::define('team-dashboard', fn ($u, $globalContext = null) =>
        $u->team_id === $globalContext
    );

    // Set team context
    Toggl::context()->to(5);

    expect(Toggl::for($user)->active('team-dashboard'))->toBeTrue();

    // Change context to different team
    Toggl::context()->to(10);

    expect(Toggl::for($user)->active('team-dashboard'))->toBeFalse();

    // Clear context
    Toggl::context()->clear();

    expect(Toggl::for($user)->active('team-dashboard'))->toBeFalse();
});
```

## Best Practices

1. **Always set context in middleware** for consistent context across requests
2. **Check for null context** in your resolvers if the feature requires context
3. **Use model IDs for context** rather than full models for better serialization
4. **Clear context** in tests to avoid test pollution
5. **Document context requirements** in your feature definitions

## Migration from Direct Entity Checking

If you're currently checking features like this:

```php
// Before: mixing entity and global context
Toggl::for(['user' => $user, 'team' => $team])->active('feature');
```

Migrate to context-based approach:

```php
// After: separate entity and global context
Toggl::context()->to($team->id);
Toggl::for($user)->active('feature');
```

This provides better separation of concerns and more consistent behavior across your application.
