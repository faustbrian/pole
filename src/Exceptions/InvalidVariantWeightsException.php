<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use InvalidArgumentException;

use function sprintf;

/**
 * Thrown when variant weight configuration is invalid for A/B testing.
 *
 * This exception is raised when variant weight arrays used for A/B testing
 * and multivariate feature rollouts do not meet mathematical requirements.
 * Weights must be non-empty and sum to exactly 100 to ensure proper
 * probability distribution across all variants.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidVariantWeightsException extends InvalidArgumentException
{
    /**
     * Create exception for empty variant weights array.
     *
     * Variant-based feature flags require at least one weighted variant to
     * determine which version users receive. Empty weight arrays prevent
     * any variant assignment and make feature resolution impossible.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function cannotBeEmpty(): self
    {
        return new self('Variant weights array cannot be empty');
    }

    /**
     * Create exception when variant weights do not sum to 100.
     *
     * Weight values represent percentage probabilities and must sum to exactly
     * 100 to ensure all users are assigned to variants. Incorrect sums cause
     * unintended traffic distribution, leaving users unassigned (sum < 100)
     * or causing overlap (sum > 100).
     *
     * @param  int  $total The actual sum of all variant weights
     * @return self Exception instance with descriptive error message
     */
    public static function mustSumTo100(int $total): self
    {
        return new self(
            sprintf('Variant weights must sum to 100, got %d', $total),
        );
    }
}
