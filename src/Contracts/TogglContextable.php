<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

use Cline\Toggl\Support\TogglContext;

/**
 * Contract for objects that provide unified feature context information.
 *
 * This interface provides a single method that returns a TogglContext value
 * object containing:
 * - Context identifier (id)
 * - Context type for polymorphic storage
 * - Optional scope scope for scoped feature resolution
 *
 * Implementing this interface allows models to fully describe their feature
 * context in one place, including any scoped relationships.
 *
 * ```php
 * class User extends Model implements TogglContextable
 * {
 *     public function toTogglContext(): TogglContext
 *     {
 *         return TogglContext::withScopes(
 *             id: $this->id,
 *             type: static::class,
 *             scope: new FeatureScope('user', [
 *                 'company_id' => $this->company_id,
 *                 'org_id' => $this->org_id,
 *                 'team_id' => $this->team_id,
 *                 'user_id' => $this->id,
 *             ])
 *         );
 *     }
 * }
 * ```
 *
 * For simple contexts without scope:
 * ```php
 * class Feature implements TogglContextable
 * {
 *     public function toTogglContext(): TogglContext
 *     {
 *         return TogglContext::simple($this->id, static::class);
 *     }
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface TogglContextable
{
    /**
     * Convert this object to a TogglContext for feature operations.
     *
     * Returns a unified context representation containing the identifier,
     * type, and optional scope scope. This context is used for all
     * feature flag operations including activation, deactivation, and lookup.
     *
     * @return TogglContext The context representation for feature operations
     */
    public function toTogglContext(): TogglContext;
}
