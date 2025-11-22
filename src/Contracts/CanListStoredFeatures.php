<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

/**
 * Contract for drivers that can list features stored in persistent storage.
 *
 * Provides enumeration capabilities for feature flag drivers that persist data
 * to storage backends like databases or caches. This is primarily used for
 * auditing which features have been explicitly configured or activated, as
 * opposed to features that only exist as code definitions.
 *
 * Implementing this interface enables operations like:
 * - Auditing which features are actively configured in storage
 * - Identifying orphaned features no longer defined in code
 * - Debugging feature flag states across environments
 * - Cleaning up unused or deprecated features from storage
 *
 * ```php
 * $driver = app(Driver::class);
 * if ($driver instanceof CanListStoredFeatures) {
 *     $storedFeatures = $driver->stored();
 *     // ['new-ui', 'beta-api', 'premium-features']
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface CanListStoredFeatures
{
    /**
     * Retrieve the names of all features stored in persistent storage.
     *
     * Returns feature names that have been explicitly written to the storage
     * backend, regardless of their current enabled/disabled state. This does
     * not include features that are only defined in code but have never been
     * persisted to storage.
     *
     * The returned array is useful for comparing defined features against
     * stored features to identify discrepancies or orphaned data that may
     * need cleanup during deployments or migrations.
     *
     * @return array<int, string> List of feature names present in storage
     */
    public function stored(): array;
}
