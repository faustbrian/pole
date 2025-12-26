<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

/**
 * Thrown when variant weights array is empty for A/B testing.
 *
 * Variant-based feature flags require at least one weighted variant to
 * determine which version users receive. Empty weight arrays prevent
 * any variant assignment and make feature resolution impossible.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class EmptyVariantWeightsException extends InvalidVariantWeightsException
{
    /**
     * Create exception for empty variant weights array.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function create(): self
    {
        return new self('Variant weights array cannot be empty');
    }
}
