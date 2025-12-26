<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use function sprintf;

/**
 * Thrown when variant weights do not sum to 100 for A/B testing.
 *
 * Weight values represent percentage probabilities and must sum to exactly
 * 100 to ensure all users are assigned to variants. Incorrect sums cause
 * unintended traffic distribution, leaving users unassigned (sum < 100)
 * or causing overlap (sum > 100).
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class VariantWeightsSumException extends InvalidVariantWeightsException
{
    /**
     * Create exception when variant weights do not sum to 100.
     *
     * @param  int  $total The actual sum of all variant weights
     * @return self Exception instance with descriptive error message
     */
    public static function forTotal(int $total): self
    {
        return new self(
            sprintf('Variant weights must sum to 100, got %d', $total),
        );
    }
}
