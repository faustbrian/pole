<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use BackedEnum;
use Cline\Toggl\Exceptions\MissingResolverOrDefaultException;
use Cline\Toggl\FeatureManager;
use Closure;

/**
 * Conductor for fluent feature definition.
 *
 * Provides a chainable API for defining features with resolvers, defaults, and metadata.
 * Features must be configured with either a resolver closure or a default value before
 * registration. The resolver or default value determines the feature's behavior and state.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class FluentDefinitionConductor
{
    private const string NO_RESOLVER = '__NO_RESOLVER__';

    /**
     * Create a new fluent definition conductor instance.
     *
     * @param FeatureManager    $manager     Feature manager instance for managing feature registration
     *                                       and coordinating feature definitions across the application
     * @param BackedEnum|string $feature     Feature identifier to define. Supports string name or backed
     *                                       enum for type-safe feature references throughout the codebase
     * @param Closure|mixed     $resolver    Feature resolver closure for dynamic state determination, or static
     *                                       default value for constant feature state. Uses NO_RESOLVER sentinel
     *                                       constant when neither resolvedBy() nor defaultTo() has been called
     * @param null|string       $description Human-readable feature description for documentation and debugging.
     *                                       Explains the feature's purpose, behavior, and usage patterns
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum $feature,
        private mixed $resolver = self::NO_RESOLVER,
        private ?string $description = null,
    ) {}

    /**
     * Set the feature resolver (callable that determines feature state).
     *
     * Configures dynamic feature state determination via closure. The resolver is invoked
     * at runtime to compute the feature's state based on context and configuration.
     * Mutually exclusive with defaultTo() - last one called takes precedence.
     *
     * @param  Closure $resolver Feature resolver closure determining runtime state. Receives context
     *                           and feature metadata, returns boolean state or configuration value
     * @return self    New immutable conductor instance with resolver configured
     */
    public function resolvedBy(Closure $resolver): self
    {
        return new self($this->manager, $this->feature, $resolver, $this->description);
    }

    /**
     * Set a default value for the feature.
     *
     * Returns a new conductor instance with a static default value configured.
     * This is simpler than resolvedBy() when the feature has a constant value.
     * Mutually exclusive with resolvedBy() - last one called takes precedence.
     *
     * @param  mixed $value Default value for the feature. Can be boolean for simple flags,
     *                      or any serializable value for features with configuration data
     * @return self  New conductor instance with default value configured
     */
    public function defaultTo(mixed $value): self
    {
        return new self($this->manager, $this->feature, $value, $this->description);
    }

    /**
     * Set the feature description.
     *
     * Returns a new conductor instance with description configured. The description
     * serves as documentation for the feature's purpose and usage.
     *
     * @param  string $description Feature description text explaining purpose and behavior
     * @return self   New conductor instance with description configured
     */
    public function describedAs(string $description): self
    {
        return new self($this->manager, $this->feature, $this->resolver, $description);
    }

    /**
     * Register the feature definition (terminal method).
     *
     * Validates that a resolver or default value has been configured, then registers
     * the feature with the driver. This finalizes the feature definition and makes it
     * available for use throughout the system.
     *
     * @throws MissingResolverOrDefaultException If no resolver or default value configured via resolvedBy() or defaultTo()
     */
    public function register(): void
    {
        if ($this->resolver === self::NO_RESOLVER) {
            $featureName = $this->feature instanceof BackedEnum ? $this->feature->value : $this->feature;

            throw MissingResolverOrDefaultException::forFeature((string) $featureName);
        }

        // Register the feature with the driver
        $this->manager->driver()->define($this->feature, $this->resolver);
    }

    /**
     * Get the feature name.
     *
     * Returns the feature identifier being defined, either as a string or backed enum value.
     *
     * @return BackedEnum|string The feature identifier
     */
    public function feature(): string|BackedEnum
    {
        return $this->feature;
    }

    /**
     * Get the resolver.
     *
     * Returns the configured resolver closure or default value. May be the NO_RESOLVER
     * sentinel constant if neither resolvedBy() nor defaultTo() has been called yet.
     *
     * @return Closure|mixed The resolver closure, default value, or NO_RESOLVER sentinel
     */
    public function resolver(): mixed
    {
        return $this->resolver;
    }

    /**
     * Get the description.
     *
     * Returns the feature description configured via describedAs(), or null if no
     * description has been set.
     *
     * @return null|string The feature description, or null if not configured
     */
    public function description(): ?string
    {
        return $this->description;
    }
}
