# Scoped Features

Activate features at organizational levels (company, division, org, team) that automatically apply to all matching contexts without duplicating database records.

## Setup

### 1. Implement TogglContextable Interface

```php
use Cline\Toggl\Contracts\TogglContextable;
use Cline\Toggl\Concerns\HasTogglContext;
use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Support\FeatureScope;

class User extends Model implements TogglContextable
{
    use HasTogglContext;

    public function toTogglContext(): TogglContext
    {
        return new TogglContext(
            id: $this->getKey(),
            type: static::class,
            scope: new FeatureScope(
                kind: 'user',
                scopes: [
                    'company_id' => $this->company_id,
                    'division_id' => $this->division_id,
                    'org_id' => $this->org_id,
                    'team_id' => $this->team_id,
                    'user_id' => $this->id,
                ],
            ),
        );
    }
}
```

### 2. Add Scope Columns to Your Model

```php
// Migration
$table->integer('company_id')->nullable();
$table->integer('division_id')->nullable();
$table->integer('org_id')->nullable();
$table->integer('team_id')->nullable();
```

---

## Basic Usage

### Enable Scope Resolution with withScopes()

```php
$user = User::find(1);

// Check feature with scope resolution enabled
Toggl::for($user)->withScopes()->active('premium-dashboard');
```

### Activate for All Users in an Organization

Use the fluent `withScopes()` method on conductors:

```php
// Activate at org level (applies to all users in org 2)
Toggl::activate('premium-dashboard')
    ->withScopes([
        'company_id' => 3,
        'org_id' => 2,
        'user_id' => null,  // Wildcard: any user
    ])
    ->for($user);

// All users in org 2 now have access
$user = User::find(1); // company_id=3, org_id=2
Toggl::for($user)->withScopes()->active('premium-dashboard'); // true
```

### Wildcard Matching (null Values)

```php
// Activate for all users in ANY team within org 2
Toggl::activate('shared-analytics')
    ->withScopes([
        'company_id' => 3,
        'org_id' => 2,
        'team_id' => null,  // Matches any team
        'user_id' => null,
    ])
    ->for($user);

// Both users have access despite different teams
$userTeamA = User::find(1); // team_id=10
$userTeamB = User::find(2); // team_id=20
Toggl::for($userTeamA)->withScopes()->active('shared-analytics'); // true
Toggl::for($userTeamB)->withScopes()->active('shared-analytics'); // true
```

---

## Features with Values

### Set Configuration at Organizational Level

```php
Toggl::activate('theme')
    ->withValue('corporate-blue')
    ->withScopes([
        'company_id' => 5,
        'user_id' => null,
    ])
    ->for($user);

// Users inherit the company theme
Toggl::for($user)->withScopes()->value('theme'); // 'corporate-blue'
```

---

## Precedence: Exact Context Wins

User-specific activations override scoped ones:

```php
// Activate at org level
Toggl::activate('theme')
    ->withValue('org-theme')
    ->withScopes([
        'org_id' => 2,
        'user_id' => null,
    ])
    ->for($user);

// Override for specific user
Toggl::for($user)->activate('theme', 'user-theme');

// User's specific value takes precedence
Toggl::for($user)->withScopes()->value('theme'); // 'user-theme'
```

---

## Explicit Scope Scope

You can provide an explicit scope instead of extracting from context:

```php
$user = User::find(1);

// Use explicit scope instead of extracting from context
Toggl::for($user)->withScopes([
    'company_id' => 3,
    'org_id' => 5,
], 'user')->active('premium-dashboard');
```

---

## Deactivation

```php
Toggl::deactivate('test-feature')
    ->withScopes([
        'company_id' => 3,
        'user_id' => null,
    ])
    ->for($user);
```

---

## Real-World Scenarios

### SaaS Multi-Tenant Scope

```php
// Premium feature for entire organization
Toggl::activate('advanced-reporting')
    ->withScopes([
        'company_id' => 10,
        'division_id' => 20,
        'org_id' => 30,
        'user_id' => null,
    ])
    ->for($user);

// Feature enabled for entire division (multiple orgs)
Toggl::activate('division-wide-feature')
    ->withScopes([
        'division_id' => 1,
        'org_id' => null,  // Any org in division
        'user_id' => null,
    ])
    ->for($user);
```

### Feature Rollout by Tier

```php
// Enable premium for specific organizations
Toggl::activate('premium-analytics')
    ->withScopes([
        'company_id' => 5,
        'org_id' => 100,
        'user_id' => null,
    ])
    ->for($user);

// Standard orgs don't have it
$premiumUser = User::where('org_id', 100)->first();
$standardUser = User::where('org_id', 200)->first();

Toggl::for($premiumUser)->withScopes()->active('premium-analytics');  // true
Toggl::for($standardUser)->withScopes()->active('premium-analytics'); // false
```

---

## Key Points

| Feature | Description |
|---------|-------------|
| **No Duplication** | One database record activates feature for all matching contexts |
| **Wildcards** | `null` values match any value at that scope level |
| **Exact Wins** | User-specific activations override scoped ones |
| **Kind Matching** | The `kind` parameter must match between activation and context |
| **Explicit withScopes()** | Must call `withScopes()` to enable scoped resolution |
