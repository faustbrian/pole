<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Enums;

/**
 * Defines available snapshot storage drivers.
 *
 * Determines how feature snapshots are persisted and retrieved. Each driver
 * offers different trade-offs between performance, persistence, and features.
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum SnapshotDriver: string
{
    /**
     * In-memory array storage.
     *
     * Fast but non-persistent. Snapshots lost between requests.
     * Suitable for testing and temporary snapshots within a single request.
     */
    case Array = 'array';

    /**
     * Database storage with full audit trails.
     *
     * Persistent storage with dedicated tables for snapshots, entries, and events.
     * Provides complete historical tracking, granular replay/revert capabilities,
     * and audit metadata including who created/restored snapshots and when.
     *
     * Recommended for production use when snapshot history is critical.
     */
    case Database = 'database';

    /**
     * Cache-based storage.
     *
     * Fast with optional persistence depending on cache driver.
     * Suitable for temporary snapshots with TTL-based expiration.
     * No audit trail or historical tracking.
     */
    case Cache = 'cache';
}
