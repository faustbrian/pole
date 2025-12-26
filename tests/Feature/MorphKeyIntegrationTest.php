<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Database\ModelRegistry;
use Cline\Toggl\Toggl;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Configure to use database driver for these integration tests
    config()->set('toggl.default', 'database');
    config()->set('toggl.stores.database.driver', 'database');
    config()->set('toggl.stores.database.connection');
    config()->set('toggl.stores.database.table', 'features');

    // Drop and recreate features table with morph columns based on config
    $morphType = config('toggl.morph_type', 'string');
    $primaryKeyType = config('toggl.primary_key_type', 'id');

    Schema::dropIfExists('features');
    Schema::create('features', function ($table) use ($morphType, $primaryKeyType): void {
        match ($primaryKeyType) {
            'ulid' => $table->ulid('id')->primary(),
            'uuid' => $table->uuid('id')->primary(),
            default => $table->id(),
        };

        $table->string('name');

        match ($morphType) {
            'ulid' => $table->ulidMorphs('context'),
            'uuid' => $table->uuidMorphs('context'),
            'numeric' => $table->numericMorphs('context'),
            default => $table->morphs('context'),
        };

        $table->text('value');
        $table->string('strategy')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->json('metadata')->nullable();
        $table->json('scope')->nullable();
        $table->timestamps();
        $table->index(['name', 'context_type', 'context_id']);
    });

    // Clear morph map and disable enforcement for these tests
    Relation::morphMap([], merge: false);
    Relation::requireMorphMap(false);

    $this->registry = resolve(ModelRegistry::class);
    $this->registry->reset();
});

afterEach(function (): void {
    $this->registry->reset();
    Relation::morphMap([], merge: false);
});

describe('MorphKeyIntegration', function (): void {
    describe('Happy Paths', function (): void {
        test('uses correct key for context id when activating features with default id', function (): void {
            // Arrange
            $this->registry->morphKeyMap([
                User::class => 'id',
            ]);
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Act
            Toggl::for($user)->activate('new-dashboard');

            // Assert
            $record = DB::table('features')
                ->where('name', 'new-dashboard')
                ->where('context_type', $user->getMorphClass())
                ->first();

            expect($record)->not->toBeNull();
            expect($record->context_id)->toEqual((string) $user->id);
        });

        test('stores context type and id correctly in database', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Act
            Toggl::for($user)->activate('beta-features');

            // Assert
            $record = DB::table('features')
                ->where('name', 'beta-features')
                ->first();

            expect($record->context_type)->toBe($user->getMorphClass());
            expect($record->context_id)->toBe($user->id);
        });

        test('can activate multiple features for same context', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Act
            Toggl::for($user)->activate(['feature-a', 'feature-b', 'feature-c']);

            // Assert
            $records = DB::table('features')
                ->where('context_type', $user->getMorphClass())
                ->where('context_id', $user->id)
                ->get();

            expect($records)->toHaveCount(3);
            expect($records->pluck('name')->toArray())->toMatchArray(['feature-a', 'feature-b', 'feature-c']);
        });

        test('can query features by context type efficiently', function (): void {
            // Arrange
            $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);
            $user3 = User::query()->create(['name' => 'User 3', 'email' => 'user3@example.com']);

            Toggl::for($user1)->activate('premium');
            Toggl::for($user2)->activate('premium');
            Toggl::for($user3)->activate('trial');

            // Act - Query users with premium feature
            $premiumUsers = DB::table('features')
                ->where('name', 'premium')
                ->where('context_type', $user1->getMorphClass())
                ->pluck('context_id');

            // Assert
            expect($premiumUsers)->toHaveCount(2);
            expect($premiumUsers->toArray())->toContain($user1->id, $user2->id);
        });

        test('can deactivate feature and verify database update', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            Toggl::for($user)->activate('temp-feature');

            // Act
            Toggl::for($user)->deactivate('temp-feature');

            // Assert
            $record = DB::table('features')
                ->where('name', 'temp-feature')
                ->where('context_type', $user->getMorphClass())
                ->where('context_id', $user->id)
                ->first();

            expect($record)->not->toBeNull();
            expect(json_decode((string) $record->value))->toBe(false);
            expect(Toggl::for($user)->active('temp-feature'))->toBeFalse();
        });

        test('handles feature values correctly with morph context', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Act
            Toggl::for($user)->activate('theme', 'dark');

            // Assert
            $record = DB::table('features')
                ->where('name', 'theme')
                ->where('context_type', $user->getMorphClass())
                ->where('context_id', $user->id)
                ->first();

            expect($record)->not->toBeNull();
            expect(json_decode((string) $record->value))->toBe('dark');
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
        });

        test('updates existing feature value for same context', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            Toggl::for($user)->activate('theme', 'light');

            // Act
            Toggl::for($user)->activate('theme', 'dark');

            // Assert
            $records = DB::table('features')
                ->where('name', 'theme')
                ->where('context_type', $user->getMorphClass())
                ->where('context_id', $user->id)
                ->get();

            expect($records)->toHaveCount(1);
            expect(json_decode((string) $records->first()->value))->toBe('dark');
        });

        test('different contexts can have different feature values', function (): void {
            // Arrange
            $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);

            // Act
            Toggl::for($user1)->activate('theme', 'light');
            Toggl::for($user2)->activate('theme', 'dark');

            // Assert
            expect(Toggl::for($user1)->value('theme'))->toBe('light');
            expect(Toggl::for($user2)->value('theme'))->toBe('dark');
        });
    });

    describe('Custom Key Mapping', function (): void {
        test('uses mapped key name for context id', function (): void {
            // Skip if using typed morphs (fixed-length context_id columns)
            if (in_array(config('toggl.morph_type', 'string'), ['numeric', 'uuid', 'ulid'], true)) {
                $this->markTestSkipped('Custom string keys only work with morph (varchar) configuration');
            }

            // Arrange
            $this->registry->morphKeyMap([
                User::class => 'name', // Use name as the key instead of id
            ]);
            $user = User::query()->create(['name' => 'CustomKeyUser', 'email' => 'john@example.com']);

            // Act
            Toggl::for($user)->activate('custom-feature');

            // Assert
            $record = DB::table('features')
                ->where('name', 'custom-feature')
                ->where('context_type', $user->getMorphClass())
                ->first();

            expect($record)->not->toBeNull();
            expect($record->context_id)->toBe('CustomKeyUser');
        });

        test('retrieves features using mapped key', function (): void {
            // Skip if using typed morphs (fixed-length context_id columns)
            if (in_array(config('toggl.morph_type', 'string'), ['numeric', 'uuid', 'ulid'], true)) {
                $this->markTestSkipped('Custom string keys only work with morph (varchar) configuration');
            }

            // Arrange
            $this->registry->morphKeyMap([
                User::class => 'name',
            ]);
            $user = User::query()->create(['name' => 'MappedKeyUser', 'email' => 'john@example.com']);
            Toggl::for($user)->activate('name-keyed-feature', 'test-value');

            // Act
            $value = Toggl::for($user)->value('name-keyed-feature');

            // Assert
            expect($value)->toBe('test-value');
        });

        test('can query by custom key efficiently', function (): void {
            // Skip if using typed morphs (fixed-length context_id columns)
            if (in_array(config('toggl.morph_type', 'string'), ['numeric', 'uuid', 'ulid'], true)) {
                $this->markTestSkipped('Custom string keys only work with morph (varchar) configuration');
            }

            // Arrange
            $this->registry->morphKeyMap([
                User::class => 'name',
            ]);
            $user1 = User::query()->create(['name' => 'PremiumUser', 'email' => 'user1@example.com']);
            $user2 = User::query()->create(['name' => 'TrialUser', 'email' => 'user2@example.com']);

            Toggl::for($user1)->activate('premium');
            Toggl::for($user2)->activate('trial');

            // Act
            $premiumRecord = DB::table('features')
                ->where('name', 'premium')
                ->where('context_type', $user1->getMorphClass())
                ->where('context_id', 'PremiumUser')
                ->first();

            // Assert
            expect($premiumRecord)->not->toBeNull();
        });
    });

    describe('Edge Cases', function (): void {
        test('handles id properly in context_id column', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Act
            Toggl::for($user)->activate('test-feature');

            // Assert
            $record = DB::table('features')->where('name', 'test-feature')->first();
            // context_id can be int, uuid, or ulid depending on configuration
            expect($record->context_id)->toBe($user->id);
        });

        test('unique constraint prevents duplicate context activations', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            Toggl::for($user)->activate('unique-feature');

            // Act - Try to activate again (should update, not duplicate)
            Toggl::for($user)->activate('unique-feature');

            // Assert
            $count = DB::table('features')
                ->where('name', 'unique-feature')
                ->where('context_type', $user->getMorphClass())
                ->where('context_id', $user->id)
                ->count();

            expect($count)->toBe(1);
        });

        test('can check feature across multiple contexts simultaneously', function (): void {
            // Arrange
            $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);
            $user3 = User::query()->create(['name' => 'User 3', 'email' => 'user3@example.com']);

            Toggl::for($user1)->activate('feature-x');
            Toggl::for($user2)->activate('feature-x');

            // Act & Assert
            expect(Toggl::for($user1)->active('feature-x'))->toBeTrue();
            expect(Toggl::for($user2)->active('feature-x'))->toBeTrue();
            expect(Toggl::for($user3)->active('feature-x'))->toBeFalse();
        });
    });
});
