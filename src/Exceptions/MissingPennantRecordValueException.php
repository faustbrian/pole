<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

/**
 * Thrown when a Pennant feature flag record has missing or invalid value property.
 *
 * Feature flag records must include a value property containing the flag
 * state (boolean active/inactive) or variant identifier. Missing values
 * prevent accurate feature flag resolution.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MissingPennantRecordValueException extends InvalidPennantRecordException
{
    /**
     * Create exception for missing or invalid value property.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function create(): self
    {
        return new self('Invalid record: missing or invalid value property');
    }
}
