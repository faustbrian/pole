# Feature Strategies TODO

## Priority 1: Foundation
- [ ] **"On Unless Off" Strategy** - Default to enabled, only deactivate if explicitly forbidden
  - Add `isForbidden()` / `isExplicitlyDeactivated()` methods
  - Modify driver resolution to return `null` for unknown features (vs `false`)
  - Add conductor methods with inverted logic (default `true` when state unknown)
  - Update middleware to support both activation paradigms

## Priority 2: Advanced Activation Strategies

### Geo-location
- [ ] Region/country-based activation
- [ ] Timezone-based activation
- [ ] IP range filtering

### User Attributes
- [ ] Single attribute matching
- [ ] Multiple attribute conditions (AND/OR)
- [ ] Attribute comparison operators (>, <, >=, <=, !=)

### Ring Deployment
- [ ] Progressive environment rollout
- [ ] Stage ordering with auto-promotion
- [ ] Rollback capability

### Budget/Quota
- [ ] Rate limiting per time window
- [ ] Maximum concurrent usage
- [ ] Total usage quotas with resets

### Kill Switch
- [ ] Emergency deactivation
- [ ] Admin override capability
- [ ] Audit logging for kill switch events

### Dependency-Based
- [ ] `whenAllActive()` - require all features
- [ ] `whenAnyActive()` - require at least one feature
- [ ] `whenNoneActive()` - inverse dependency

### Request-Based
- [ ] HTTP header matching
- [ ] Query parameter detection
- [ ] Cookie-based activation

### Device/Platform
- [ ] Platform detection (iOS, Android, Web)
- [ ] Version comparison (min/max)
- [ ] Device capability checks

### Load-Based
- [ ] CPU threshold monitoring
- [ ] Queue size limits
- [ ] Memory usage awareness
- [ ] Custom metric thresholds

## Priority 3: Infrastructure
- [ ] Strategy composition (combine multiple strategies)
- [ ] Strategy caching for performance
- [ ] Strategy audit logging
- [ ] Strategy testing utilities
- [ ] Migration helpers for existing features
