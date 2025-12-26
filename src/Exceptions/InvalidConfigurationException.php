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
 * Exception thrown when Toggl configuration is invalid or conflicting.
 *
 * Toggls configuration errors detected during service provider bootstrapping,
 * particularly conflicts between mutually exclusive configuration options like
 * polymorphic key mapping strategies. These errors indicate application setup
 * issues that must be resolved before the feature flag system can function.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidConfigurationException extends RuntimeException implements TogglException
{
    /**
     * Create an exception for conflicting morph key map configurations.
     *
     * Thrown when both morphKeyMap and enforceMorphKeyMap are configured simultaneously
     * in the toggl.php config file. These options are mutually exclusive: morphKeyMap
     * provides optional polymorphic key mappings with fallback behavior, while
     * enforceMorphKeyMap requires strict mapping and throws exceptions for unmapped types.
     *
     * @return self Exception instance with descriptive error message
     */
    public static function conflictingMorphKeyMaps(): self
    {
        return new self(
            'Cannot configure both morphKeyMap and enforceMorphKeyMap simultaneously. '.
            'Choose one: use morphKeyMap for optional mapping or enforceMorphKeyMap for strict enforcement.',
        );
    }
}
