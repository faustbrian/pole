# Conductor-Style API Improvements

This document outlines potential conductor-style API enhancements for Toggl, inspired by Warden's fluent conductor pattern. These improvements would provide alternative, more natural ways to interact with feature flags while maintaining backward compatibility.

## Implementation Progress

### Phase 1: Core Conductors (Warden Parity)
- [x] #1: Reverse-Flow (`activate()->for($user)`) - Implementation, Tests, Cookbook ✅
- [x] #2: Group-First (`activateGroup()->for($user)`) - Implementation, Tests, Cookbook ✅
- [x] #10: Query/Check (`when()->for()->then()`) - Implementation, Tests, Cookbook ✅
- [x] #21: Sync (`sync($user)->features([])`) - Implementation, Tests, Cookbook ✅

### Phase 2: Value & Context (High Impact)
- [x] #3: Value (`activate()->withValue()->for()`) - Implementation, Tests, Cookbook ✅
- [x] #9: Context Grouping (`within($team)->activate()`) - Implementation, Tests, Cookbook ✅
- [x] #17: Bulk Value (`bulk([...])->for()`) - Implementation ✅, Tests ✅, Cookbook
- [x] #22: Tap (`activate()->tap()->for()`) - Implementation ✅, Tests ✅, Cookbook

### Phase 3: Advanced Write Operations
- [x] #4: Variant (`variant()->use()->for()`) - Implementation, Tests, Cookbook ✅
- [x] #16: Conditional (`activate()->onlyIf()->for()`) - Implementation ✅, Tests ✅, Cookbook
- [x] #6: Batch (`batch()->activate()->for()`) - Implementation ✅, Tests ✅, Cookbook
- [x] #11: Permission-Style (`allow($user)->to('feature')`) - Implementation ✅, Tests ✅, Cookbook

### Phase 4: Configuration & Definition
- [x] #7: Strategy (`strategy()->percentage()->activate()`) - Implementation, Tests, Cookbook ✅
- [x] #8: Dependency (`require()->before()->for()`) - Implementation, Tests, Cookbook ✅
- [x] #26: Cascade (`activate()->cascade()->for()`) - Implementation, Tests, Cookbook ✅
- [x] #12: Fluent Definition (`define()->resolver()->register()`) - Implementation ✅, Tests ✅, Cookbook
- [x] #25: Metadata (`activate()->withMeta()->for()`) - Implementation ✅, Tests ✅, Cookbook

### Phase 5: Developer Experience
- [x] #14: Testing (`fake()->enable()->for()`) - Implementation ✅, Tests ✅, Cookbook
- [x] #18: Copy/Clone (`from($user)->copyTo($another)`) - Implementation ✅, Tests ✅, Cookbook
- [x] #23: Pipeline (`pipeline()->activate()->execute()`) - Implementation, Tests, Cookbook ✅
- [x] #24: Transaction (`transaction(fn() => ...)`) - Implementation ✅, Tests ✅, Cookbook

### Phase 6: Enterprise Features
- [x] #13: Migration/Rollout (`rollout()->toPercent()->over()`) - Implementation, Tests, Cookbook ✅
- [x] #28: Inherit (`for($user)->inherit($team)`) - Implementation, Tests, Cookbook ✅
- [x] #19: Snapshot/Restore (`snapshot()` / `restore()`) - Implementation ✅, Tests ✅, Cookbook
- [x] #5: Time-Based (`activate()->from()->until()->for()`) - Implementation, Tests, Cookbook ✅

### Phase 7: Monitoring & Analytics
- [x] #15: Observe/Watch (`watch()->for()->onChange()`) - Implementation, Tests, Cookbook ✅
- [x] #27: Audit (`activate()->audit()->for()`) - Implementation ✅, Tests ✅, Cookbook
- [x] #20: Comparison (`compare($u1, $u2)`) - Implementation, Tests, Cookbook ✅

---

## Quick Reference

| #   | Conductor              | Priority    | Pattern                                | Category      |
| --- | ---------------------- | ----------- | -------------------------------------- | ------------- |
| 1   | Reverse-Flow           | ⭐⭐⭐ HIGHEST | `activate()->for($user)`               | Write         |
| 2   | Group-First            | ⭐⭐⭐ HIGH    | `activateGroup()->for($user)`          | Write         |
| 3   | Value                  | ⭐⭐ MEDIUM   | `activate()->withValue()->for()`       | Write         |
| 4   | Variant                | ⭐⭐ MEDIUM   | `variant()->use()->for()`              | Advanced      |
| 5   | Time-Based             | ⭐⭐ MEDIUM   | `activate()->from()->until()->for()`   | Advanced      |
| 6   | Batch Activation       | ⭐⭐ MEDIUM   | `batch()->activate()->for()`           | Write         |
| 7   | Strategy               | ⭐⭐ MEDIUM   | `strategy()->percentage()->activate()` | Config        |
| 8   | Dependency             | ⭐⭐ MEDIUM   | `require()->before()->for()`           | Config        |
| 9   | Context Grouping       | ⭐⭐⭐ HIGH    | `within($team)->activate()`            | Grouping      |
| 10  | Query/Check            | ⭐⭐⭐ HIGH    | `when()->for()->then()`                | Read          |
| 11  | Permission-Style       | ⭐⭐ MEDIUM   | `allow($user)->to('feature')`          | Write         |
| 12  | Fluent Definition      | ⭐⭐ MEDIUM   | `define()->resolver()->register()`     | Config        |
| 13  | Migration/Rollout      | ⭐ LOW       | `rollout()->toPercent()->over()`       | Enterprise    |
| 14  | Testing                | ⭐⭐⭐ HIGH    | `fake()->enable()->for()`              | Testing       |
| 15  | Observe/Watch          | ⭐⭐ MEDIUM   | `watch()->for()->onChange()`           | Monitoring    |
| 16  | Conditional Activation | ⭐⭐ MEDIUM   | `activate()->onlyIf()->for()`          | Write         |
| 17  | Bulk Value             | ⭐⭐⭐ HIGH    | `bulk([...])->for()`                   | Write         |
| 18  | Copy/Clone             | ⭐⭐ MEDIUM   | `from($user)->copyTo($another)`        | Grouping      |
| 19  | Snapshot/Restore       | ⭐ LOW       | `snapshot()` / `restore()`             | Advanced      |
| 20  | Comparison             | ⭐ LOW       | `compare($u1, $u2)`                    | Read          |
| 21  | Sync                   | ⭐⭐⭐ HIGH    | `sync($user)->features([])`            | Write         |
| 22  | Tap                    | ⭐⭐⭐ HIGH    | `activate()->tap()->for()`             | Orchestration |
| 23  | Pipeline               | ⭐⭐ MEDIUM   | `pipeline()->activate()->execute()`    | Orchestration |
| 24  | Transaction            | ⭐⭐ MEDIUM   | `transaction(fn() => ...)`             | Orchestration |
| 25  | Metadata               | ⭐⭐ MEDIUM   | `activate()->withMeta()->for()`        | Config        |
| 26  | Cascade                | ⭐⭐ MEDIUM   | `activate()->cascade()->for()`         | Advanced      |
| 27  | Audit                  | ⭐ LOW       | `activate()->audit()->for()`           | Monitoring    |
| 28  | Inherit                | ⭐⭐ MEDIUM   | `for($user)->inherit($team)`           | Grouping      |
| 29  | (REMOVED)              |             | Merged into #15 (Observe)              |               |
| 30  | (REMOVED)              |             | Merged into #5 (Time-Based)            |               |

---

## Detailed Conductor Patterns

## **1. Reverse-Flow Conductors** (Feature-first, then context)

Similar to Warden's `assign($roles)->to($user)` pattern, but using `for()` since features are enabled FOR users, not assigned TO them:

```php
// Current
Feature::for($user)->activate('premium');

// New conductor style (feature-first)
Feature::activate('premium')->for($user);
Feature::activate('premium')->for([$user1, $user2, $user3]);

Feature::deactivate('beta')->for($user);

// With values
Feature::activate('theme')->withValue('dark')->for($user);

// Bulk operations
Feature::activate(['feat-1', 'feat-2', 'feat-3'])->for($user);
```

## **2. Group-First Conductor** (for bulk group operations)

```php
// Current
Feature::for($user)->activateGroup('premium');

// New conductor style
Feature::activateGroup('premium')->for($user);
Feature::activateGroup('premium')->for([$user1, $user2]);

Feature::deactivateGroup('beta')->for($user);
```

## **3. Conditional Conductors** (more fluent when/unless)

```php
// Current
Feature::for($user)->when('premium', fn() => ...);

// New conductor style (reads very naturally)
Feature::when('premium')->for($user)->then(fn() => ...)->otherwise(fn() => ...);
```

## **4. Variant Conductor**

```php
// Current
$variant = Feature::for($user)->variant('ab-test');

// New conductor style
$variant = Feature::variant('ab-test')->for($user)->get();

// Or assign specific variant
Feature::variant('ab-test')->use('variant-b')->for($user);
```

## **5. Time-Based Conductor** (temporal modifiers)

```php
// Current
Feature::define('holiday-sale')->expiresAt('2025-12-26');

// New conductor style - temporal modifiers before terminal for()
// Duration window
Feature::activate('holiday-sale')
    ->from('2025-12-20')
    ->until('2025-12-26')
    ->for($user);

// Single start time (no expiry = permanent)
Feature::activate('beta')
    ->from('2025-12-20')
    ->for($user);

// Expires at specific time (starts now)
Feature::activate('limited-offer')
    ->until('2025-12-26')
    ->for($user);

// Recurring time windows (from Schedule conductor #28)
Feature::activate('weekend-mode')
    ->between('saturday', 'sunday')
    ->for($user);

Feature::activate('business-hours')
    ->between('09:00', '17:00')
    ->for($user);
```

## **Implementation Priority Ranking**

**High Value:**
1. ✅ **Feature-first activation** (`activate()->for($user)`) - mirrors Warden perfectly
2. ✅ **Group-first activation** (`activateGroup()->for($user)`) - consistent with #1

**Medium Value:**
3. **Value conductor** (`activate('theme')->withValue('dark')->for($user)`) - cleaner than current
4. **Variant conductor** (`variant('test')->use('b')->for($user)`) - better than current

**Lower Priority:**
5. Conditional conductors - current API is already good
6. Time-based conductor - nice-to-have

---

## Additional Conductor Patterns to Consider

### **6. Batch Activation Conductor**

```php
// Cartesian product: all features × all contexts (9 operations, auto-executes)
Feature::batch()
    ->activate(['premium-1', 'premium-2', 'premium-3'])
    ->for([$user1, $user2, $user3]); // for() is terminal, auto-executes

// Mixed operations in one batch (auto-executes when last for() called)
Feature::batch()
    ->activate(['premium-1', 'premium-2'])->for([$user1, $user2]) // 4 ops
    ->activate('beta')->for($adminUser);                           // 1 op, executes
```

### **7. Strategy Conductor**

```php
// Current
Feature::define('rollout')->strategy(new PercentageStrategy(25));

// New conductor style
Feature::strategy('rollout')
    ->percentage(25)
    ->for(auth()->user());

Feature::strategy('scheduled-feature')
    ->from('2025-01-01')
    ->until('2025-12-31')
    ->activate();

Feature::strategy('ab-test')
    ->variants(['control' => 50, 'variant-a' => 30, 'variant-b' => 20])
    ->for($user);
```

### **8. Dependency Conductor**

```php
// Current
Feature::define('advanced-analytics')->requires('basic-analytics');

// New conductor style
Feature::require('basic-analytics')
    ->before('advanced-analytics')
    ->for($user);

// Or chain dependencies
Feature::activate('premium-suite')
    ->requires(['auth', 'payment', 'subscription'])
    ->for($user);
```

### **9. Context Grouping Conductor**

```php
// Set context for multiple operations
Feature::within($team)
    ->activate('team-dashboard')
    ->activate('team-analytics')
    ->activate('team-reporting');

// Or with callback
Feature::within($team, function($features) {
    $features->activate('team-dashboard');
    $features->activate('team-analytics');
    $features->deactivate('legacy-ui');
});
```

### **10. Query/Check Conductor**

```php
// Current
if (Feature::for($user)->active('premium')) { ... }

// New conductor style (more chainable)
// NOTE: Revisit boolean-returning methods - when/then/otherwise may be sufficient

// Boolean checks - discarded but kept for consideration
Feature::when('premium')
    ->for($user)
    ->isActive(); // reads backwards: "when premium for user is active"

Feature::when(['auth', 'api', 'dashboard'])
    ->for($user)
    ->allActive(); // reads backwards

Feature::when(['feat-1', 'feat-2', 'feat-3'])
    ->for($user)
    ->anyActive(); // reads backwards

// Boolean checks (proposal - alternative naming)
Feature::when('premium')
    ->for($user)
    ->isTrue(); // or ->passes()? ->holds()?

Feature::when(['auth', 'api', 'dashboard'])
    ->for($user)
    ->allTrue(); // or ->allPass()?

Feature::when(['feat-1', 'feat-2', 'feat-3'])
    ->for($user)
    ->anyTrue(); // or ->anyPass()?

// With callbacks (preferred - consistent with BDD when/then/otherwise)
Feature::when('premium')
    ->for($user)
    ->then(fn() => ...)
    ->otherwise(fn() => ...);
```

### **11. Permission-Style Conductor** (Warden-inspired)

```php
// Warden style: Warden::allow($user)->to('edit', $post)
// Toggl equivalent:
Feature::allow($user)->to('premium-dashboard');
Feature::deny($user)->to('beta-features');

Feature::allow($user)->toGroup('premium');
Feature::deny($user)->toGroup('experimental');

// Bulk operations
Feature::allow([$user1, $user2])->to(['feat-1', 'feat-2']);
```

### **12. Fluent Definition Conductor**

```php
// Current
Feature::define('new-ui', fn($user) => $user->isAdmin());

// New conductor style
Feature::define('new-ui')
    ->resolver(fn($user) => $user->isAdmin())
    ->expiresAt('2025-12-31')
    ->dependsOn(['auth', 'session'])
    ->register();

// Or with inline activation
Feature::define('dark-mode')
    ->defaultValue('auto')
    ->for($user)
    ->withValue('enabled');
```

### **13. Migration/Rollout Conductor**

```php
// Gradual rollout pattern
Feature::rollout('new-checkout')
    ->toPercent(10) // 10% of users
    ->over(7) // over 7 days
    ->start();

// Next day increases to ~11.4%, day 7 = 100%

// Or manual incremental rollout
Feature::rollout('api-v3')
    ->first(fn() => User::where('beta_tester', true)->get())
    ->then(fn() => User::where('subscription', 'premium')->get())
    ->finally(fn() => User::all())
    ->execute();
```

### **14. Testing Conductor**

```php
// In tests
Feature::fake()
    ->enable('premium')
    ->disable('beta')
    ->activateGroup('testing')
    ->for($testUser);

// Or scenario-based
Feature::scenario('premium-user')
    ->enable(['premium-dashboard', 'priority-support', 'advanced-analytics'])
    ->disable(['trial-banner', 'upgrade-prompt'])
    ->apply();
```

### **15. Observe/Watch Conductor** (Real-time monitoring)

```php
// Watch for feature changes (merged Event/Callback conductor)
Feature::watch('premium-dashboard')
    ->for($user)
    ->onChange(fn($newValue, $oldValue) =>
        Log::info("Feature changed from {$oldValue} to {$newValue}")
    );

// Lifecycle hooks
Feature::watch('premium')
    ->for($user)
    ->onActivation(fn($user) => Log::info("Activated for {$user->email}"))
    ->onDeactivation(fn($user) => Log::info("Deactivated for {$user->email}"))
    ->onChange(fn($new, $old) => Cache::flush());

// Watch multiple features
Feature::watch(['feat-1', 'feat-2', 'feat-3'])
    ->for($user)
    ->onAnyChange(fn($changes) => Cache::flush());

// Contextual watching
Feature::for($user)
    ->watch('theme')
    ->onChange(fn($theme) => Session::put('theme', $theme));
```

### **16. Conditional Activation Conductor** (guards)

```php
// Activate only if conditions met (renamed from 'when' to avoid conflict with BDD pattern)
Feature::activate('premium-features')
    ->onlyIf(fn($user) => $user->subscription->active)
    ->for($user);

// Or with unless
Feature::activate('trial-banner')
    ->unless(fn($user) => $user->subscription->active)
    ->for($user);

// Chain multiple conditions
Feature::activate('enterprise-suite')
    ->onlyIf(fn($user) => $user->role === 'admin')
    ->onlyIf(fn($user) => $user->company->employees > 100)
    ->for($user);
```

### **17. Bulk Value Conductor**

```php
// Set multiple features with different values at once (array-based for consistency)
Feature::bulk([
    'theme' => 'dark',
    'language' => 'es',
    'timezone' => 'UTC',
    'notifications' => ['email' => true, 'sms' => false],
])->for($user);

// Different from batch() which does Cartesian products
// Bulk = multiple feature/value pairs → single context
// Batch = features × contexts (Cartesian product)
```

### **18. Copy/Clone Conductor**

```php
// Copy all features from one context to another
Feature::from($adminUser)
    ->copyTo($newUser);

// Or selective copy
Feature::from($adminUser)
    ->only(['premium-features', 'advanced-access'])
    ->copyTo($newUser);

// Or exclude certain features
Feature::from($adminUser)
    ->except(['debug-mode', 'testing-tools'])
    ->copyTo($newUser);
```

### **19. Snapshot/Restore Conductor**

```php
// Take snapshot of current feature state
$snapshot = Feature::for($user)->snapshot();

// Later, restore
Feature::for($user)->restore($snapshot);

// Or named snapshots
Feature::for($user)
    ->snapshot('before-experiment');

// Run experiment...

Feature::for($user)
    ->restore('before-experiment');
```

### **20. Comparison Conductor**

```php
// Compare feature states between contexts
$diff = Feature::compare($user1, $user2);
// Returns: ['only_user1' => [...], 'only_user2' => [...], 'different_values' => [...]]

// Or compare against a baseline
$diff = Feature::compare($user)
    ->against(Feature::defaults());

// Or compare groups
$diff = Feature::compareGroups('premium', 'basic');
```

### **21. Sync Conductor** (Warden-inspired)

```php
// Current - no built-in pattern for syncing
Feature::for($user)->activate(['feat-1', 'feat-2', 'feat-3']);
// (doesn't remove previously activated features)

// New conductor style - sync replaces all features
Feature::sync($user)
    ->features(['premium-dashboard', 'advanced-analytics', 'priority-support']);
// User now has ONLY these 3 features, all others are removed

// Or sync groups
Feature::sync($user)
    ->groups(['premium', 'beta-tester']);
// User now belongs to ONLY these groups

// Or sync with values (using 'with' prefix for consistency)
Feature::sync($user)
    ->withValues([
        'theme' => 'dark',
        'language' => 'es',
        'notifications' => ['email' => true],
    ]);

// Detach pattern (remove all)
Feature::sync($user)->features([]); // Removes all features
Feature::sync($user)->groups([]);   // Removes all group memberships
```

### **22. Tap Conductor** (Chainable side effects)

```php
// Execute side effects without breaking the chain
Feature::activate('premium')
    ->tap(fn() => Log::info('Activating premium'))
    ->tap(fn() => Cache::forget('user-features'))
    ->for($user)
    ->tap(fn() => event(new PremiumActivated($user)));

// Or with the conductor itself
Feature::activate('premium')
    ->tap(fn($conductor) => Log::info("Activating: {$conductor->feature()}"))
    ->for($user);
```

### **23. Pipeline Conductor** (Sequential operations)

```php
// Execute a series of feature operations
Feature::pipeline()
    ->activate('feature-1')->for($user)
    ->activate('feature-2')->for($user)
    ->deactivate('old-feature')->for($user)
    ->activateGroup('premium')->for($user)
    ->execute();

// Or with conditional steps
Feature::pipeline()
    ->when($user->isPremium(), fn($pipe) =>
        $pipe->activateGroup('premium')->for($user)
    )
    ->when($user->isBetaTester(), fn($pipe) =>
        $pipe->activateGroup('beta')->for($user)
    )
    ->execute();
```

### **24. Transaction Conductor** (All-or-nothing)

```php
// Atomic feature operations - all succeed or all rollback
Feature::transaction(function($features) use ($user) {
    $features->activate('premium-suite')->for($user);
    $features->activate('advanced-analytics')->for($user);
    $features->deactivate('trial-mode')->for($user);

    // If any operation fails, all rollback
    if (!$user->canUpgrade()) {
        throw new UpgradeException();
    }
});

// Or explicit commits
Feature::beginTransaction()
    ->activate('premium')->for($user)
    ->activate('analytics')->for($user)
    ->commit();
// If commit() not called, changes rollback
```

### **25. Metadata Conductor** (Attach context)

```php
// Add metadata to feature activation
Feature::activate('premium')
    ->withMeta([
        'activated_by' => auth()->user()->id,
        'reason' => 'subscription_upgrade',
        'plan' => 'professional',
        'activated_at' => now(),
    ])
    ->for($user);

// Retrieve metadata later
$meta = Feature::for($user)->meta('premium');
// ['activated_by' => 123, 'reason' => 'subscription_upgrade', ...]

// Or query by metadata
$users = Feature::query()
    ->whereFeature('premium')
    ->whereMeta('reason', 'subscription_upgrade')
    ->get();
```

### **26. Cascade Conductor** (Hierarchical activation)

```php
// Activate feature and all dependencies
Feature::activate('advanced-suite')
    ->cascade() // Also activates required features
    ->for($user);

// Or explicit cascade control
Feature::activate('premium-analytics')
    ->cascadeDown() // Activate this and all child features
    ->for($user);

Feature::deactivate('basic-plan')
    ->cascadeUp() // Deactivate this and all parent features
    ->for($user);
```

### **27. Audit Conductor** (Track changes)

```php
// Enable auditing for feature changes
Feature::activate('premium')
    ->audit()
    ->for($user);

// Later, retrieve audit trail
$history = Feature::for($user)
    ->audit('premium')
    ->get();
// [
//   ['action' => 'activated', 'user_id' => 1, 'at' => '2025-01-01 10:00:00'],
//   ['action' => 'deactivated', 'user_id' => 1, 'at' => '2025-01-15 14:30:00'],
//   ['action' => 'activated', 'user_id' => 1, 'at' => '2025-01-20 09:00:00'],
// ]

// Or audit all changes for a user
$fullHistory = Feature::for($user)
    ->auditAll()
    ->since('2025-01-01')
    ->get();
```

### **28. Inherit Conductor** (Context scope)

```php
// Inherit features from parent context
Feature::for($user)
    ->inherit($team) // User inherits team features
    ->activate('custom-branding');

// Multi-level inheritance
Feature::for($user)
    ->inherit($team)
    ->inherit($organization)
    ->inherit($parentOrganization)
    ->activate('feature');

// Or explicit inheritance chain
Feature::inheritanceChain()
    ->from($parentOrganization)
    ->through($organization)
    ->through($team)
    ->for($user)
    ->activate('enterprise-features');
```

---

## Pattern Categories

### **Read Operations** (Query/Check)
- #10: Query/Check Conductor
- #15: Observe/Watch Conductor (merged Event/Callback)
- #20: Comparison Conductor
- #27: Audit Conductor

### **Write Operations** (Activation/Deactivation)
- #1: Reverse-Flow Conductors (HIGH PRIORITY)
- #2: Group-First Conductor (HIGH PRIORITY)
- #3: Value Conductor
- #6: Batch Activation Conductor
- #11: Permission-Style Conductor
- #16: Conditional Activation Conductor
- #17: Bulk Value Conductor
- #21: Sync Conductor (HIGH PRIORITY - Warden-inspired)

### **Configuration/Definition**
- #7: Strategy Conductor
- #8: Dependency Conductor
- #12: Fluent Definition Conductor
- #25: Metadata Conductor

### **Scoping/Context**
- #9: Context Grouping Conductor
- #18: Copy/Clone Conductor
- #28: Inherit Conductor

### **Advanced Patterns**
- #4: Variant Conductor
- #5: Time-Based Conductor (merged Schedule)
- #13: Migration/Rollout Conductor
- #19: Snapshot/Restore Conductor
- #26: Cascade Conductor

### **Orchestration**
- #22: Tap Conductor
- #23: Pipeline Conductor
- #24: Transaction Conductor

### **Testing**
- #14: Testing Conductor

---

## Recommended Implementation Order

### Phase 1: Core Conductors (Essential - Warden Parity)
1. **Reverse-Flow Conductors** (#1) - `activate()->for($user)` - HIGHEST PRIORITY
2. **Group-First Conductor** (#2) - `activateGroup()->for($user)`
3. **Sync Conductor** (#21) - `sync($user)->features([])` - Direct Warden pattern
4. **Query/Check Conductor** (#10) - `check()->for($user)->isActive()`

### Phase 2: Value & Context (High Impact) ✅ COMPLETE
- [x] #3: Value (`activate()->withValue()->for()`) - Implementation, Tests, Cookbook ✅
- [x] #9: Context Grouping (`within($team)->activate()`) - Implementation, Tests, Cookbook ✅
- [x] #17: Bulk Value (`bulk([...])->for()`) - Implementation, Tests, Cookbook ✅
- [x] #22: Tap (`activate()->tap()->for()`) - Implementation, Tests, Cookbook ✅

### Phase 3: Advanced Write Operations
9. **Variant Conductor** (#4)
10. **Conditional Activation Conductor** (#16)
11. **Batch Activation Conductor** (#6)
12. **Permission-Style Conductor** (#11)

### Phase 4: Configuration & Definition
13. **Strategy Conductor** (#7)
14. **Dependency Conductor** (#8)
15. **Cascade Conductor** (#27)
16. **Fluent Definition Conductor** (#12)
17. **Metadata Conductor** (#26)

### Phase 5: Developer Experience
18. **Testing Conductor** (#14)
19. **Copy/Clone Conductor** (#18)
20. **Pipeline Conductor** (#23)
21. **Transaction Conductor** (#24)

### Phase 6: Enterprise Features
22. **Migration/Rollout Conductor** (#13)
23. **Inherit Conductor** (#28)
24. **Snapshot/Restore Conductor** (#19)

### Phase 7: Monitoring & Analytics
25. **Observe/Watch Conductor** (#15) - merged Event/Callback
26. **Audit Conductor** (#27)
27. **Comparison Conductor** (#20)

---

## Design Principles

All conductors follow these core principles:

### 1. **Immutability**
Each method returns a new conductor instance for safe chaining.

### 2. **Terminal Methods**
- **Contextual operations** (affecting specific context) → `->for($scope)` (auto-executes)
- **Global operations** (no context) → `->execute()` or `->apply()`
- **Queries/reads** → return values directly

### 3. **Context Direction**
- **Primary pattern** (90% of cases): Feature-first → `Feature::activate('premium')->for($user)`
- **Exceptions** (documented with rationale):
  - `within()`: Multiple operations on same context
  - `sync()`: Warden-inspired replacement pattern
  - `inherit()`: Context scope relationships

### 4. **Temporal Vocabulary**
- `from()` / `until()`: Duration windows
- `from()` alone: Starts at time, no expiry (permanent)
- `until()` alone: Starts now, expires at time
- `between()`: Recurring time ranges (daily/weekly)

### 5. **Callback Patterns**
- **BDD conditionals**: `when/then/otherwise` (READ operations)
- **Guard modifiers**: `onlyIf/unless` (WRITE conditions)
- **Lifecycle hooks**: `onActivation/onDeactivation/onChange` (observers)

### 6. **Value Setting Verbs**
- `withValue()`: Single feature value
- `bulk([...])`: Multiple feature/value pairs
- `withMeta()`: Metadata attachments
- `withValues()`: Sync operations (replacement)

### 7. **Pattern Clarity**
- **Batch**: Cartesian product (features × contexts)
- **Bulk**: Multiple feature/value pairs → single context
- **Pipeline**: Sequential operations with conditionals + explicit `execute()`

### 8. **Backward Compatibility**
All existing APIs continue to work. Conductors provide alternative, more fluent syntax.

### 9. **Type Safety**
Full support for BackedEnum and proper type hints throughout.

### 10. **Natural Language**
Reading the code should sound like natural language. Avoid awkward phrasing.
