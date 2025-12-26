<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Http\Middleware;

use Cline\Toggl\Toggl;
use Closure;
use Illuminate\Http\Request;

use function abort;
use function implode;

/**
 * Middleware using "on unless off" strategy for feature access control.
 *
 * Allows requests to proceed unless features are explicitly forbidden.
 * Implements inverted logic where unknown features are permitted by default.
 *
 * Use this middleware when:
 * - Features should be accessible by default
 * - Only explicitly blocked contexts should be restricted
 * - Progressive rollback scenarios (deactivate for problematic users)
 *
 * ```php
 * Route::middleware(['toggl.not-forbidden:beta-ui,api-v2'])->group(function () {
 *     // These routes are accessible unless explicitly forbidden
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class EnsureFeaturesAreNotForbidden
{
    /**
     * Custom response handler for forbidden features.
     *
     * @var null|Closure(Request, array<string>): mixed
     */
    private static ?Closure $whenForbiddenCallback = null;

    /**
     * Set a custom handler for forbidden feature responses.
     *
     * ```php
     * EnsureFeaturesAreNotForbidden::whenForbidden(function ($request, $features) {
     *     return response()->json(['message' => 'Access restricted'], 403);
     * });
     * ```
     *
     * @param null|Closure(Request $request, array<string> $features): mixed $callback
     */
    public static function whenForbidden(?Closure $callback): void
    {
        self::$whenForbiddenCallback = $callback;
    }

    /**
     * Handle the incoming request.
     *
     * Checks if any specified features are explicitly forbidden for the request context.
     * Aborts with 403 if features are forbidden, otherwise allows the request through.
     *
     * @param  Request $request     HTTP request instance containing the context
     * @param  Closure $next        Next middleware handler in the pipeline
     * @param  string  ...$features Feature names to check (comma-separated in route definition)
     * @return mixed   Response from next middleware or forbidden response
     */
    public function handle(Request $request, Closure $next, string ...$features): mixed
    {
        $context = $request->user() ?? $request;

        // Check if ANY features are explicitly forbidden
        foreach ($features as $feature) {
            if (Toggl::for($context)->isForbidden($feature)) {
                return $this->handleForbidden($request, $features);
            }
        }

        return $next($request);
    }

    /**
     * Handle forbidden feature response.
     *
     * @param  Request       $request  The current request
     * @param  array<string> $features Features that are forbidden
     * @return mixed         Custom response or abort with 403
     */
    private function handleForbidden(Request $request, array $features): mixed
    {
        if (self::$whenForbiddenCallback instanceof Closure) {
            return (self::$whenForbiddenCallback)($request, $features);
        }

        abort(
            403,
            'Access to features ['.implode(', ', $features).'] is forbidden.',
        );
    }
}
