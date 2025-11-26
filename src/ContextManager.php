<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl;

use Cline\Toggl\Contracts\Context;

/**
 * Default implementation of the Context contract.
 *
 * Manages a global context identifier that can be used to scope feature flag
 * resolution to specific tenants, teams, organizations, or other contextual
 * boundaries. This enables multi-tenant applications to isolate feature flags
 * per tenant without passing context explicitly in every feature check.
 *
 * The manager automatically flushes the feature cache when context changes to
 * ensure features are re-evaluated with the new context, preventing stale
 * cached values from affecting feature resolution.
 *
 * ```php
 * $context = app(Context::class);
 *
 * // Set context for current tenant
 * $context->to($tenant->id);
 *
 * // Check if context is set
 * if ($context->hasContext()) {
 *     $currentContext = $context->current();
 * }
 *
 * // Clear context when done
 * $context->clear();
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ContextManager implements Context
{
    /**
     * The current context identifier for feature scoping.
     *
     * Stores the active context (e.g., tenant ID, organization ID, team ID) that
     * should be applied to all feature flag evaluations. Null indicates no specific
     * context is set (global context).
     */
    private mixed $context = null;

    /**
     * The feature manager instance for automatic cache invalidation.
     *
     * When set, the manager's cache is automatically flushed whenever the context
     * changes to ensure fresh feature evaluation with the new context. This prevents
     * stale cached values from affecting feature resolution.
     */
    private ?FeatureManager $featureManager = null;

    /**
     * Set the current context identifier for feature scoping.
     *
     * Changes the global context for feature flag evaluation. Automatically flushes
     * the feature manager cache to ensure all subsequent feature checks use the new
     * context. This is commonly used in multi-tenant applications to switch between
     * tenants or organizations, ensuring features are evaluated in the correct context.
     *
     * @param  mixed $identifier The context identifier (tenant ID, organization ID, team ID, etc.)
     * @return $this For method chaining
     */
    public function to(mixed $identifier): static
    {
        $this->context = $identifier;

        // Flush cache when context changes to ensure fresh feature evaluation
        if ($this->featureManager instanceof FeatureManager) {
            $this->featureManager->flushCache();
        }

        return $this;
    }

    /**
     * Set the feature manager for automatic cache invalidation.
     *
     * Registers the feature manager so the context can automatically flush its cache
     * when the context changes, ensuring features are re-evaluated with the new
     * context. This enables automatic cache management without manual intervention.
     *
     * @param  FeatureManager $manager The feature manager instance to coordinate with
     * @return $this          For method chaining
     */
    public function setFeatureManager(FeatureManager $manager): static
    {
        $this->featureManager = $manager;

        return $this;
    }

    /**
     * Get the current context identifier.
     *
     * @return mixed The current context identifier, or null if no context is set (global context)
     */
    public function current(): mixed
    {
        return $this->context;
    }

    /**
     * Check if a context is currently set.
     *
     * Returns true if a non-null context has been set via to(). When no context
     * is set, features are evaluated in global context.
     *
     * @return bool True if a context is active, false if using global context
     */
    public function hasContext(): bool
    {
        return $this->context !== null;
    }

    /**
     * Clear the current context and return to global context.
     *
     * Resets the context to null and flushes the feature cache to ensure
     * feature flags are re-evaluated without context constraints. Use this
     * when returning to the default global context or when cleaning up after
     * a contextual operation.
     *
     * @return $this For method chaining
     */
    public function clear(): static
    {
        $this->context = null;

        // Flush cache when context is cleared to ensure fresh feature evaluation
        if ($this->featureManager instanceof FeatureManager) {
            $this->featureManager->flushCache();
        }

        return $this;
    }
}
