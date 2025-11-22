<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

/**
 * Contract for managing global feature flag context.
 *
 * Provides a scoping mechanism that allows setting a global context (e.g., team,
 * account, organization) that automatically applies to all feature flag resolution,
 * enabling multi-tenancy and contextual feature activation without passing context
 * explicitly to every feature check.
 *
 * The context system acts like a scope guard, maintaining state that persists across
 * feature evaluations until explicitly cleared or replaced. This is particularly useful
 * in request-scoped scenarios where a single tenant context applies to all operations.
 *
 * ```php
 * // Set context once at the start of a request
 * Toggl::context()->to($team);
 *
 * // All subsequent checks automatically use this context
 * $enabled = Toggl::active('new-feature'); // Evaluated within team context
 * $value = Toggl::value('api-rate-limit');  // Also within team context
 *
 * // Clear when done (e.g., end of request)
 * Toggl::context()->clear();
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Context
{
    /**
     * Set the current context identifier.
     *
     * This context will be passed to all feature strategies alongside
     * the context parameter, enabling features to be evaluated within
     * a specific tenant, team, or other contextual boundary. The context
     * remains active until explicitly cleared or replaced.
     *
     * @param  mixed  $identifier The context identifier (e.g., team ID, account ID, Team model)
     * @return static Fluent interface for method chaining
     */
    public function to(mixed $identifier): static;

    /**
     * Get the current context identifier.
     *
     * @return mixed The current context, or null if no context is set
     */
    public function current(): mixed;

    /**
     * Check if a context is currently set.
     *
     * @return bool True if a context is active, false otherwise
     */
    public function hasContext(): bool;

    /**
     * Clear the current context.
     *
     * Resets the context to global, removing any active context.
     *
     * @return static Fluent interface for method chaining
     */
    public function clear(): static;
}
