<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\ContextResolver;
use Cline\Toggl\Toggl;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Configure database driver
    config()->set('toggl.default', 'database');
    config()->set('toggl.stores.database.driver', 'database');
    config()->set('toggl.stores.database.connection'); // Use default test connection
    config()->set('toggl.stores.database.table', 'features');

    // Create features table
    Schema::dropIfExists('features');
    Schema::create('features', function ($table): void {
        // Respect primary_key_type config for primary key
        $primaryKeyType = config('toggl.primary_key_type', 'id');
        match ($primaryKeyType) {
            'ulid' => $table->ulid('id')->primary(),
            'uuid' => $table->uuid('id')->primary(),
            default => $table->id(),
        };

        $table->string('name');

        // Respect primary_key_type config for morph columns
        $table->string('context_type');
        match ($primaryKeyType) {
            'ulid' => $table->ulid('context_id'),
            'uuid' => $table->uuid('context_id'),
            default => $table->unsignedBigInteger('context_id'),
        };

        $table->text('value');
        $table->string('strategy')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->json('metadata')->nullable();
        $table->json('scope')->nullable();
        $table->timestamps();
        $table->index(['name', 'context_type', 'context_id']);
    });
});

afterEach(function (): void {
    // Clear morph map after each test
    Relation::morphMap([], merge: false);
});

describe('enforceMorphMap Compatibility', function (): void {
    test('respects Laravel enforceMorphMap when storing context_type', function (): void {
        // Arrange - enforce morph map like production apps do
        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

        // Act
        Toggl::for($user)->activate('new-feature');

        // Assert - context_type should be 'user' not 'Tests\Fixtures\User'
        $record = DB::table('features')
            ->where('name', 'new-feature')
            ->where('context_id', $user->id)
            ->first();

        expect($record)->not->toBeNull()
            ->and($record->context_type)->toBe('user')
            ->and($record->context_type)->not->toBe(User::class);
    });

    test('ContextResolver uses getMorphClass for plain models', function (): void {
        // Arrange
        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

        // Act
        $context = ContextResolver::resolve($user);

        // Assert - type should use morph map alias
        expect($context->type)->toBe('user')
            ->and($context->type)->not->toBe(User::class);
    });

    test('retrieves features correctly with morph map aliases', function (): void {
        // Arrange
        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        $user = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
        Toggl::for($user)->activate('test-feature');

        // Act - retrieve using the same user instance
        $isActive = Toggl::for($user)->active('test-feature');

        // Assert
        expect($isActive)->toBeTrue();
    });

    test('works without enforceMorphMap for backward compatibility', function (): void {
        // Arrange - explicitly clear any morph map
        Relation::morphMap([], merge: false);
        Relation::requireMorphMap(false);

        $user = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.com']);

        // Act
        Toggl::for($user)->activate('legacy-feature');

        // Assert - should use full class name when no morph map
        $record = DB::table('features')
            ->where('name', 'legacy-feature')
            ->where('context_id', $user->id)
            ->first();

        expect($record)->not->toBeNull()
            ->and($record->context_type)->toBe($user->getMorphClass());
    });

    test('migrator respects getMorphClass when deserializing contexts', function (): void {
        // Arrange
        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        $user = User::query()->create(['name' => 'Migrated', 'email' => 'migrated@example.com']);

        // Simulate what the migrator does
        $context = ContextResolver::resolve($user);

        // Generate ID based on primary_key_type config
        $primaryKeyType = config('toggl.primary_key_type', 'id');
        $id = match ($primaryKeyType) {
            'ulid' => (string) Str::ulid(),
            'uuid' => (string) Str::uuid(),
            default => null,
        };

        // Act - manually insert as migrator would
        DB::table('features')->insert(array_filter([
            'id' => $id,
            'name' => 'migrated-feature',
            'context_type' => $context->type,
            'context_id' => $context->id,
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($v): bool => $v !== null));

        // Assert
        $record = DB::table('features')
            ->where('name', 'migrated-feature')
            ->first();

        expect($record->context_type)->toBe('user')
            ->and($record->context_type)->not->toBe(User::class);
    });
});
