<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Contracts;

/**
 * Interface for drivers that can list features stored in persistent storage.
 *
 * This interface allows feature drivers to report which feature flags have
 * been persisted to storage (database, cache, etc.). This is useful for
 * auditing, debugging, or cleaning up unused features.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface CanListStoredFeatures
{
    /**
     * Retrieve the names of all stored features.
     *
     * Returns a list of feature names that have been persisted to storage,
     * regardless of their defined state or values. This typically includes
     * features that have been explicitly activated, deactivated, or configured
     * for specific scopes.
     *
     * @return array<string> Array of feature names present in storage
     */
    public function stored(): array;
}
