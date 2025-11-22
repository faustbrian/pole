<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use Cline\Toggl\Facades\Snapshot;
use Cline\Toggl\FeatureManager;
use Cline\Toggl\PendingContextualFeatureInteraction;
use DateTime;
use Illuminate\Support\Facades\Date;

use function array_key_exists;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;
use function uasort;
use function usort;

/**
 * Conductor for cleaning up stale feature data and historical records.
 *
 * Provides fluent API for implementing data retention policies on snapshots,
 * audit logs, and other historical feature data. Supports time-based retention
 * (removing records older than N days) and count-based retention (keeping only
 * the N most recent items), with configurable cleanup targets and criteria.
 *
 * Essential for managing storage growth in production environments by pruning
 * old debugging data, expired snapshots, and obsolete audit trails while preserving
 * recent history for operational needs and compliance requirements.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class CleanupConductor
{
    /**
     * Create a new cleanup conductor instance.
     *
     * @param FeatureManager $manager       Core feature manager instance providing access to stored
     *                                      feature data for implementing retention policies
     * @param string         $type          Cleanup target category determining which data to prune,
     *                                      either 'snapshots' for historical state captures or 'audit'
     *                                      for historical audit log entries across all features
     * @param null|int       $olderThanDays Time-based retention threshold measured in days, removing
     *                                      items with timestamps exceeding this age while preserving
     *                                      recent data for operational and compliance requirements
     * @param null|int       $keepLatest    Count-based retention limit specifying maximum items to
     *                                      preserve, keeping only the N most recent by timestamp and
     *                                      removing all older entries regardless of absolute age
     */
    public function __construct(
        private FeatureManager $manager,
        private string $type = 'snapshots',
        private ?int $olderThanDays = null,
        private ?int $keepLatest = null,
    ) {}

    /**
     * Configure cleanup to target snapshot data.
     *
     * Directs the cleanup operation to remove old feature state snapshots,
     * preserving recent captures while freeing storage from historical debugging data.
     *
     * @return self New conductor instance configured to clean snapshots
     */
    public function snapshots(): self
    {
        return new self($this->manager, 'snapshots', $this->olderThanDays, $this->keepLatest);
    }

    /**
     * Configure cleanup to target audit history data.
     *
     * Directs the cleanup operation to remove old audit log entries across all
     * features, implementing retention policies on compliance and debugging records.
     *
     * @return self New conductor instance configured to clean audit history
     */
    public function auditHistory(): self
    {
        return new self($this->manager, 'audit', $this->olderThanDays, $this->keepLatest);
    }

    /**
     * Set time-based retention threshold for cleanup.
     *
     * Configures cleanup to remove items with timestamps older than the specified
     * number of days, preserving recent data for operational and compliance needs.
     * Can be combined with keepLatest() for dual retention criteria.
     *
     * @param  int  $days Number of days to retain, removing items older than this threshold
     * @return self New conductor instance with time-based retention configured
     */
    public function olderThan(int $days): self
    {
        return new self($this->manager, $this->type, $days, $this->keepLatest);
    }

    /**
     * Set count-based retention limit for cleanup.
     *
     * Configures cleanup to preserve only the N most recent items based on timestamp
     * sorting, removing all older entries regardless of age. Useful for maintaining
     * fixed-size historical windows. Can be combined with olderThan() for dual criteria.
     *
     * @param  int  $count Maximum number of items to preserve, removing older items
     * @return self New conductor instance with count-based retention configured
     */
    public function keepLatest(int $count): self
    {
        return new self($this->manager, $this->type, $this->olderThanDays, $count);
    }

    /**
     * Execute the cleanup operation on the specified context.
     *
     * Terminal method applying configured retention policies to remove old data from
     * the target context. Returns count of removed items for logging and monitoring.
     * Applies both time-based and count-based criteria when both are configured.
     *
     * ```php
     * // Remove snapshots older than 30 days
     * $removed = Toggl::cleanup()
     *     ->snapshots()
     *     ->olderThan(30)
     *     ->for($user);
     *
     * // Keep only 10 most recent audit entries
     * Toggl::cleanup()
     *     ->auditHistory()
     *     ->keepLatest(10)
     *     ->for($organization);
     * ```
     *
     * @param  mixed $context Target context to clean up historical data from
     * @return int   Number of items successfully removed during cleanup
     */
    public function for(mixed $context): int
    {
        /** @var PendingContextualFeatureInteraction $contextdDriver */
        $contextdDriver = $this->manager->for($context);

        return match ($this->type) {
            'snapshots' => $this->cleanupSnapshots($context),
            'audit' => $this->cleanupAuditHistory($contextdDriver),
            default => 0,
        };
    }

    /**
     * Get the configured cleanup target type.
     *
     * @return string Cleanup type, either 'snapshots' or 'audit'
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Get the configured time-based retention threshold.
     *
     * @return null|int Number of days to retain, or null if not configured
     */
    public function olderThanDays(): ?int
    {
        return $this->olderThanDays;
    }

    /**
     * Get the configured count-based retention limit.
     *
     * @return null|int Maximum number of items to preserve, or null if not configured
     */
    public function keepLatestCount(): ?int
    {
        return $this->keepLatest;
    }

    /**
     * Prune expired snapshot records from context storage.
     *
     * Retrieves all snapshots for the context, applies configured retention criteria
     * (time-based and count-based), and removes expired records from storage. Sorting
     * by timestamp ensures keepLatest correctly identifies the N most recent items.
     * Returns removal count for operational monitoring and cleanup reporting.
     *
     * @param  mixed $context Context entity to clean snapshot history from
     * @return int   Count of snapshot records successfully deleted
     */
    private function cleanupSnapshots(mixed $context): int
    {
        $snapshots = Snapshot::list($context);

        if ($snapshots === []) {
            return 0;
        }

        /** @var array<string, array<string, mixed>> $snapshotsById */
        $snapshotsById = [];

        foreach ($snapshots as $snapshot) {
            /** @var string $snapshotId */
            $snapshotId = $snapshot['id'];
            $snapshotsById[$snapshotId] = $snapshot;
        }

        $toRemove = $this->filterItemsToRemove($snapshotsById);
        $removedCount = count($toRemove);

        if ($removedCount === 0) {
            return 0;
        }

        foreach ($toRemove as $id) {
            Snapshot::delete($id, $context);
        }

        return $removedCount;
    }

    /**
     * Prune expired audit entries across all features in context.
     *
     * Scans all stored features to identify audit histories (those prefixed with __audit__),
     * applies configured retention criteria to each history array, and either updates the
     * trimmed history or removes the audit feature entirely when all entries are expired.
     * Aggregates removal counts across all features for comprehensive cleanup metrics.
     *
     * @param  PendingContextualFeatureInteraction $contextdDriver Context-bound driver providing access to feature storage
     * @return int                                 Total audit entries deleted across all feature audit histories
     */
    private function cleanupAuditHistory(PendingContextualFeatureInteraction $contextdDriver): int
    {
        /** @var array<string, mixed> $allFeatures */
        $allFeatures = $contextdDriver->stored();
        $totalRemoved = 0;

        foreach ($allFeatures as $feature => $value) {
            if (!str_starts_with($feature, '__audit__')) {
                continue;
            }

            $history = $value;

            if (!is_array($history)) {
                continue;
            }

            if ($history === []) {
                continue;
            }

            /** @var array<int|string, array<string, mixed>> $history */
            $toKeep = $this->filterItemsToKeep($history);
            $removedCount = count($history) - count($toKeep);

            if ($removedCount > 0) {
                if ($toKeep === []) {
                    $contextdDriver->deactivate($feature);
                } else {
                    $contextdDriver->activate($feature, $toKeep);
                }

                $totalRemoved += $removedCount;
            }
        }

        return $totalRemoved;
    }

    /**
     * Identify items exceeding retention thresholds for deletion.
     *
     * Evaluates each item against configured retention criteria, marking for removal
     * those exceeding count limits (beyond keepLatest position) or time limits (older
     * than olderThanDays threshold). Sorts by timestamp with newest first to ensure
     * keepLatest correctly identifies the most recent N items to preserve.
     *
     * @param  array<string, array<string, mixed>> $items Keyed collection of items with timestamp metadata
     * @return array<int, string>                  IDs of items marked for deletion
     */
    private function filterItemsToRemove(array $items): array
    {
        $toRemove = [];

        // Sort by timestamp (newest first)
        uasort($items, fn (array $a, array $b): int => ($b['timestamp'] ?? '') <=> ($a['timestamp'] ?? ''));

        $index = 0;

        foreach ($items as $id => $item) {
            $shouldKeep = true;

            // Remove if beyond keepLatest count
            if ($this->keepLatest !== null && $index >= $this->keepLatest) {
                $shouldKeep = false;
            }

            // Remove items older than N days
            if ($this->olderThanDays !== null && array_key_exists('timestamp', $item)) {
                $timestamp = $item['timestamp'];

                if (is_string($timestamp)) {
                    $itemDate = new DateTime($timestamp);
                    $cutoffDate = Date::now();
                    $cutoffDate->modify(sprintf('-%d days', $this->olderThanDays));

                    if ($itemDate < $cutoffDate) {
                        $shouldKeep = false;
                    }
                }
            }

            if (!$shouldKeep) {
                $toRemove[] = $id;
            }

            ++$index;
        }

        return $toRemove;
    }

    /**
     * Identify items within retention thresholds for preservation.
     *
     * Inverse operation to filterItemsToRemove, evaluating items against retention
     * criteria to identify those meeting preservation requirements. Re-indexes to
     * sequential keys for consistent iteration, sorts by timestamp (newest first),
     * and returns items within both count-based and time-based retention limits.
     *
     * @param  array<int|string, array<string, mixed>> $items Indexed collection of items with timestamp metadata
     * @return array<int, array<string, mixed>>        Items qualified for retention
     */
    private function filterItemsToKeep(array $items): array
    {
        // Re-index to ensure sequential keys
        $items = array_values($items);

        // Sort by timestamp (newest first)
        usort($items, fn (array $a, array $b): int => ($b['timestamp'] ?? '') <=> ($a['timestamp'] ?? ''));

        $toKeep = [];

        foreach ($items as $index => $item) {
            $shouldKeep = true;

            // Keep only latest N items
            if ($this->keepLatest !== null && $index >= $this->keepLatest) {
                $shouldKeep = false;
            }

            // Keep only items newer than N days
            if ($this->olderThanDays !== null && array_key_exists('timestamp', $item)) {
                $timestamp = $item['timestamp'];

                if (is_string($timestamp)) {
                    $itemDate = new DateTime($timestamp);
                    $cutoffDate = Date::now();
                    $cutoffDate->modify(sprintf('-%d days', $this->olderThanDays));

                    if ($itemDate < $cutoffDate) {
                        $shouldKeep = false;
                    }
                }
            }

            if ($shouldKeep) {
                $toKeep[] = $item;
            }
        }

        return $toKeep;
    }
}
