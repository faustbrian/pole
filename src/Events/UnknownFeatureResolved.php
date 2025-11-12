<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Events;

/**
 * Event fired when an undefined feature is resolved.
 *
 * This event is dispatched when a feature flag is checked but hasn't been
 * defined. It allows applications to log, monitor, or handle undefined
 * feature references.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class UnknownFeatureResolved
{
    /**
     * Create a new Unknown Feature Resolved event.
     *
     * @param string $feature The name of the unknown feature that was accessed
     * @param mixed  $scope   The scope in which the feature was accessed (e.g., User, ID, or null)
     */
    public function __construct(
        public string $feature,
        public mixed $scope,
    ) {}
}
