<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\TogglContext;
use Tests\Fixtures\User;
use Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * Create a test user for testing feature contexts.
 *
 * @param  string $name  User name
 * @param  string $email User email (defaults to name@example.com)
 * @return User   Created user instance
 */
function createUser(string $name = 'Test User', ?string $email = null): User
{
    return User::query()->create([
        'name' => $name,
        'email' => $email ?? str_replace(' ', '.', mb_strtolower($name)).'@example.com',
    ]);
}

/**
 * Create a TogglContext from a User model (includes scope scope).
 *
 * @param  User         $user The user to create context from
 * @return TogglContext The TogglContext instance with scope
 */
function userContext(User $user): TogglContext
{
    return $user->toTogglContext();
}

/**
 * Create a simple TogglContext from a User model (no scope scope).
 *
 * Use this for tests that don't require scoped feature resolution.
 *
 * @param  User         $user The user to create context from
 * @return TogglContext The TogglContext instance without scope
 */
function simpleUserContext(User $user): TogglContext
{
    return new TogglContext(
        id: $user->getKey(),
        type: User::class,
        source: $user,
    );
}
