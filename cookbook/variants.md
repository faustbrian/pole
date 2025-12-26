# Variants

Variants enable A/B testing and multi-variant experiments by returning different values instead of just true/false.

## Defining Variants

### Basic Variant Definition

```php
use Cline\Toggl\Toggl;

Toggl::defineVariant('checkout-flow', [
    'control' => 40,  // 40% of users
    'v1' => 30,       // 30% of users
    'v2' => 30,       // 30% of users
]);

// With BackedEnum
Toggl::defineVariant(FeatureFlag::CheckoutFlow, [
    'control' => 40,
    'v1' => 30,
    'v2' => 30,
]);

// Weights must sum to 100
```

### Retrieving Variants

```php
$variant = Toggl::variant('checkout-flow');
// Returns: 'control', 'v1', or 'v2'

// Contextual to specific user
$variant = Toggl::for($user)->variant('checkout-flow');
// Same user always gets same variant (sticky)

// With BackedEnum
$variant = Toggl::for($user)->variant(FeatureFlag::CheckoutFlow);
```

## How Variants Work

Variants use **consistent hashing (CRC32)** to ensure:
- Same context always gets same variant
- Distribution matches specified weights
- No database lookups required
- Deterministic across requests

```php
// User A always gets 'v1'
Toggl::for($userA)->variant('experiment'); // 'v1'
Toggl::for($userA)->variant('experiment'); // 'v1' (same)

// User B always gets 'control'
Toggl::for($userB)->variant('experiment'); // 'control'
Toggl::for($userB)->variant('experiment'); // 'control' (same)
```

## Use Cases

### A/B Testing

```php
Toggl::defineVariant('pricing-page', [
    'control' => 50,
    'price-first' => 25,
    'features-first' => 25,
]);

$layout = Toggl::for($user)->variant('pricing-page');

return match($layout) {
    'price-first' => view('pricing.price-first'),
    'features-first' => view('pricing.features-first'),
    default => view('pricing.control'),
};
```

### Multi-Variant Experiments

```php
Toggl::defineVariant('button-color', [
    'blue' => 25,
    'green' => 25,
    'red' => 25,
    'orange' => 25,
]);

$buttonColor = Toggl::for($visitor)->variant('button-color');
```

### Gradual Feature Rollout

```php
Toggl::defineVariant('new-editor', [
    'legacy' => 70,   // 70% stay on old
    'new' => 30,      // 30% get new editor
]);

if (Toggl::for($user)->variant('new-editor') === 'new') {
    return $this->newEditor();
}

return $this->legacyEditor();
```

### Algorithm Testing

```php
Toggl::defineVariant('recommendation-algorithm', [
    'collaborative-filtering' => 33,
    'content-based' => 33,
    'hybrid' => 34,
]);

$algorithm = Toggl::for($user)->variant('recommendation-algorithm');

$recommendations = match($algorithm) {
    'collaborative-filtering' => $this->collaborativeFiltering($user),
    'content-based' => $this->contentBased($user),
    'hybrid' => $this->hybrid($user),
};
```

## Checking Variants

### Get Variant Names

```php
$variants = Toggl::variantNames('checkout-flow');
// ['control', 'v1', 'v2']
```

### Get All Variant Configs

```php
$config = Toggl::getVariants('checkout-flow');
// ['control' => 40, 'v1' => 30, 'v2' => 30]
```

### Check if Feature Has Variants

```php
if (Toggl::getVariants('my-feature')) {
    // Feature has variants
    $variant = Toggl::variant('my-feature');
} else {
    // Regular boolean feature
    $active = Toggl::active('my-feature');
}
```

## Updating Variant Weights

```php
// Start with small test
Toggl::defineVariant('new-search', [
    'legacy' => 90,
    'new' => 10,
]);

// Increase after positive results
Toggl::defineVariant('new-search', [
    'legacy' => 50,
    'new' => 50,
]);

// Full rollout
Toggl::defineVariant('new-search', [
    'legacy' => 0,
    'new' => 100,
]);

// Or just switch to boolean
Toggl::define('new-search', true);
```

## Blade Usage

```blade
@php
    $variant = Toggl::for(auth()->user())->variant('landing-page');
@endphp

@if($variant === 'hero-video')
    <x-hero-video />
@elseif($variant === 'hero-carousel')
    <x-hero-carousel />
@else
    <x-hero-static />
@endif
```

## Tracking Variant Performance

```php
$variant = Toggl::for($user)->variant('checkout-flow');

// Track in analytics
Analytics::track('checkout_started', [
    'user_id' => $user->id,
    'variant' => $variant,
]);

// Log conversion
Analytics::track('purchase_completed', [
    'user_id' => $user->id,
    'variant' => $variant,
    'amount' => $order->total,
]);
```

## Combining with Other Features

### Variants + Time Bombs

```php
// Run experiment for 30 days
Toggl::defineVariant('price-test', [
    'control' => 50,
    'higher' => 25,
    'lower' => 25,
])
->expiresAfter(days: 30);

// After expiration, pick winning variant
Toggl::define('price-test', 'lower'); // Winner
```

### Variants + Contexts

```php
// Different experiments per team
$teamVariant = Toggl::for($team)->variant('team-dashboard');

// Different experiments per user
$userVariant = Toggl::for($user)->variant('onboarding-flow');
```

### Variants + Dependencies

```php
Toggl::define('base-feature', true);

// Only run variant test if base feature is active
if (Toggl::active('base-feature')) {
    $variant = Toggl::variant('advanced-test');
}
```

## Best Practices

1. **Keep experiments focused**
   ```php
   // ✅ Good - test one thing
   Toggl::defineVariant('button-text', [
       'buy-now' => 50,
       'purchase' => 50,
   ]);
   
   // ❌ Avoid - too many variables
   Toggl::defineVariant('everything', [
       'variant-a' => 10,
       'variant-b' => 10,
       // ... 8 more variants
   ]);
   ```

2. **Weights must sum to 100**
   ```php
   // ✅ Good
   ['a' => 50, 'b' => 30, 'c' => 20] // = 100
   
   // ❌ Invalid
   ['a' => 50, 'b' => 30, 'c' => 30] // = 110
   ```

3. **Use meaningful variant names**
   ```php
   // ✅ Good
   ['control', 'short-form', 'long-form']
   
   // ❌ Unclear
   ['a', 'b', 'c']
   ```

4. **Track everything**
   ```php
   $variant = Toggl::variant('experiment');
   
   // Log variant assignment
   Log::info('Variant assigned', [
       'feature' => 'experiment',
       'variant' => $variant,
       'user' => $user->id,
   ]);
   ```

5. **Plan your rollout**
   ```php
   // Phase 1: Small test
   Toggl::defineVariant('feature', ['old' => 95, 'new' => 5]);
   
   // Phase 2: Increase if positive
   Toggl::defineVariant('feature', ['old' => 50, 'new' => 50]);
   
   // Phase 3: Full rollout
   Toggl::define('feature', 'new'); // Switch to boolean
   ```

## Testing Variants

```php
test('variant returns consistent results', function () {
    Toggl::defineVariant('test', ['a' => 50, 'b' => 50]);
    
    $user = User::factory()->create();
    
    $variant1 = Toggl::for($user)->variant('test');
    $variant2 = Toggl::for($user)->variant('test');
    
    expect($variant1)->toBe($variant2); // Same user, same variant
    expect($variant1)->toBeIn(['a', 'b']); // Valid variant
});

test('variant distribution is roughly correct', function () {
    Toggl::defineVariant('test', ['a' => 50, 'b' => 50]);
    
    $results = ['a' => 0, 'b' => 0];
    
    for ($i = 0; $i < 1000; $i++) {
        $variant = Toggl::for("user-{$i}")->variant('test');
        $results[$variant]++;
    }
    
    // Should be roughly 50/50 (allowing 10% variance)
    expect($results['a'])->toBeBetween(450, 550);
    expect($results['b'])->toBeBetween(450, 550);
});
```

## Next Steps

- [Advanced Usage](advanced-usage.md) - Events, middleware, and commands
- [Basic Usage](basic-usage.md) - Core operations
- [Strategies](strategies.md) - Different resolution strategies
