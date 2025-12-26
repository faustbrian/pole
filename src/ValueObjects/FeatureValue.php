<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\ValueObjects;

use Cline\Toggl\Enums\FeatureState;

/**
 * Represents a feature flag state with three possible values.
 *
 * Modern PHP enum-based approach for feature flag states:
 * - Active: Feature is explicitly enabled (may carry custom values)
 * - Inactive: Feature is explicitly disabled/forbidden
 * - Undefined: Feature has never been set
 *
 * Enables clear strategy implementation:
 * - Restrictive (default-deny): undefined = treated as inactive
 * - Permissive (default-allow): undefined = treated as active
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class FeatureValue
{
    /**
     * Create a new feature state instance.
     *
     * @param FeatureState $state The enum state
     * @param mixed        $value The optional associated value for Active states
     */
    private function __construct(
        private FeatureState $state,
        private mixed $value = null,
    ) {}

    /**
     * Create FeatureState from a raw driver value.
     *
     * Maps raw values to enum cases:
     * - false → Inactive
     * - null → Undefined
     * - anything else → Active (with value attached)
     *
     * @param  mixed $value Raw value from driver (true/false/null/string/array/etc.)
     * @return self  Feature state enum case
     */
    public static function from(mixed $value): self
    {
        return match (true) {
            $value === false => new self(FeatureState::Inactive),
            $value === null => new self(FeatureState::Undefined),
            default => new self(FeatureState::Active, $value),
        };
    }

    /**
     * Check if feature is active using restrictive/default-deny logic.
     *
     * Returns true only if explicitly active.
     * Undefined features are treated as inactive (restrictive).
     *
     * @return bool True if explicitly active, false if inactive or undefined
     */
    public function isActiveRestrictive(): bool
    {
        return $this->state === FeatureState::Active;
    }

    /**
     * Check if feature is active using permissive/default-allow logic.
     *
     * Returns true unless explicitly inactive.
     * Undefined features are treated as active (permissive).
     *
     * @return bool True if active or undefined, false if explicitly inactive
     */
    public function isActivePermissive(): bool
    {
        return $this->state !== FeatureState::Inactive;
    }

    /**
     * Check if feature is explicitly inactive/forbidden.
     *
     * @return bool True if explicitly inactive
     */
    public function isForbidden(): bool
    {
        return $this->state === FeatureState::Inactive;
    }

    /**
     * Check if feature state is undefined/unknown.
     *
     * @return bool True if no value has been set for this feature
     */
    public function isUndefined(): bool
    {
        return $this->state === FeatureState::Undefined;
    }

    /**
     * Convert to boolean using restrictive logic.
     *
     * Default boolean coercion treats undefined as inactive.
     *
     * @return bool True if active, false if inactive or undefined
     */
    public function toBool(): bool
    {
        return $this->isActiveRestrictive();
    }

    /**
     * Get raw value representation.
     *
     * Returns the actual stored value, preserving custom types:
     * - Active: the attached value (or true if no value)
     * - Inactive: false
     * - Undefined: null
     *
     * @return mixed The raw feature value
     */
    public function toValue(): mixed
    {
        return match ($this->state) {
            FeatureState::Active => $this->value ?? true,
            FeatureState::Inactive => false,
            FeatureState::Undefined => null,
        };
    }

    /**
     * Get the enum state.
     *
     * @return FeatureState The underlying enum
     */
    public function state(): FeatureState
    {
        return $this->state;
    }
}
