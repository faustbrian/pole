<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Contracts;

/**
 * Interface for objects that can be serialized as feature scope identifiers.
 *
 * This interface allows custom objects to define how they should be serialized
 * when used as scope identifiers in the feature flag system. Implementing this
 * interface ensures consistent serialization for cache keys and storage.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface FeatureScopeSerializeable
{
    /**
     * Serialize the object for feature storage.
     *
     * This method should return a unique string representation of the object
     * that can be used as a scope identifier in the feature flag system.
     *
     * @return string A unique string identifier for this scope
     */
    public function featureScopeSerialize(): string;
}
