<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

/**
 * Contract for objects that maintain a flushable in-memory cache.
 *
 * This contract defines cache invalidation for objects that cache feature flag data
 * in memory for performance optimization. Implementations typically cache resolved
 * feature values, definitions, or group memberships to avoid repeated storage lookups.
 *
 * Cache flushing is essential for:
 * - Testing scenarios where fresh state is required between tests
 * - Feature flag updates that need immediate visibility
 * - Administrative operations that modify feature definitions
 * - Development workflows requiring real-time feature changes
 *
 * The flush operation only affects the in-memory cache layer, not persistent storage.
 * After flushing, subsequent feature checks will query storage and rebuild the cache.
 *
 * ```php
 * // Update a feature in storage
 * $driver->set('new-ui', $user, true);
 *
 * // Flush cache to ensure the change is immediately visible
 * if ($driver instanceof HasFlushableCache) {
 *     $driver->flushCache();
 * }
 *
 * // Next check will reflect the updated value
 * $enabled = $driver->get('new-ui', $user); // true
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface HasFlushableCache
{
    /**
     * Flush the in-memory cache.
     *
     * Clears all cached feature flag data, forcing fresh resolution from storage
     * on the next access. This operation is immediate and affects all cached entries,
     * including feature definitions, resolved values, and any metadata.
     *
     * Performance note: After flushing, the first access to each feature will incur
     * storage lookup overhead as the cache is rebuilt. In high-traffic scenarios,
     * consider targeted invalidation strategies if available.
     */
    public function flushCache(): void;
}
