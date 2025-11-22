<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use BackedEnum;
use Cline\Toggl\FeatureManager;

/**
 * Context conductor for chainable feature operations within a fixed context.
 *
 * Provides an ergonomic API for performing multiple feature operations on the same context
 * without repeatedly specifying the context. Implements the "within" pattern that
 * binds a context once and allows fluent chaining of activate, deactivate, and group operations.
 *
 * ```php
 * Toggl::within($organization)
 *     ->activate(['analytics', 'reporting'])
 *     ->deactivate('legacy-export')
 *     ->activateGroup('enterprise-features')
 *     ->activateWithValue('theme', 'dark');
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class ContextConductor
{
    /**
     * Create a new context conductor instance.
     *
     * Binds a feature manager and context together, enabling subsequent operations to execute
     * against this fixed context without re-specification.
     *
     * @param FeatureManager $manager Feature manager instance for executing feature operations
     *                                and managing state across different contexts
     * @param mixed          $context Context entity (user, team, organization, etc.) that all
     *                                subsequent operations target. Remains bound throughout the
     *                                fluent method chain for ergonomic multi-operation workflows
     */
    public function __construct(
        private FeatureManager $manager,
        private mixed $context,
    ) {}

    /**
     * Activate one or more features for the bound context.
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $features Feature(s) to activate with default value true
     * @return self                                       Fluent interface for continued chaining
     */
    public function activate(string|BackedEnum|array $features): self
    {
        $this->manager->for($this->context)->activate($features);

        return $this;
    }

    /**
     * Deactivate one or more features for the bound context.
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $features Feature(s) to deactivate
     * @return self                                       Fluent interface for continued chaining
     */
    public function deactivate(string|BackedEnum|array $features): self
    {
        $this->manager->for($this->context)->deactivate($features);

        return $this;
    }

    /**
     * Activate a feature with a custom value for the bound context.
     *
     * @param  BackedEnum|string $feature Feature to activate with custom value
     * @param  mixed             $value   Value to associate with the feature (configuration data, settings, etc.)
     * @return self              Fluent interface for continued chaining
     */
    public function activateWithValue(string|BackedEnum $feature, mixed $value): self
    {
        $this->manager->for($this->context)->activate($feature, $value);

        return $this;
    }

    /**
     * Activate an entire feature group for the bound context.
     *
     * @param  string $groupName Name of the feature group to activate
     * @return self   Fluent interface for continued chaining
     */
    public function activateGroup(string $groupName): self
    {
        $this->manager->for($this->context)->activateGroup($groupName);

        return $this;
    }

    /**
     * Deactivate an entire feature group for the bound context.
     *
     * @param  string $groupName Name of the feature group to deactivate
     * @return self   Fluent interface for continued chaining
     */
    public function deactivateGroup(string $groupName): self
    {
        $this->manager->for($this->context)->deactivateGroup($groupName);

        return $this;
    }
}
