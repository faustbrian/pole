<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Contracts;

/**
 * Interface for managing groups of related feature flags.
 *
 * Feature groups allow you to organize and manage multiple related features
 * as a single unit. This is useful for feature sets that should typically be
 * enabled or disabled together.
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface FeatureGroup
{
    /**
     * Define a feature group.
     *
     * Creates a named group containing multiple feature flags. Groups can be used
     * to activate, deactivate, or check multiple features at once.
     *
     * @param string        $name     Group name identifier
     * @param array<string> $features Array of feature names to include in the group
     */
    public function defineGroup(string $name, array $features): void;

    /**
     * Get all features in a group.
     *
     * Returns the list of feature names that belong to the specified group.
     *
     * @param  string        $name Group name identifier
     * @return array<string> Array of feature names in the group
     */
    public function getGroup(string $name): array;

    /**
     * Determine if a group exists.
     *
     * @param  string $name Group name identifier
     * @return bool   True if the group exists
     */
    public function hasGroup(string $name): bool;

    /**
     * Activate all features in a group.
     *
     * Sets all features in the group to the specified value (defaults to true).
     *
     * @param string $name  Group name identifier
     * @param mixed  $value Value to set for all features in the group
     */
    public function activateGroup(string $name, mixed $value = true): void;

    /**
     * Deactivate all features in a group.
     *
     * Sets all features in the group to false.
     *
     * @param string $name Group name identifier
     */
    public function deactivateGroup(string $name): void;
}
