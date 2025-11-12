<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Contracts;

/**
 * Interface for objects that maintain a flushable in-memory cache.
 *
 * This interface defines a contract for objects that cache feature flag data
 * in memory and need a way to clear that cache. This is useful for testing,
 * or when feature flags are updated and the cache needs to be refreshed.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface HasFlushableCache
{
    /**
     * Flush the in-memory cache.
     *
     * Clears all cached feature flag data, forcing fresh resolution on the next access.
     * This does not affect persistent storage, only the in-memory cache.
     */
    public function flushCache(): void;
}
