<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\GroupRepositories\DatabaseGroupRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DatabaseGroupRepository test suite.
 *
 * Tests the database-backed group repository, which persists feature flag groups
 * in a database table using JSON columns for features and metadata storage. The
 * repository provides persistent group management across requests and supports
 * custom table names. Tests verify database CRUD operations, JSON serialization,
 * upsert behavior on redefinition, timestamp tracking, and custom table configuration.
 */
describe('DatabaseGroupRepository', function (): void {
    /**
     * Set up database table and repository instance before each test.
     *
     * Creates the feature_groups table if it doesn't exist, initializes the
     * repository with the database connection, and clears any existing test
     * data to ensure test isolation.
     */
    beforeEach(function (): void {
        $primaryKeyType = config('toggl.primary_key_type', 'id');

        // Run migration if table doesn't exist
        if (!Schema::hasTable('feature_groups')) {
            Schema::create('feature_groups', function ($table) use ($primaryKeyType): void {
                match ($primaryKeyType) {
                    'ulid' => $table->ulid('id')->primary(),
                    'uuid' => $table->uuid('id')->primary(),
                    default => $table->id(),
                };

                $table->string('name')->unique();
                $table->json('features');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index('name');
            });
        }

        $this->repository = new DatabaseGroupRepository();
        DB::table('feature_groups')->delete();
    });

    describe('Happy Path', function (): void {
        test('can define and retrieve a group', function (): void {
            // Act
            $this->repository->define('test', ['feat1', 'feat2']);

            // Assert
            expect($this->repository->get('test'))->toBe(['feat1', 'feat2']);
        });

        test('can check if group exists', function (): void {
            // Arrange
            $this->repository->define('test', ['feat1']);

            // Act & Assert
            expect($this->repository->exists('test'))->toBeTrue();
            expect($this->repository->exists('nonexistent'))->toBeFalse();
        });

        test('can get all groups', function (): void {
            // Arrange
            $this->repository->define('group1', ['feat1']);
            $this->repository->define('group2', ['feat2']);

            // Act
            $all = $this->repository->all();

            // Assert
            expect($all)->toHaveKey('group1');
            expect($all)->toHaveKey('group2');
            expect($all['group1'])->toBe(['feat1']);
            expect($all['group2'])->toBe(['feat2']);
        });

        test('can delete a group', function (): void {
            // Arrange
            $this->repository->define('temp', ['feat1']);

            // Act
            $this->repository->delete('temp');

            // Assert
            expect($this->repository->exists('temp'))->toBeFalse();
        });

        test('can update group features', function (): void {
            // Arrange
            $this->repository->define('test', ['feat1']);

            // Act
            $this->repository->update('test', ['feat2', 'feat3']);

            // Assert
            expect($this->repository->get('test'))->toBe(['feat2', 'feat3']);
        });

        test('can add features to group', function (): void {
            // Arrange
            $this->repository->define('test', ['feat1']);

            // Act
            $this->repository->addFeatures('test', ['feat2', 'feat3']);

            // Assert
            expect($this->repository->get('test'))->toBe(['feat1', 'feat2', 'feat3']);
        });

        test('can remove features from group', function (): void {
            // Arrange
            $this->repository->define('test', ['feat1', 'feat2', 'feat3']);

            // Act
            $this->repository->removeFeatures('test', ['feat2']);

            // Assert
            expect($this->repository->get('test'))->toBe(['feat1', 'feat3']);
        });

        test('stores metadata in database', function (): void {
            // Act
            $this->repository->define('test', ['feat1'], ['description' => 'Test group']);

            // Assert
            $record = DB::table('feature_groups')->where('name', 'test')->first();
            expect($record)->not->toBeNull();
            expect(json_decode((string) $record->metadata, true))->toBe(['description' => 'Test group']);
        });

        test('uses custom table name', function (): void {
            // Arrange
            $primaryKeyType = config('toggl.primary_key_type', 'id');

            Schema::create('custom_groups', function ($table) use ($primaryKeyType): void {
                match ($primaryKeyType) {
                    'ulid' => $table->ulid('id')->primary(),
                    'uuid' => $table->uuid('id')->primary(),
                    default => $table->id(),
                };

                $table->string('name')->unique();
                $table->json('features');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });

            config(['toggl.table_names.feature_groups' => 'custom_groups']);

            $repository = new DatabaseGroupRepository();

            // Act
            $repository->define('test', ['feat1']);

            // Assert
            expect(DB::table('custom_groups')->where('name', 'test')->exists())->toBeTrue();

            // Cleanup
            Schema::dropIfExists('custom_groups');
        });
    });

    describe('Sad Path', function (): void {
        test('throws exception when getting non-existent group', function (): void {
            // Act & Assert
            expect(fn () => $this->repository->get('nonexistent'))
                ->toThrow(InvalidArgumentException::class, 'Feature group [nonexistent] is not defined.');
        });

        test('throws exception when updating non-existent group', function (): void {
            // Act & Assert
            expect(fn () => $this->repository->update('nonexistent', ['feat']))
                ->toThrow(InvalidArgumentException::class, 'Feature group [nonexistent] does not exist.');
        });

        test('throws exception when adding to non-existent group', function (): void {
            // Act & Assert
            expect(fn () => $this->repository->addFeatures('nonexistent', ['feat']))
                ->toThrow(InvalidArgumentException::class, 'Feature group [nonexistent] is not defined.');
        });

        test('throws exception when removing from non-existent group', function (): void {
            // Act & Assert
            expect(fn () => $this->repository->removeFeatures('nonexistent', ['feat']))
                ->toThrow(InvalidArgumentException::class, 'Feature group [nonexistent] is not defined.');
        });
    });

    describe('Edge Cases', function (): void {
        test('adding duplicate features removes duplicates', function (): void {
            // Arrange
            $this->repository->define('test', ['feat1']);

            // Act
            $this->repository->addFeatures('test', ['feat1', 'feat2', 'feat1']);

            // Assert
            $features = $this->repository->get('test');
            expect($features)->toHaveCount(2);
            expect($features)->toContain('feat1');
            expect($features)->toContain('feat2');
        });

        test('removing non-existent features is safe', function (): void {
            // Arrange
            $this->repository->define('test', ['feat1', 'feat2']);

            // Act
            $this->repository->removeFeatures('test', ['feat3']);

            // Assert
            expect($this->repository->get('test'))->toBe(['feat1', 'feat2']);
        });

        test('can handle empty feature arrays', function (): void {
            // Act
            $this->repository->define('empty', []);

            // Assert
            expect($this->repository->get('empty'))->toBe([]);
        });

        test('redefining group updates existing record', function (): void {
            // Arrange
            $this->repository->define('test', ['feat1']);
            $firstId = DB::table('feature_groups')->where('name', 'test')->value('id');

            // Act
            $this->repository->define('test', ['feat2']);

            // Assert
            $secondId = DB::table('feature_groups')->where('name', 'test')->value('id');
            expect($secondId)->toBe($firstId); // Same ID, not a new record
            expect($this->repository->get('test'))->toBe(['feat2']);
        });

        test('delete on non-existent group is safe', function (): void {
            // Act & Assert
            expect(fn () => $this->repository->delete('nonexistent'))->not->toThrow(Exception::class);
        });

        test('timestamps are set on create', function (): void {
            // Act
            $this->repository->define('test', ['feat1']);

            // Assert
            $record = DB::table('feature_groups')->where('name', 'test')->first();
            expect($record->created_at)->not->toBeNull();
            expect($record->updated_at)->not->toBeNull();
        });

        test('sets connection on model when custom connection configured', function (): void {
            // Arrange
            $defaultStore = config('toggl.default', 'database');
            // Use current connection (works in Docker with pgsql or locally with sqlite)
            $customConnection = config('database.default');

            // Set custom connection for the default store - this triggers lines 250-251
            config([sprintf('toggl.stores.%s.connection', $defaultStore) => $customConnection]);

            // Create new repository to pick up config changes
            $repository = new DatabaseGroupRepository();

            // Act - Each method call triggers newQuery() which executes lines 249-252
            $repository->define('test', ['feat1']);

            // Assert
            // Verify the group was created successfully with custom connection
            expect($repository->get('test'))->toBe(['feat1']);
            expect($repository->exists('test'))->toBeTrue();

            // Verify all repository methods work with custom connection
            // Each of these method calls goes through newQuery() with the connection set
            $repository->update('test', ['feat2', 'feat3']);
            expect($repository->get('test'))->toBe(['feat2', 'feat3']);

            $repository->addFeatures('test', ['feat4']);
            expect($repository->get('test'))->toBe(['feat2', 'feat3', 'feat4']);

            $repository->removeFeatures('test', ['feat3']);
            expect($repository->get('test'))->toBe(['feat2', 'feat4']);

            $all = $repository->all();
            expect($all)->toHaveKey('test');

            $repository->delete('test');
            expect($repository->exists('test'))->toBeFalse();
        });

        test('uses default connection when connection config is null', function (): void {
            // Arrange
            $defaultStore = config('toggl.default', 'database');

            // Explicitly set connection to null for the default store
            config([sprintf('toggl.stores.%s.connection', $defaultStore) => null]);

            // Create new repository to pick up config changes
            $repository = new DatabaseGroupRepository();

            // Act
            $repository->define('test', ['feat1']);

            // Assert
            // Verify the group was created successfully with default connection
            expect($repository->get('test'))->toBe(['feat1']);
            expect($repository->exists('test'))->toBeTrue();
        });

        test('handles different default stores with custom connections', function (): void {
            // Arrange
            $originalDefault = config('toggl.default');

            // Set a different default store
            config(['toggl.default' => 'array']);
            // Use current connection (works in Docker with pgsql or locally with sqlite)
            config(['toggl.stores.array.connection' => config('database.default')]);

            // Create repository - should use 'array' store with 'sqlite' connection
            $repository = new DatabaseGroupRepository();

            // Act
            $repository->define('test', ['feat1', 'feat2']);

            // Assert
            expect($repository->get('test'))->toBe(['feat1', 'feat2']);
            expect($repository->exists('test'))->toBeTrue();

            // Verify CRUD operations work correctly
            $repository->update('test', ['feat3']);
            expect($repository->get('test'))->toBe(['feat3']);

            // Restore original config
            config(['toggl.default' => $originalDefault]);
        });

        test('connection setting is evaluated per query', function (): void {
            // Arrange
            $defaultStore = config('toggl.default', 'database');
            // Use current connection (works in Docker with pgsql or locally with sqlite)
            config([sprintf('toggl.stores.%s.connection', $defaultStore) => config('database.default')]);

            $repository = new DatabaseGroupRepository();

            // Act - Multiple operations to ensure connection is set for each query
            $repository->define('group1', ['feat1']);
            $repository->define('group2', ['feat2']);

            // Assert
            expect($repository->exists('group1'))->toBeTrue();
            expect($repository->exists('group2'))->toBeTrue();

            $all = $repository->all();
            expect($all)->toHaveCount(2);
            expect($all)->toHaveKey('group1');
            expect($all)->toHaveKey('group2');

            // Cleanup
            $repository->delete('group1');
            $repository->delete('group2');

            expect($repository->exists('group1'))->toBeFalse();
            expect($repository->exists('group2'))->toBeFalse();
        });
    });
});
