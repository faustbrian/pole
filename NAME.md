# Package Name Options

## 1. Toggl
**Primary Recommendation**

### Rationale
- Clear tech metaphor: feature flags ARE toggls to your code
- Natural grammar: `Toggl::active('feature')` reads perfectly
- Single syllable, modern, memorable
- Strong action verb: "toggl a change"
- Diversifies Laravel ecosystem beyond nautical themes

### API Examples
```php
use Cline\Toggl\Feature;

Toggl::define('api-v2', fn($user) => $user->isAdmin());
Toggl::active('api-v2');
Toggl::for($user)->variant('checkout-flow');
Toggl::group('beta-features')->activate();
```

### Concerns
- Common word (but available in package namespace)

---

## 2. Beacon
**Strong Alternative**

### Rationale
- Guides behavior and direction
- Strong visual metaphor for feature activation
- Professional, enterprise-friendly tone
- Less common in dev tooling

### API Examples
```php
use Cline\Beacon\Feature;

Beacon::define('premium-tier', fn($user) => $user->isPremium());
Beacon::active('premium-tier');
Beacon::for($user)->variant('pricing-test');
Beacon::group('experimental')->activate();
```

### Concerns
- Two syllables (slightly heavier)
- Beacons guide/attract vs flags that enable/disable
- Less direct connection to conditional logic

---

## 3. Toggle
**Direct/Obvious**

### Rationale
- Immediately understandable
- Common terminology in feature flag space
- Direct action verb
- No learning curve for new developers

### API Examples
```php
use Cline\Toggle\Feature;

Toggle::define('dark-mode', fn($user) => $user->preferences->theme === 'dark');
Toggle::active('dark-mode');
Toggle::for($user)->variant('layout-experiment');
Toggle::group('admin-features')->activate();
```

### Concerns
- Overused in feature flag ecosystem
- Less distinctive/memorable
- Binary connotation (on/off) doesn't capture variants/strategies richness
