<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Http\Middleware;

use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;
use Closure;
use Illuminate\Http\Request;

use function abort;
use function array_values;
use function call_user_func;
use function config;
use function implode;

/**
 * Middleware to ensure specified features are active before allowing request.
 *
 * Aborts with 400 if any required features are inactive.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class EnsureFeaturesAreActive
{
    /**
     * Custom response handler for inactive features.
     */
    private static ?Closure $respondUsing = null;

    /**
     * Specify the features for the middleware.
     */
    public static function using(string ...$features): string
    {
        return self::class.':'.implode(',', $features);
    }

    /**
     * Specify a callback that should be used to generate responses for failed feature checks.
     */
    public static function whenInactive(?Closure $callback): void
    {
        self::$respondUsing = $callback;
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$features): mixed
    {
        $context = $request->user() ?? TogglContext::simple('guest', 'guest');

        /** @var array<int, string> $features */
        $features = array_values($features);

        if (Toggl::for($context)->someAreInactive($features)) {
            return $this->handleInactive($request, $features);
        }

        return $next($request);
    }

    /**
     * Handle inactive feature response.
     *
     * @param array<int, string> $features
     */
    private function handleInactive(Request $request, array $features): mixed
    {
        if (self::$respondUsing instanceof Closure) {
            return call_user_func(self::$respondUsing, $request, $features);
        }

        $error = config('app.debug')
            ? 'Required features ['.implode(', ', $features).'] are not active.'
            : '';

        abort(400, $error);
    }
}
