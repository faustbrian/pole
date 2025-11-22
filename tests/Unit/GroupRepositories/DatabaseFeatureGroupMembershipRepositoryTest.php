<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Database\ModelRegistry;
use Cline\Toggl\GroupRepositories\DatabaseFeatureGroupMembershipRepository;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\User;

/**
 * DatabaseFeatureGroupMembershipRepository test suite.
 *
 * Tests the database-backed feature group membership repository, which persists group
 * memberships using polymorphic relationships. Tests verify database CRUD operations,
 * bulk operations, model handling, and custom table configuration.
 */
describe('DatabaseFeatureGroupMembershipRepository', function (): void {
    /**
     * Set up database table and repository instance before each test.
     */
    beforeEach(function (): void {
        $primaryKeyType = config('toggl.primary_key_type', 'id');
        $morphType = config('toggl.morph_type', 'morph');

        // Run migration if table doesn't exist
        if (!Schema::hasTable('feature_group_memberships')) {
            Schema::create('feature_group_memberships', function ($table) use ($primaryKeyType, $morphType): void {
                match ($primaryKeyType) {
                    'ulid' => $table->ulid('id')->primary(),
                    'uuid' => $table->uuid('id')->primary(),
                    default => $table->id(),
                };

                $table->string('group_name')->index();

                match ($morphType) {
                    'ulidMorph' => $table->ulidMorphs('context'),
                    'uuidMorph' => $table->uuidMorphs('context'),
                    'numericMorph' => $table->numericMorphs('context'),
                    default => $table->morphs('context'),
                };

                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['group_name', 'context_type', 'context_id']);
                $table->index(['group_name', 'context_type', 'context_id']);
            });
        }

        $this->registry = app(ModelRegistry::class);
        $this->repository = new DatabaseFeatureGroupMembershipRepository(
            $this->registry,
        );
        DB::table('feature_group_memberships')->delete();
    });

    describe('Happy Path', function (): void {
        test('can add a context to a group', function (): void {
            // Arrange
            $user = User::factory()->create();
            $context = simpleUserContext($user);

            // Act
            $this->repository->addToGroup('beta-users', $context);

            // Assert
            expect($this->repository->isInGroup('beta-users', $context))->toBeTrue();
        });

        test('can add multiple contexts to a group', function (): void {
            // Arrange
            $users = User::factory()->count(3)->create();
            $contexts = $users->map(fn (User $u): TogglContext => simpleUserContext($u))->all();

            // Act
            $this->repository->addManyToGroup('beta-users', $contexts);

            // Assert
            foreach ($contexts as $context) {
                expect($this->repository->isInGroup('beta-users', $context))->toBeTrue();
            }
        });

        test('can remove a context from a group', function (): void {
            // Arrange
            $user = User::factory()->create();
            $context = simpleUserContext($user);
            $this->repository->addToGroup('beta-users', $context);

            // Act
            $this->repository->removeFromGroup('beta-users', $context);

            // Assert
            expect($this->repository->isInGroup('beta-users', $context))->toBeFalse();
        });

        test('can remove multiple contexts from a group', function (): void {
            // Arrange
            $users = User::factory()->count(3)->create();
            $contexts = $users->map(fn (User $u): TogglContext => simpleUserContext($u))->all();
            $this->repository->addManyToGroup('beta-users', $contexts);

            // Act
            $this->repository->removeManyFromGroup('beta-users', [$contexts[0], $contexts[2]]);

            // Assert
            expect($this->repository->isInGroup('beta-users', $contexts[0]))->toBeFalse();
            expect($this->repository->isInGroup('beta-users', $contexts[1]))->toBeTrue();
            expect($this->repository->isInGroup('beta-users', $contexts[2]))->toBeFalse();
        });

        test('can check if context is in group', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $context1 = simpleUserContext($user1);
            $context2 = simpleUserContext($user2);
            $this->repository->addToGroup('premium', $context1);

            // Act & Assert
            expect($this->repository->isInGroup('premium', $context1))->toBeTrue();
            expect($this->repository->isInGroup('premium', $context2))->toBeFalse();
        });

        test('can get all members of a group', function (): void {
            // Arrange
            $users = User::factory()->count(3)->create();
            $contexts = $users->map(fn (User $u): TogglContext => simpleUserContext($u))->all();
            $this->repository->addManyToGroup('developers', $contexts);

            // Act
            $members = $this->repository->getGroupMembers('developers');

            // Assert
            expect($members)->toHaveCount(3);
            expect($members)->toContain('Tests\Fixtures\User|'.$users[0]->id);
            expect($members)->toContain('Tests\Fixtures\User|'.$users[1]->id);
            expect($members)->toContain('Tests\Fixtures\User|'.$users[2]->id);
        });

        test('can get all groups for a context', function (): void {
            // Arrange
            $user = User::factory()->create();
            $context = simpleUserContext($user);
            $this->repository->addToGroup('beta-users', $context);
            $this->repository->addToGroup('premium', $context);
            $this->repository->addToGroup('developers', $context);

            // Act
            $groups = $this->repository->getGroupsForContext($context);

            // Assert
            expect($groups)->toHaveCount(3);
            expect($groups)->toContain('beta-users');
            expect($groups)->toContain('premium');
            expect($groups)->toContain('developers');
        });

        test('can clear all members from a group', function (): void {
            // Arrange
            $users = User::factory()->count(3)->create();
            $contexts = $users->map(fn (User $u): TogglContext => simpleUserContext($u))->all();
            $this->repository->addManyToGroup('beta-users', $contexts);

            // Act
            $this->repository->clearGroup('beta-users');

            // Assert
            expect($this->repository->getGroupMembers('beta-users'))->toBeEmpty();
        });

        test('can remove context from all groups', function (): void {
            // Arrange
            $user = User::factory()->create();
            $context = simpleUserContext($user);
            $this->repository->addToGroup('beta-users', $context);
            $this->repository->addToGroup('premium', $context);
            $this->repository->addToGroup('developers', $context);

            // Act
            $this->repository->removeContextFromAllGroups($context);

            // Assert
            expect($this->repository->getGroupsForContext($context))->toBeEmpty();
            expect($this->repository->isInGroup('beta-users', $context))->toBeFalse();
            expect($this->repository->isInGroup('premium', $context))->toBeFalse();
            expect($this->repository->isInGroup('developers', $context))->toBeFalse();
        });

        test('uses custom table name', function (): void {
            // Arrange
            $primaryKeyType = config('toggl.primary_key_type', 'id');
            $morphType = config('toggl.morph_type', 'morph');

            Schema::create('custom_memberships', function ($table) use ($primaryKeyType, $morphType): void {
                match ($primaryKeyType) {
                    'ulid' => $table->ulid('id')->primary(),
                    'uuid' => $table->uuid('id')->primary(),
                    default => $table->id(),
                };

                $table->string('group_name')->index();

                match ($morphType) {
                    'ulidMorph' => $table->ulidMorphs('context'),
                    'uuidMorph' => $table->uuidMorphs('context'),
                    'numericMorph' => $table->numericMorphs('context'),
                    default => $table->morphs('context'),
                };

                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['group_name', 'context_type', 'context_id']);
            });

            config(['toggl.table_names.feature_group_memberships' => 'custom_memberships']);

            $repository = new DatabaseFeatureGroupMembershipRepository(
                $this->registry,
            );

            $user = User::factory()->create();
            $context = simpleUserContext($user);

            // Act
            $repository->addToGroup('test', $context);

            // Assert
            expect(DB::table('custom_memberships')->where('group_name', 'test')->exists())->toBeTrue();

            // Cleanup
            Schema::dropIfExists('custom_memberships');
        });

        test('uses custom database connection when configured', function (): void {
            // Arrange
            config(['toggl.default' => 'database']);
            config(['toggl.stores.database.connection' => 'sqlite']);

            $repository = new DatabaseFeatureGroupMembershipRepository(
                $this->registry,
            );

            $user = User::factory()->create();
            $context = simpleUserContext($user);

            // Act
            $repository->addToGroup('beta-users', $context);

            // Assert
            expect($this->repository->isInGroup('beta-users', $context))->toBeTrue();
            expect(DB::table('feature_group_memberships')->where('group_name', 'beta-users')->exists())->toBeTrue();
        });

        test('sets connection on query model when connection is not null', function (): void {
            // Arrange
            config(['toggl.default' => 'database']);
            config(['toggl.stores.database.connection' => 'testing']);

            $repository = new DatabaseFeatureGroupMembershipRepository(
                $this->registry,
            );

            $users = User::factory()->count(3)->create();
            $contexts = $users->map(fn (User $u): TogglContext => simpleUserContext($u))->all();

            // Act - Test all methods that call newQuery()
            $repository->addToGroup('connection-test', $contexts[0]);
            $repository->addManyToGroup('connection-test', [$contexts[1], $contexts[2]]);

            // Assert
            expect($repository->isInGroup('connection-test', $contexts[0]))->toBeTrue();
            expect($repository->getGroupMembers('connection-test'))->toHaveCount(3);
            expect($repository->getGroupsForContext($contexts[0]))->toContain('connection-test');

            // Act - Test remove operations
            $repository->removeFromGroup('connection-test', $contexts[0]);

            // Assert
            expect($repository->isInGroup('connection-test', $contexts[0]))->toBeFalse();
            expect($repository->getGroupMembers('connection-test'))->toHaveCount(2);

            // Act - Test clear and remove from all groups
            $repository->clearGroup('connection-test');

            // Assert
            expect($repository->getGroupMembers('connection-test'))->toBeEmpty();
        });

        test('does not set connection when connection config is null', function (): void {
            // Arrange
            config(['toggl.default' => 'database']);
            config(['toggl.stores.database.connection' => null]);

            $repository = new DatabaseFeatureGroupMembershipRepository(
                $this->registry,
            );

            $user = User::factory()->create();
            $context = simpleUserContext($user);

            // Act
            $repository->addToGroup('null-connection-test', $context);

            // Assert
            expect($repository->isInGroup('null-connection-test', $context))->toBeTrue();
            expect(DB::table('feature_group_memberships')->where('group_name', 'null-connection-test')->exists())->toBeTrue();
        });
    });

    describe('Sad Path', function (): void {
        test('removing context from empty group is safe', function (): void {
            // Arrange
            $user = User::factory()->create();
            $context = simpleUserContext($user);

            // Act & Assert
            expect(fn () => $this->repository->removeFromGroup('nonexistent', $context))
                ->not->toThrow(Exception::class);
        });

        test('removing multiple contexts from empty group is safe', function (): void {
            // Arrange
            $users = User::factory()->count(2)->create();
            $contexts = $users->map(fn (User $u): TogglContext => simpleUserContext($u))->all();

            // Act & Assert
            expect(fn () => $this->repository->removeManyFromGroup('nonexistent', $contexts))
                ->not->toThrow(Exception::class);
        });

        test('clearing empty group is safe', function (): void {
            // Act & Assert
            expect(fn () => $this->repository->clearGroup('nonexistent'))
                ->not->toThrow(Exception::class);
        });

        test('getting members of empty group returns empty array', function (): void {
            // Act
            $members = $this->repository->getGroupMembers('nonexistent');

            // Assert
            expect($members)->toBeEmpty();
        });

        test('getting groups for context not in any group returns empty array', function (): void {
            // Arrange
            $user = User::factory()->create();
            $context = simpleUserContext($user);

            // Act
            $groups = $this->repository->getGroupsForContext($context);

            // Assert
            expect($groups)->toBeEmpty();
        });
    });

    describe('Edge Cases', function (): void {
        test('adding same context twice is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();
            $context = simpleUserContext($user);

            // Act
            $this->repository->addToGroup('beta-users', $context);
            $this->repository->addToGroup('beta-users', $context);

            // Assert
            $members = $this->repository->getGroupMembers('beta-users');
            expect($members)->toHaveCount(1);
        });

        test('removing context not in group is safe', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $context1 = simpleUserContext($user1);
            $context2 = simpleUserContext($user2);
            $this->repository->addToGroup('beta-users', $context1);

            // Act & Assert
            expect(fn () => $this->repository->removeFromGroup('beta-users', $context2))
                ->not->toThrow(Exception::class);
            expect($this->repository->isInGroup('beta-users', $context1))->toBeTrue();
        });

        test('context can be in multiple groups', function (): void {
            // Arrange
            $user = User::factory()->create();
            $context = simpleUserContext($user);

            // Act
            $this->repository->addToGroup('beta-users', $context);
            $this->repository->addToGroup('premium', $context);
            $this->repository->addToGroup('developers', $context);

            // Assert
            expect($this->repository->isInGroup('beta-users', $context))->toBeTrue();
            expect($this->repository->isInGroup('premium', $context))->toBeTrue();
            expect($this->repository->isInGroup('developers', $context))->toBeTrue();
        });

        test('removing from one group does not affect other groups', function (): void {
            // Arrange
            $user = User::factory()->create();
            $context = simpleUserContext($user);
            $this->repository->addToGroup('beta-users', $context);
            $this->repository->addToGroup('premium', $context);

            // Act
            $this->repository->removeFromGroup('beta-users', $context);

            // Assert
            expect($this->repository->isInGroup('beta-users', $context))->toBeFalse();
            expect($this->repository->isInGroup('premium', $context))->toBeTrue();
        });

        test('getGroupMembers handles context_id as string', function (): void {
            // Skip if using typed morphs (fixed-length context_id columns)
            if (in_array(config('toggl.morph_type', 'morph'), ['numericMorph', 'uuidMorph', 'ulidMorph'], true)) {
                $this->markTestSkipped('String context_id only works with morph (varchar) configuration');
            }

            // Arrange - Manually insert a record with string context_id
            $primaryKeyType = config('toggl.primary_key_type', 'id');
            $id = match ($primaryKeyType) {
                'ulid' => (string) Str::ulid(),
                'uuid' => (string) Str::uuid(),
                default => null,
            };

            DB::table('feature_group_memberships')->insert(array_filter([
                'id' => $id,
                'group_name' => 'test-group',
                'context_type' => 'StringContext',
                'context_id' => 'string-identifier',
                'created_at' => now(),
                'updated_at' => now(),
            ], fn ($v): bool => $v !== null));

            // Act
            $members = $this->repository->getGroupMembers('test-group');

            // Assert
            expect($members)->toHaveCount(1);
            // Note: When using typed morphs (ulid/uuid), context_id is fixed-width and may be padded
            expect(mb_trim((string) $members[0]))->toBe('StringContext|string-identifier');
        });
    });

    describe('Type Safety', function (): void {
        test('throws TypeError when adding string context to group', function (): void {
            // Act & Assert
            expect(fn () => $this->repository->addToGroup('beta-users', 'not-a-model'))
                ->toThrow(TypeError::class);
        });

        test('throws TypeError when adding null context to group', function (): void {
            // Act & Assert
            expect(fn () => $this->repository->addToGroup('beta-users', null))
                ->toThrow(TypeError::class);
        });

        test('throws TypeError when checking if string context is in group', function (): void {
            // Act & Assert
            expect(fn () => $this->repository->isInGroup('beta-users', 'not-a-model'))
                ->toThrow(TypeError::class);
        });
    });
});
