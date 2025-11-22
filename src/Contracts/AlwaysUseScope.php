<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

/**
 * Marker interface indicating a context should always use scoped resolution.
 *
 * When a context implements this interface, Toggl will automatically use
 * scoped feature resolution for that context, regardless of the global
 * toggl.scope.enabled configuration setting.
 *
 * This provides granular per-context control:
 * - Global config is false → most contexts use exact matching only
 * - Contexts implementing AlwaysUseScope → automatically use scope
 *
 * This is useful when you have a mix of context types where only some benefit
 * from scoped resolution (e.g., Users in multi-tenant orgs vs simple
 * API tokens that don't need scope).
 *
 * ```php
 * class User extends Model implements TogglContextable, AlwaysUseScope
 * {
 *     use HasTogglContext;
 *
 *     protected function getScopeAttributes(): array
 *     {
 *         return ['company_id', 'org_id', 'team_id'];
 *     }
 * }
 *
 * // Scope is automatically used, no withScopes() needed:
 * Toggl::for($user)->active('premium'); // checks scope
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface AlwaysUseScope {}
