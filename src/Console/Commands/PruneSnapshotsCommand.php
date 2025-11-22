<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Console\Commands;

use Cline\Toggl\Facades\Snapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Artisan command to prune old feature snapshots.
 *
 * Deletes snapshots older than the configured retention period to keep
 * the database clean and manage storage efficiently. This command can be
 * scheduled to run automatically via Laravel's task scheduler or executed
 * manually when needed.
 *
 * The retention period is configurable via the toggl.pruning.retention_days
 * configuration value (defaults to 365 days), or can be overridden using
 * the --days option when running the command.
 *
 * ```bash
 * # Use configured retention period
 * php artisan toggl:prune-snapshots
 *
 * # Override with custom retention period
 * php artisan toggl:prune-snapshots --days=90
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PruneSnapshotsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'toggl:prune-snapshots
                            {--days= : Number of days to retain (overrides config)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete feature snapshots older than the retention period';

    /**
     * Execute the console command.
     *
     * Retrieves the retention period from the --days option or configuration,
     * validates it, and delegates to the Snapshot facade to prune old records.
     * Provides user feedback on the number of snapshots deleted.
     *
     * @return int Command exit code (self::SUCCESS)
     */
    public function handle(): int
    {
        // Check if snapshots are enabled
        if (!Config::boolean('toggl.snapshots.enabled', true)) {
            $this->components->warn('Snapshots are disabled.');

            return self::SUCCESS;
        }

        $daysOption = $this->option('days');

        /** @var null|int|numeric-string $daysOption */
        $days = $daysOption !== null
            ? (int) $daysOption
            : Config::integer('toggl.snapshots.pruning.retention_days', 365);

        if ($days <= 0) {
            $this->components->warn('Pruning is disabled (retention_days is 0 or negative).');

            return self::SUCCESS;
        }

        $deleted = Snapshot::prune($days);

        if ($deleted === 0) {
            $this->components->info('No snapshots older than '.$days.' days found.');
        } else {
            $this->components->info('Deleted '.$deleted.' snapshot(s) older than '.$days.' days.');
        }

        return self::SUCCESS;
    }
}
