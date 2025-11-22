# Snapshot Pruning

Toggl creates feature snapshots automatically when features are activated or deactivated, providing point-in-time recovery and audit trails. Over time, these snapshots can accumulate. This guide covers how to configure and automate snapshot pruning.

## Configuration

Configure snapshot functionality and retention in `config/toggl.php`:

```php
'snapshots' => [
    'enabled' => env('TOGGL_SNAPSHOTS_ENABLED', true),
    'driver' => env('TOGGL_SNAPSHOT_DRIVER') ? SnapshotDriver::tryFrom(env('TOGGL_SNAPSHOT_DRIVER')) : null,
    'pruning' => [
        'retention_days' => env('TOGGL_SNAPSHOT_RETENTION_DAYS', 365),
    ],
],
```

- Set `enabled` to `false` to completely disable snapshot functionality
- The default retention period is 365 days. Set to `0` to disable pruning while keeping snapshots enabled

## Manual Pruning

Run the prune command manually:

```bash
# Use configured retention period (default: 365 days)
php artisan toggl:prune-snapshots

# Override retention period to 30 days
php artisan toggl:prune-snapshots --days=30
```

## Scheduled Pruning

Add the command to your scheduler for automatic cleanup. In `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Run daily at 3 AM
    $schedule->command('toggl:prune-snapshots')->dailyAt('03:00');

    // Or run weekly on Sundays
    $schedule->command('toggl:prune-snapshots')->weeklyOn(0, '03:00');
}
```

### Laravel 11+ Scheduler

In `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('toggl:prune-snapshots')->dailyAt('03:00');
```

## Environment-Specific Configuration

Set different retention periods or disable snapshots per environment:

```env
# .env.production - snapshots enabled with 1 year retention
TOGGL_SNAPSHOTS_ENABLED=true
TOGGL_SNAPSHOT_RETENTION_DAYS=365

# .env.staging - snapshots enabled with 30 day retention
TOGGL_SNAPSHOTS_ENABLED=true
TOGGL_SNAPSHOT_RETENTION_DAYS=30

# .env.local - disable snapshots entirely
TOGGL_SNAPSHOTS_ENABLED=false

# Or keep snapshots enabled but disable pruning
TOGGL_SNAPSHOTS_ENABLED=true
TOGGL_SNAPSHOT_RETENTION_DAYS=0
```

## What Gets Deleted

When a snapshot is pruned, the following related data is also deleted:

- **Snapshot entries** - Individual feature states captured in the snapshot
- **Snapshot events** - Audit trail of snapshot operations (create, restore, delete)

This is handled automatically via Eloquent's `deleting` event on the `FeatureSnapshot` model.

## Compliance Considerations

Before configuring pruning, consider:

1. **Regulatory requirements** - Some industries require audit trails for specific periods
2. **Internal policies** - Your organization may have data retention policies
3. **Debugging needs** - Older snapshots can help diagnose production issues

Recommended retention periods by use case:

| Use Case | Retention Period |
|----------|------------------|
| Development | 7-30 days |
| Staging | 30-90 days |
| Production (standard) | 365 days |
| Production (regulated) | As required by compliance |

## Monitoring Pruning

Track pruning operations in your logs:

```php
// In a custom event listener
use Illuminate\Console\Events\CommandFinished;

Event::listen(CommandFinished::class, function ($event) {
    if ($event->command === 'toggl:prune-snapshots') {
        Log::info('Snapshot pruning completed', [
            'exit_code' => $event->exitCode,
        ]);
    }
});
```

## Performance Considerations

The prune command uses chunked queries to handle large numbers of snapshots efficiently:

- Snapshots are processed in batches of 100
- Each snapshot's related entries and events are deleted via Eloquent events
- For very large datasets, consider running during off-peak hours

## See Also

- [Advanced Usage](advanced-usage.md) - Events and middleware
- [Getting Started](getting-started.md) - Initial setup
