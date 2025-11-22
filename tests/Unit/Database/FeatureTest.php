<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Database\Feature;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Organization;
use Tests\Fixtures\User;

uses(RefreshDatabase::class);

/**
 * Feature Model Test Suite
 *
 * Tests the Feature Eloquent model, specifically focusing on:
 * - context() morphTo relationship method
 * - Polymorphic relationship with different model types (User, Organization)
 * - Proper context_type and context_id column linking
 * - Relationship query methods and eager loading
 *
 * These tests ensure 100% coverage of the Feature database model.
 */
describe('Feature Model', function (): void {
    describe('context() Relationship', function (): void {
        describe('Happy Paths', function (): void {
            test('context() method returns MorphTo relationship instance', function (): void {
                // Arrange
                $feature = new Feature();

                // Act
                $relationship = $feature->context();

                // Assert
                expect($relationship)->toBeInstanceOf(MorphTo::class);
            });

            test('context relationship returns correct User model', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'John Doe',
                    'email' => 'john@example.com',
                ]);

                $feature = Feature::query()->create([
                    'name' => 'premium-feature',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'value' => 'enabled',
                ]);

                // Act
                $context = $feature->context;

                // Assert
                expect($context)->toBeInstanceOf(User::class)
                    ->and($context->getKey())->toBe($user->getKey())
                    ->and($context->name)->toBe('John Doe')
                    ->and($context->email)->toBe('john@example.com');
            });

            test('context relationship returns correct Organization model', function (): void {
                // Arrange
                $organization = Organization::query()->create([
                    'name' => 'Acme Corporation',
                ]);

                $feature = Feature::query()->create([
                    'name' => 'enterprise-feature',
                    'context_type' => Organization::class,
                    'context_id' => $organization->getKey(),
                    'value' => 'active',
                ]);

                // Act
                $context = $feature->context;

                // Assert
                expect($context)->toBeInstanceOf(Organization::class)
                    ->and($context->getKey())->toBe($organization->getKey())
                    ->and($context->name)->toBe('Acme Corporation');
            });

            test('multiple features can share same User context', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Jane Smith',
                    'email' => 'jane@example.com',
                ]);

                $feature1 = Feature::query()->create([
                    'name' => 'feature-one',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'value' => 'on',
                ]);

                $feature2 = Feature::query()->create([
                    'name' => 'feature-two',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'value' => 'off',
                ]);

                // Act
                $context1 = $feature1->context;
                $context2 = $feature2->context;

                // Assert
                expect($context1)->toBeInstanceOf(User::class)
                    ->and($context2)->toBeInstanceOf(User::class)
                    ->and($context1->getKey())->toBe($user->getKey())
                    ->and($context2->getKey())->toBe($user->getKey())
                    ->and($context1->email)->toBe($context2->email);
            });

            test('multiple features can share same Organization context', function (): void {
                // Arrange
                $organization = Organization::query()->create([
                    'name' => 'Tech Startup Inc',
                ]);

                $feature1 = Feature::query()->create([
                    'name' => 'api-access',
                    'context_type' => Organization::class,
                    'context_id' => $organization->getKey(),
                    'value' => 'enabled',
                ]);

                $feature2 = Feature::query()->create([
                    'name' => 'premium-support',
                    'context_type' => Organization::class,
                    'context_id' => $organization->getKey(),
                    'value' => 'enabled',
                ]);

                // Act
                $context1 = $feature1->context;
                $context2 = $feature2->context;

                // Assert
                expect($context1)->toBeInstanceOf(Organization::class)
                    ->and($context2)->toBeInstanceOf(Organization::class)
                    ->and($context1->getKey())->toBe($organization->getKey())
                    ->and($context2->getKey())->toBe($organization->getKey())
                    ->and($context1->name)->toBe('Tech Startup Inc');
            });

            test('context relationship can be eager loaded for User', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Bob Wilson',
                    'email' => 'bob@example.com',
                ]);

                Feature::query()->create([
                    'name' => 'feature-a',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'value' => 'yes',
                ]);

                Feature::query()->create([
                    'name' => 'feature-b',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'value' => 'no',
                ]);

                // Act
                $features = Feature::query()->with('context')->get();

                // Assert
                expect($features)->toHaveCount(2)
                    ->and($features[0]->relationLoaded('context'))->toBeTrue()
                    ->and($features[1]->relationLoaded('context'))->toBeTrue()
                    ->and($features[0]->context)->toBeInstanceOf(User::class)
                    ->and($features[1]->context)->toBeInstanceOf(User::class)
                    ->and($features[0]->context->name)->toBe('Bob Wilson');
            });

            test('context relationship can be eager loaded for Organization', function (): void {
                // Arrange
                $org = Organization::query()->create([
                    'name' => 'Enterprise Corp',
                ]);

                Feature::query()->create([
                    'name' => 'sso-enabled',
                    'context_type' => Organization::class,
                    'context_id' => $org->getKey(),
                    'value' => 'true',
                ]);

                Feature::query()->create([
                    'name' => 'audit-logs',
                    'context_type' => Organization::class,
                    'context_id' => $org->getKey(),
                    'value' => 'true',
                ]);

                // Act
                $features = Feature::query()->with('context')->get();

                // Assert
                expect($features)->toHaveCount(2)
                    ->and($features[0]->relationLoaded('context'))->toBeTrue()
                    ->and($features[1]->relationLoaded('context'))->toBeTrue()
                    ->and($features[0]->context)->toBeInstanceOf(Organization::class)
                    ->and($features[1]->context)->toBeInstanceOf(Organization::class)
                    ->and($features[0]->context->name)->toBe('Enterprise Corp');
            });

            test('context relationship handles mixed model types in collection', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Alice Cooper',
                    'email' => 'alice@example.com',
                ]);

                $organization = Organization::query()->create([
                    'name' => 'Global Industries',
                ]);

                Feature::query()->create([
                    'name' => 'user-feature',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'value' => 'active',
                ]);

                Feature::query()->create([
                    'name' => 'org-feature',
                    'context_type' => Organization::class,
                    'context_id' => $organization->getKey(),
                    'value' => 'active',
                ]);

                // Act
                $features = Feature::query()->with('context')->get();

                // Assert
                expect($features)->toHaveCount(2)
                    ->and($features[0]->context)->toBeInstanceOf(User::class)
                    ->and($features[1]->context)->toBeInstanceOf(Organization::class)
                    ->and($features[0]->context->name)->toBe('Alice Cooper')
                    ->and($features[1]->context->name)->toBe('Global Industries');
            });

            test('context relationship correctly resolves context_type and context_id columns', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                ]);

                $feature = Feature::query()->create([
                    'name' => 'test-feature',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'value' => 'enabled',
                ]);

                // Act
                $context = $feature->context;

                // Assert - Verify polymorphic columns are properly linked
                expect($feature->context_type)->toBe(User::class)
                    ->and($feature->context_id)->toBe($user->getKey())
                    ->and($context->getKey())->toBe($user->getKey())
                    ->and($context)->toBeInstanceOf(User::class);
            });

            test('can query features by context relationship using whereHasMorph', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Query Test User',
                    'email' => 'query@example.com',
                ]);

                $org = Organization::query()->create([
                    'name' => 'Query Test Org',
                ]);

                Feature::query()->create([
                    'name' => 'user-specific',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'value' => 'on',
                ]);

                Feature::query()->create([
                    'name' => 'org-specific',
                    'context_type' => Organization::class,
                    'context_id' => $org->getKey(),
                    'value' => 'on',
                ]);

                // Act
                $userFeatures = Feature::query()
                    ->whereHasMorph('context', User::class)
                    ->get();

                $orgFeatures = Feature::query()
                    ->whereHasMorph('context', Organization::class)
                    ->get();

                // Assert
                expect($userFeatures)->toHaveCount(1)
                    ->and($userFeatures[0]->name)->toBe('user-specific')
                    ->and($orgFeatures)->toHaveCount(1)
                    ->and($orgFeatures[0]->name)->toBe('org-specific');
            });
        });

        describe('Edge Cases', function (): void {
            test('context relationship handles deleted context model gracefully', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Temporary User',
                    'email' => 'temp@example.com',
                ]);

                $feature = Feature::query()->create([
                    'name' => 'orphaned-feature',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'value' => 'enabled',
                ]);

                // Act - Delete the user
                $user->delete();
                $feature->refresh();
                $context = $feature->context;

                // Assert
                expect($context)->toBeNull()
                    ->and($feature->context_type)->toBe(User::class)
                    ->and($feature->context_id)->not->toBeNull();
            });

            test('context relationship handles non-existent context_id gracefully', function (): void {
                // Arrange - Create feature with non-existent context_id
                $nonExistentId = match (config('toggl.primary_key_type')) {
                    'ulid' => '01JDABCDEFGHIJKLMNOPQRSTUV', // Non-existent ULID
                    'uuid' => '99999999-9999-9999-9999-999999999999', // Non-existent UUID
                    default => 99_999, // Non-existent numeric ID
                };

                $feature = Feature::query()->create([
                    'name' => 'invalid-context',
                    'context_type' => User::class,
                    'context_id' => $nonExistentId,
                    'value' => 'test',
                ]);

                // Act
                $context = $feature->context;

                // Assert
                expect($context)->toBeNull()
                    ->and($feature->context_type)->toBe(User::class)
                    ->and($feature->context_id)->toBe($nonExistentId);
            });

            test('context relationship can be refreshed after context is deleted and recreated', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Original User',
                    'email' => 'original@example.com',
                ]);

                $feature = Feature::query()->create([
                    'name' => 'dynamic-feature',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'value' => 'active',
                ]);

                $originalUserId = $user->getKey();

                // Act - Delete and recreate user with same ID (in test scenario)
                $user->delete();
                $feature->refresh();
                $contextAfterDelete = $feature->context;

                $newUser = User::query()->create([
                    'id' => $originalUserId,
                    'name' => 'New User',
                    'email' => 'new@example.com',
                ]);

                $feature->refresh();
                $feature->load('context');

                $contextAfterRecreate = $feature->context;

                // Assert
                expect($contextAfterDelete)->toBeNull()
                    ->and($contextAfterRecreate)->toBeInstanceOf(User::class)
                    ->and($contextAfterRecreate->name)->toBe('New User');
            });

            test('features with same name can have different contexts', function (): void {
                // Arrange
                $user1 = User::query()->create([
                    'name' => 'User One',
                    'email' => 'user1@example.com',
                ]);

                $user2 = User::query()->create([
                    'name' => 'User Two',
                    'email' => 'user2@example.com',
                ]);

                $feature1 = Feature::query()->create([
                    'name' => 'shared-feature',
                    'context_type' => User::class,
                    'context_id' => $user1->getKey(),
                    'value' => 'variant-a',
                ]);

                $feature2 = Feature::query()->create([
                    'name' => 'shared-feature',
                    'context_type' => User::class,
                    'context_id' => $user2->getKey(),
                    'value' => 'variant-b',
                ]);

                // Act
                $context1 = $feature1->context;
                $context2 = $feature2->context;

                // Assert
                expect($context1)->toBeInstanceOf(User::class)
                    ->and($context2)->toBeInstanceOf(User::class)
                    ->and($context1->getKey())->not->toBe($context2->getKey())
                    ->and($context1->name)->toBe('User One')
                    ->and($context2->name)->toBe('User Two')
                    ->and($feature1->value)->toBe('variant-a')
                    ->and($feature2->value)->toBe('variant-b');
            });

            test('context relationship works with different primary key types', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Key Test User',
                    'email' => 'keytest@example.com',
                ]);

                $feature = Feature::query()->create([
                    'name' => 'key-type-test',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'value' => 'test',
                ]);

                // Act
                $context = $feature->context;

                // Assert - Works regardless of ID, ULID, or UUID
                expect($context)->toBeInstanceOf(User::class)
                    ->and($context->getKey())->toBe($user->getKey())
                    ->and($feature->context_id)->toBe($user->getKey());
            });
        });
    });
});
