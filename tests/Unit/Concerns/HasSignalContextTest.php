<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Contracts\TogglContextable;
use Cline\Toggl\Support\TogglContext;
use Tests\Fixtures\HierarchicalModel;
use Tests\Fixtures\SimpleModel;

describe('HasTogglContext', function (): void {
    describe('Simple Model (no scope)', function (): void {
        test('toTogglContext returns simple context', function (): void {
            $model = new SimpleModel();
            $model->id = 123;
            $model->name = 'Test';

            $context = $model->toTogglContext();

            expect($context)->toBeInstanceOf(TogglContext::class);
            expect($context->id)->toBe(123);
            expect($context->type)->toBe(SimpleModel::class);
            expect($context->hasScope())->toBeFalse();
        });

        test('uses model class as type', function (): void {
            $model = new SimpleModel(['id' => 1]);

            $context = $model->toTogglContext();

            expect($context->type)->toBe(SimpleModel::class);
        });
    });

    describe('Hierarchical Model', function (): void {
        test('toTogglContext returns context with scope', function (): void {
            $model = new HierarchicalModel([
                'id' => 456,
                'company_id' => 10,
                'org_id' => 20,
                'team_id' => 30,
            ]);

            $context = $model->toTogglContext();

            expect($context)->toBeInstanceOf(TogglContext::class);
            expect($context->id)->toBe(456);
            expect($context->type)->toBe(HierarchicalModel::class);
            expect($context->hasScope())->toBeTrue();
        });

        test('scope scope includes configured attributes', function (): void {
            $model = new HierarchicalModel([
                'id' => 1,
                'company_id' => 5,
                'org_id' => 10,
                'team_id' => 15,
            ]);

            $context = $model->toTogglContext();
            $scope = $context->scope;

            expect($scope->constraints['company_id'])->toBe(5);
            expect($scope->constraints['org_id'])->toBe(10);
            expect($scope->constraints['team_id'])->toBe(15);
        });

        test('scope scope includes model primary key', function (): void {
            $model = new HierarchicalModel(['id' => 99, 'company_id' => 1]);

            $context = $model->toTogglContext();

            expect($context->scope->constraints['id'])->toBe(99);
        });

        test('uses custom scope kind', function (): void {
            $model = new HierarchicalModel(['id' => 1, 'company_id' => 1]);

            $context = $model->toTogglContext();

            expect($context->scope->kind)->toBe('member');
        });

        test('includes null values in scope scope', function (): void {
            $model = new HierarchicalModel([
                'id' => 1,
                'company_id' => 5,
                'org_id' => null,
                'team_id' => null,
            ]);

            $context = $model->toTogglContext();
            $scope = $context->scope;

            expect($scope->constraints['company_id'])->toBe(5);
            expect($scope->constraints['org_id'])->toBeNull();
            expect($scope->constraints['team_id'])->toBeNull();
        });
    });

    describe('Interface Contract', function (): void {
        test('simple model implements TogglContextable', function (): void {
            $model = new SimpleModel(['id' => 1]);

            expect($model)->toBeInstanceOf(TogglContextable::class);
        });

        test('scoped model implements TogglContextable', function (): void {
            $model = new HierarchicalModel(['id' => 1]);

            expect($model)->toBeInstanceOf(TogglContextable::class);
        });
    });
});
