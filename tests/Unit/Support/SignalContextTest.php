<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\FeatureScope;
use Cline\Toggl\Support\TogglContext;

describe('TogglContext', function (): void {
    describe('Construction', function (): void {
        test('can create context with all properties', function (): void {
            $scope = new FeatureScope('user', ['company_id' => 1]);
            $context = new TogglContext(123, 'App\\Models\\User', $scope);

            expect($context->id)->toBe(123);
            expect($context->type)->toBe('App\\Models\\User');
            expect($context->scope)->toBe($scope);
        });

        test('can create context without scope scope', function (): void {
            $context = new TogglContext(456, 'App\\Models\\Team');

            expect($context->id)->toBe(456);
            expect($context->type)->toBe('App\\Models\\Team');
            expect($context->scope)->toBeNull();
        });

        test('supports string identifiers', function (): void {
            $context = new TogglContext('uuid-123', 'App\\Models\\Organization');

            expect($context->id)->toBe('uuid-123');
        });
    });

    describe('Factory Methods', function (): void {
        test('simple creates context without scope', function (): void {
            $context = TogglContext::simple(123, 'App\\Models\\User');

            expect($context->id)->toBe(123);
            expect($context->type)->toBe('App\\Models\\User');
            expect($context->scope)->toBeNull();
            expect($context->hasScope())->toBeFalse();
        });

        test('withScopes creates context with scope scope', function (): void {
            $scope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => 5,
            ]);

            $context = TogglContext::withScope(123, 'App\\Models\\User', $scope);

            expect($context->id)->toBe(123);
            expect($context->type)->toBe('App\\Models\\User');
            expect($context->scope)->toBe($scope);
            expect($context->hasScope())->toBeTrue();
        });
    });

    describe('hasScope', function (): void {
        test('returns false when no scope scope', function (): void {
            $context = new TogglContext(1, 'User');

            expect($context->hasScope())->toBeFalse();
        });

        test('returns true when scope scope present', function (): void {
            $context = new TogglContext(1, 'User', new FeatureScope('user', []));

            expect($context->hasScope())->toBeTrue();
        });
    });

    describe('withFeatureScope', function (): void {
        test('creates new instance with updated scope', function (): void {
            $original = TogglContext::simple(123, 'User');
            $newScope = new FeatureScope('user', ['company_id' => 5]);

            $updated = $original->withFeatureScope($newScope);

            expect($original->scope)->toBeNull();
            expect($updated->scope)->toBe($newScope);
            expect($updated->id)->toBe(123);
            expect($updated->type)->toBe('User');
        });

        test('can remove scope scope with null', function (): void {
            $scope = new FeatureScope('user', ['company_id' => 5]);
            $original = TogglContext::withScope(123, 'User', $scope);

            $updated = $original->withFeatureScope(null);

            expect($original->hasScope())->toBeTrue();
            expect($updated->hasScope())->toBeFalse();
        });
    });

    describe('toCacheKey', function (): void {
        test('generates key without scope', function (): void {
            $context = TogglContext::simple(123, 'App\\Models\\User');

            expect($context->toCacheKey())->toBe('App\\Models\\User:123');
        });

        test('generates key with scope included', function (): void {
            $scope = new FeatureScope('user', ['company_id' => 3, 'org_id' => 5]);
            $context = TogglContext::withScope(123, 'App\\Models\\User', $scope);

            $key = $context->toCacheKey();

            expect($key)->toContain('App\\Models\\User:123');
            expect($key)->toContain('user:');
            expect($key)->toContain('company_id=3');
            expect($key)->toContain('org_id=5');
        });

        test('handles string identifiers', function (): void {
            $context = TogglContext::simple('uuid-abc', 'Team');

            expect($context->toCacheKey())->toBe('Team:uuid-abc');
        });
    });

    describe('toArray', function (): void {
        test('converts context without scope to array', function (): void {
            $context = TogglContext::simple(123, 'User');

            expect($context->toArray())->toBe([
                'id' => 123,
                'type' => 'User',
                'scope' => null,
            ]);
        });

        test('converts context with scope to array', function (): void {
            $scope = new FeatureScope('user', ['company_id' => 3]);
            $context = TogglContext::withScope(123, 'User', $scope);

            expect($context->toArray())->toBe([
                'id' => 123,
                'type' => 'User',
                'scope' => [
                    'kind' => 'user',
                    'scopes' => ['company_id' => 3],
                ],
            ]);
        });
    });
});
