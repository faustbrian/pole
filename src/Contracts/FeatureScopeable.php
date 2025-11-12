<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Contracts;

/**
 * Contract for objects that can be scoped to specific feature flag drivers.
 *
 * This interface allows objects to define custom behavior for how they should be
 * identified when used as a scope with different feature flag drivers. For example,
 * a User model might return different identifiers based on whether it's being used
 * with an array driver or a database driver.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface FeatureScopeable
{
    /**
     * Convert the object to a feature identifier for the given driver.
     *
     * This method allows the object to customize how it's identified as a scope
     * based on the specific driver being used. The returned value will be used
     * as the scope key when storing and retrieving feature flag values.
     *
     * @param  string $driver The name of the driver requesting the identifier
     * @return mixed  The identifier to use for this scope with the given driver
     */
    public function toFeatureIdentifier(string $driver): mixed;
}
