<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Conductors\LazyEvaluationConductor;
use Cline\Toggl\FeatureManager;
use Cline\Toggl\Support\BatchEvaluationResult;
use Cline\Toggl\Support\LazyEvaluation;
use Cline\Toggl\Toggl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\BatchTestUser;
use Tests\Fixtures\TestFeature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::dropIfExists('features');
    Schema::dropIfExists('users');

    $primaryKeyType = config('toggl.primary_key_type', 'id');

    Schema::create('features', function ($table) use ($primaryKeyType): void {
        match ($primaryKeyType) {
            'ulid' => $table->ulid('id')->primary(),
            'uuid' => $table->uuid('id')->primary(),
            default => $table->id(),
        };

        $table->string('name');
        $table->string('context');
        $table->text('value');
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->unique(['name', 'context']);
    });

    Schema::create('users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
    });
});

describe('FeatureManager Batch Evaluation', function (): void {
    describe('Happy Path', function (): void {
        describe('lazy() Method', function (): void {
            test('returns LazyEvaluationConductor', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);

                // Act
                $conductor = $manager->lazy('premium');

                // Assert
                expect($conductor)->toBeInstanceOf(LazyEvaluationConductor::class);
            });

            test('accepts BackedEnum feature', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);

                // Act
                $conductor = $manager->lazy(TestFeature::Premium);
                $evaluation = $conductor->for('user-1');

                // Assert
                expect($evaluation->feature)->toBe('premium');
            });

            test('creates LazyEvaluation when for() is called', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);
                $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'test@example.com']);

                // Act
                $evaluation = $manager->lazy('premium')->for($user);

                // Assert
                expect($evaluation)->toBeInstanceOf(LazyEvaluation::class);
                expect($evaluation->feature)->toBe('premium');
                expect($evaluation->context)->toBe($user);
            });
        });

        describe('evaluate() Method', function (): void {
            test('returns BatchEvaluationResult', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);
                $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'test@example.com']);
                $evaluations = [
                    $manager->lazy('premium')->for($user),
                ];

                // Act
                $result = $manager->evaluate($evaluations);

                // Assert
                expect($result)->toBeInstanceOf(BatchEvaluationResult::class);
            });

            test('evaluates multiple features for same context', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);
                $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'test@example.com']);

                // Activate some features
                $manager->for($user)->activate('premium');
                $manager->for($user)->activate('analytics');
                // reporting stays inactive

                $evaluations = [
                    $manager->lazy('premium')->for($user),
                    $manager->lazy('analytics')->for($user),
                    $manager->lazy('reporting')->for($user),
                ];

                // Act
                $result = $manager->evaluate($evaluations);

                // Assert
                expect($result->count())->toBe(3);
                expect($result->countActive())->toBe(2);
                expect($result->countInactive())->toBe(1);
            });

            test('evaluates same feature for multiple contexts', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);
                $user1 = BatchTestUser::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
                $user2 = BatchTestUser::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);
                $user3 = BatchTestUser::query()->create(['name' => 'User 3', 'email' => 'user3@example.com']);

                // Activate for some users
                $manager->for($user1)->activate('premium');
                $manager->for($user3)->activate('premium');
                // user2 stays inactive

                $evaluations = [
                    $manager->lazy('premium')->for($user1),
                    $manager->lazy('premium')->for($user2),
                    $manager->lazy('premium')->for($user3),
                ];

                // Act
                $result = $manager->evaluate($evaluations);

                // Assert
                expect($result->count())->toBe(3);
                expect($result->forFeature('premium')->countActive())->toBe(2);
                expect($result->forFeature('premium')->countInactive())->toBe(1);
            });

            test('evaluates mixed features and contexts', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);
                $user1 = BatchTestUser::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
                $user2 = BatchTestUser::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);

                // Setup: user1 has premium, user2 has analytics
                $manager->for($user1)->activate('premium');
                $manager->for($user2)->activate('analytics');

                $evaluations = [
                    $manager->lazy('premium')->for($user1),
                    $manager->lazy('premium')->for($user2),
                    $manager->lazy('analytics')->for($user1),
                    $manager->lazy('analytics')->for($user2),
                ];

                // Act
                $result = $manager->evaluate($evaluations);

                // Assert
                expect($result->count())->toBe(4);
                expect($result->countActive())->toBe(2);
                expect($result->forContext($user1)->countActive())->toBe(1);
                expect($result->forContext($user2)->countActive())->toBe(1);
            });

            test('all() returns true when all features are active', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);
                $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'test@example.com']);

                $manager->for($user)->activate(['premium', 'analytics', 'reporting']);

                $evaluations = [
                    $manager->lazy('premium')->for($user),
                    $manager->lazy('analytics')->for($user),
                    $manager->lazy('reporting')->for($user),
                ];

                // Act
                $result = $manager->evaluate($evaluations);

                // Assert
                expect($result->all())->toBeTrue();
            });

            test('all() returns false when any feature is inactive', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);
                $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'test@example.com']);

                $manager->for($user)->activate(['premium', 'analytics']);
                // reporting inactive

                $evaluations = [
                    $manager->lazy('premium')->for($user),
                    $manager->lazy('analytics')->for($user),
                    $manager->lazy('reporting')->for($user),
                ];

                // Act
                $result = $manager->evaluate($evaluations);

                // Assert
                expect($result->all())->toBeFalse();
            });

            test('any() returns true when at least one feature is active', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);
                $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'test@example.com']);

                $manager->for($user)->activate('premium');

                $evaluations = [
                    $manager->lazy('premium')->for($user),
                    $manager->lazy('analytics')->for($user),
                    $manager->lazy('reporting')->for($user),
                ];

                // Act
                $result = $manager->evaluate($evaluations);

                // Assert
                expect($result->any())->toBeTrue();
            });

            test('none() returns true when all features are inactive', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);
                $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'test@example.com']);

                // No features activated

                $evaluations = [
                    $manager->lazy('premium')->for($user),
                    $manager->lazy('analytics')->for($user),
                ];

                // Act
                $result = $manager->evaluate($evaluations);

                // Assert
                expect($result->none())->toBeTrue();
            });
        });

        describe('Facade Integration', function (): void {
            test('Toggl::lazy() works via facade', function (): void {
                // Act
                $conductor = Toggl::lazy('premium');

                // Assert
                expect($conductor)->toBeInstanceOf(LazyEvaluationConductor::class);
            });

            test('Toggl::evaluate() works via facade', function (): void {
                // Arrange
                $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'test@example.com']);
                Toggl::for($user)->activate('premium');

                $evaluations = [
                    Toggl::lazy('premium')->for($user),
                    Toggl::lazy('analytics')->for($user),
                ];

                // Act
                $result = Toggl::evaluate($evaluations);

                // Assert
                expect($result)->toBeInstanceOf(BatchEvaluationResult::class);
                expect($result->countActive())->toBe(1);
            });

            test('full workflow via facade', function (): void {
                // Arrange
                $user1 = BatchTestUser::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
                $user2 = BatchTestUser::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);

                Toggl::for($user1)->activate(['premium', 'analytics']);
                Toggl::for($user2)->activate('reporting');

                // Act
                $result = Toggl::evaluate([
                    Toggl::lazy(TestFeature::Premium)->for($user1),
                    Toggl::lazy(TestFeature::Premium)->for($user2),
                    Toggl::lazy(TestFeature::Analytics)->for($user1),
                    Toggl::lazy(TestFeature::Analytics)->for($user2),
                    Toggl::lazy(TestFeature::Reporting)->for($user1),
                    Toggl::lazy(TestFeature::Reporting)->for($user2),
                ]);

                // Assert
                expect($result->count())->toBe(6);
                expect($result->countActive())->toBe(3);

                // Check per-user results
                expect($result->forContext($user1)->countActive())->toBe(2);
                expect($result->forContext($user2)->countActive())->toBe(1);

                // Check per-feature results
                expect($result->forFeature('premium')->countActive())->toBe(1);
                expect($result->forFeature('analytics')->countActive())->toBe(1);
                expect($result->forFeature('reporting')->countActive())->toBe(1);
            });
        });

        describe('Result Grouping', function (): void {
            test('groupByFeature() organizes results correctly', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);
                $user1 = BatchTestUser::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
                $user2 = BatchTestUser::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);

                $manager->for($user1)->activate('premium');

                $evaluations = [
                    $manager->lazy('premium')->for($user1),
                    $manager->lazy('premium')->for($user2),
                ];

                // Act
                $result = $manager->evaluate($evaluations);
                $grouped = $result->groupByFeature();

                // Assert
                expect($grouped)->toHaveKey('premium');
                expect(count($grouped['premium']))->toBe(2);
            });

            test('groupByContext() organizes results correctly', function (): void {
                // Arrange
                $manager = app(FeatureManager::class);
                $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'test@example.com']);

                $manager->for($user)->activate('premium');

                $evaluations = [
                    $manager->lazy('premium')->for($user),
                    $manager->lazy('analytics')->for($user),
                ];

                // Act
                $result = $manager->evaluate($evaluations);
                $grouped = $result->groupByContext();

                // Assert
                expect(count($grouped))->toBe(1);
                $contextResults = array_values($grouped)[0];
                expect($contextResults)->toHaveKey('premium');
                expect($contextResults)->toHaveKey('analytics');
            });
        });
    });

    describe('Edge Cases', function (): void {
        test('handles empty evaluation array', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);

            // Act
            $result = $manager->evaluate([]);

            // Assert
            expect($result->isEmpty())->toBeTrue();
            expect($result->count())->toBe(0);
            expect($result->all())->toBeFalse();
            expect($result->none())->toBeTrue();
        });

        test('handles single user with no features activated', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'nofeatures@example.com']);

            // No features activated

            $evaluations = [
                $manager->lazy('premium')->for($user),
                $manager->lazy('analytics')->for($user),
            ];

            // Act
            $result = $manager->evaluate($evaluations);

            // Assert
            expect($result->count())->toBe(2);
            expect($result->none())->toBeTrue();
        });

        test('handles duplicate evaluations', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'test@example.com']);
            $manager->for($user)->activate('premium');

            // Same feature-context pair twice
            $evaluations = [
                $manager->lazy('premium')->for($user),
                $manager->lazy('premium')->for($user),
            ];

            // Act
            $result = $manager->evaluate($evaluations);

            // Assert - duplicates get same key, so only 1 entry
            expect($result->count())->toBe(1);
        });

        test('handles feature values (not just booleans)', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $user = BatchTestUser::query()->create(['name' => 'Test', 'email' => 'test@example.com']);

            // Activate with custom value
            $manager->for($user)->activate('theme', 'dark-mode');
            $manager->for($user)->activate('limit', 100);

            $evaluations = [
                $manager->lazy('theme')->for($user),
                $manager->lazy('limit')->for($user),
            ];

            // Act
            $result = $manager->evaluate($evaluations);

            // Assert
            expect($result->all())->toBeTrue(); // both truthy
            $array = $result->toArray();
            expect(array_values($array))->toContain('dark-mode');
            expect(array_values($array))->toContain(100);
        });

        test('handles multiple users efficiently', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $user1 = BatchTestUser::query()->create(['name' => 'User 1', 'email' => 'user1b@example.com']);
            $user2 = BatchTestUser::query()->create(['name' => 'User 2', 'email' => 'user2b@example.com']);

            $manager->for($user1)->activate('premium');

            $evaluations = [
                $manager->lazy('premium')->for($user1),
                $manager->lazy('premium')->for($user2),
            ];

            // Act
            $result = $manager->evaluate($evaluations);

            // Assert
            expect($result->countActive())->toBe(1);
            expect($result->countInactive())->toBe(1);
        });
    });
});
