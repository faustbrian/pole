<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use RuntimeException;

/**
 * Thrown when a Pennant feature flag record has invalid structure or data.
 *
 * This exception is raised when database records storing feature flag states
 * do not conform to the expected schema. Valid records must include both a
 * context property (identifying the scope) and a value property (storing the
 * flag state or variant).
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidPennantRecordException extends RuntimeException
{
    /**
     * Create exception for missing or invalid context property.
     *
     * Feature flag records must include a valid context property to identify
     * the scope (user, team, organization, etc.) for which the flag state
     * applies. This ensures proper feature flag isolation and targeting.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function missingOrInvalidScope(): self
    {
        return new self('Invalid record: missing or invalid context property');
    }

    /**
     * Create exception for missing or invalid value property.
     *
     * Feature flag records must include a value property containing the flag
     * state (boolean active/inactive) or variant identifier. Missing values
     * prevent accurate feature flag resolution.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function missingOrInvalidValue(): self
    {
        return new self('Invalid record: missing or invalid value property');
    }
}
