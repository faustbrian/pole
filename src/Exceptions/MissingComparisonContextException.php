<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use LogicException;

/**
 * Thrown when comparing feature flag states without specifying the second context.
 *
 * This exception is raised when attempting to perform context-to-context feature
 * flag comparisons without providing the second comparison target. Comparison
 * operations require both contexts to evaluate relative feature flag differences
 * or analyze rollout consistency across different scopes.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MissingComparisonContextException extends LogicException implements TogglException
{
    /**
     * Create exception for missing comparison target context.
     *
     * Feature flag comparison operations analyze differences between two contexts
     * (e.g., comparing one user's flags against another, or team against team).
     * The second context must be explicitly specified using the against() method
     * to establish the comparison baseline.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function notProvided(): self
    {
        return new self('Second context not provided. Use against($context2) to specify comparison target.');
    }
}
