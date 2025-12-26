<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

/**
 * Thrown when a Pennant feature flag record has missing or invalid context property.
 *
 * Feature flag records must include a valid context property to identify
 * the scope (user, team, organization, etc.) for which the flag state
 * applies. This ensures proper feature flag isolation and targeting.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MissingPennantRecordScopeException extends InvalidPennantRecordException
{
    /**
     * Create exception for missing or invalid context property.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function create(): self
    {
        return new self('Invalid record: missing or invalid context property');
    }
}
