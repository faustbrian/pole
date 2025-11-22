<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

/**
 * Contract for migrating feature flags from external systems.
 *
 * Migrators handle the import of feature flag data from third-party systems
 * (such as Laravel Pennant or LaunchDarkly) into the Toggl feature flag system.
 * This enables seamless transitions between feature flag providers while preserving
 * existing feature configurations and user-specific states.
 *
 * The migration process typically involves:
 * - Mapping external feature definitions to Toggl's schema
 * - Preserving context-specific feature values
 * - Converting feature metadata and configurations
 * - Maintaining feature state consistency during the transition
 *
 * Implementations should be idempotent where possible, allowing migrations to be
 * safely re-run without duplicating data or causing inconsistencies.
 *
 * ```php
 * // Migrate from Laravel Pennant
 * $migrator = new PennantMigrator($driver, $pennantStore);
 * $migrator->migrate();
 *
 * // Review migration results
 * $stats = $migrator->getStatistics();
 * echo "Migrated {$stats['features']} features for {$stats['contexts']} contexts";
 *
 * if (!empty($stats['errors'])) {
 *     Log::warning('Migration errors:', $stats['errors']);
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Migrator
{
    /**
     * Migrate all feature flags from the source system.
     *
     * Imports feature definitions and their values from an external feature flag
     * system into the current driver. This includes both global features and
     * context-specific feature states. The migration process preserves feature
     * configurations, values, and associations while transforming them to match
     * the Toggl feature flag system schema.
     *
     * The migration should handle edge cases such as missing data, invalid feature
     * states, and schema mismatches gracefully, logging issues for review via
     * getStatistics() rather than failing completely.
     */
    public function migrate(): void;

    /**
     * Retrieve migration statistics and results.
     *
     * Returns comprehensive information about the migration process including
     * success counts, failure reasons, and any warnings or errors encountered.
     * This data is essential for validating the migration completed successfully
     * and identifying any issues that require manual intervention.
     *
     * The returned array contains:
     * - features: Total number of features successfully migrated
     * - contexts: Total number of unique contexts processed
     * - errors: Array of error messages for failed migrations or data inconsistencies
     *
     * @return array{features: int, contexts: int, errors: array<int, string>} Migration statistics with counts and error details
     */
    public function getStatistics(): array;
}
