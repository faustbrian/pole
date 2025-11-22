<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use Illuminate\Support\Facades\Config;
use RuntimeException;

use function sprintf;

/**
 * Thrown when attempting to activate a feature without its required prerequisites.
 *
 * This exception enforces feature dependency chains by preventing activation
 * of dependent features when their prerequisite features are not active for
 * the given context. The exception message can include specific feature names
 * based on the 'toggl.display_feature_in_exception' configuration.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MissingPrerequisitesException extends RuntimeException
{
    /**
     * Create exception for a feature with missing prerequisites.
     *
     * Returns a detailed message including feature names if the configuration
     * 'toggl.display_feature_in_exception' is enabled, otherwise returns a
     * generic message without exposing feature names for security.
     *
     * @param  string $dependentName The feature that requires prerequisites
     * @param  string $missing       Comma-separated list of missing prerequisite feature names
     * @return self   The exception instance
     */
    public static function forFeature(string $dependentName, string $missing): self
    {
        if (Config::get('toggl.display_feature_in_exception', false)) {
            return new self(
                sprintf("Cannot activate '%s': missing prerequisites [%s]", $dependentName, $missing),
            );
        }

        return new self('Cannot activate feature: missing prerequisites');
    }
}
