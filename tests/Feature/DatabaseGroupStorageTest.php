<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\FeatureManager;
use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database Group Storage Test Suite
 *
 * Tests database-backed feature group storage including CRUD operations,
 * persistence across requests, fluent API usage, and integration with
 * feature activation. Verifies that groups stored in the database behave
 * correctly alongside config-based groups and maintain data integrity.
 */
describe('Database Group Storage', function (): void {
    beforeEach(function (): void {
        config(['toggl.group_storage' => 'database']);

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

        DB::table('feature_groups')->delete();
    });

    describe('Happy Path', function (): void {
        test('can create group using fluent API', function (): void {
            // Act
            Toggl::groups()
                ->create('experimental')
                ->with('new-ui', 'beta-api')
                ->save();

            // Assert
            expect(DB::table('feature_groups')->where('name', 'experimental')->exists())->toBeTrue();
            expect(Toggl::getGroup('experimental'))->toBe(['new-ui', 'beta-api']);
        });

        test('can define group directly', function (): void {
            // Act
            Toggl::groups()->define('premium', ['feature-a', 'feature-b']);

            // Assert
            expect(Toggl::getGroup('premium'))->toBe(['feature-a', 'feature-b']);
        });

        test('can update existing group', function (): void {
            // Arrange
            Toggl::groups()->define('test', ['feat1']);

            // Act
            Toggl::groups()->update('test', ['feat1', 'feat2']);

            // Assert
            expect(Toggl::getGroup('test'))->toBe(['feat1', 'feat2']);
        });

        test('can delete group', function (): void {
            // Arrange
            Toggl::groups()->define('temp', ['feat1']);

            // Act
            Toggl::groups()->delete('temp');

            // Assert
            expect(Toggl::groups()->exists('temp'))->toBeFalse();
        });

        test('can add features to group', function (): void {
            // Arrange
            Toggl::groups()->define('additive', ['feat1']);

            // Act
            Toggl::groups()->add('additive', ['feat2', 'feat3']);

            // Assert
            expect(Toggl::getGroup('additive'))->toBe(['feat1', 'feat2', 'feat3']);
        });

        test('can remove features from group', function (): void {
            // Arrange
            Toggl::groups()->define('subtractive', ['feat1', 'feat2', 'feat3']);

            // Act
            Toggl::groups()->remove('subtractive', ['feat2']);

            // Assert
            expect(Toggl::getGroup('subtractive'))->toBe(['feat1', 'feat3']);
        });

        test('groups persist across requests', function (): void {
            // Arrange
            Toggl::groups()->define('persistent', ['feat1', 'feat2']);

            // Act - simulate new request by creating new manager
            $newManager = resolve(FeatureManager::class);

            // Assert
            expect($newManager->getGroup('persistent'))->toBe(['feat1', 'feat2']);
        });

        test('can get all groups from database', function (): void {
            // Arrange
            Toggl::groups()->define('group1', ['feat1']);
            Toggl::groups()->define('group2', ['feat2']);

            // Act
            $all = Toggl::groups()->all();

            // Assert
            expect($all)->toHaveKey('group1');
            expect($all)->toHaveKey('group2');
            expect($all['group1'])->toBe(['feat1']);
            expect($all['group2'])->toBe(['feat2']);
        });
    });

    describe('Sad Path', function (): void {
        test('throws exception when getting non-existent group', function (): void {
            // Act & Assert
            expect(fn () => Toggl::getGroup('nonexistent'))
                ->toThrow(InvalidArgumentException::class, 'Feature group [nonexistent] is not defined.');
        });

        test('throws exception when updating non-existent group', function (): void {
            // Act & Assert
            expect(fn () => Toggl::groups()->update('nonexistent', ['feat']))
                ->toThrow(InvalidArgumentException::class, 'Feature group [nonexistent] does not exist.');
        });

        test('throws exception when adding features to non-existent group', function (): void {
            // Act & Assert
            expect(fn () => Toggl::groups()->add('nonexistent', ['feat']))
                ->toThrow(InvalidArgumentException::class, 'Feature group [nonexistent] is not defined.');
        });

        test('throws exception when removing features from non-existent group', function (): void {
            // Act & Assert
            expect(fn () => Toggl::groups()->remove('nonexistent', ['feat']))
                ->toThrow(InvalidArgumentException::class, 'Feature group [nonexistent] is not defined.');
        });
    });

    describe('Edge Cases', function (): void {
        test('adding duplicate features removes duplicates', function (): void {
            // Arrange
            Toggl::groups()->define('dedup', ['feat1']);

            // Act
            Toggl::groups()->add('dedup', ['feat1', 'feat2', 'feat1']);

            // Assert
            $features = Toggl::getGroup('dedup');
            expect($features)->toBe(['feat1', 'feat2']);
        });

        test('removing non-existent features is safe', function (): void {
            // Arrange
            Toggl::groups()->define('safe', ['feat1', 'feat2']);

            // Act
            Toggl::groups()->remove('safe', ['feat3']);

            // Assert
            expect(Toggl::getGroup('safe'))->toBe(['feat1', 'feat2']);
        });

        test('can handle empty feature arrays', function (): void {
            // Act
            Toggl::groups()->define('empty', []);

            // Assert
            expect(Toggl::getGroup('empty'))->toBe([]);
        });

        test('update or insert behavior on duplicate define', function (): void {
            // Arrange
            Toggl::groups()->define('override', ['feat1']);

            // Act
            Toggl::groups()->define('override', ['feat2']);

            // Assert
            expect(Toggl::getGroup('override'))->toBe(['feat2']);
        });
    });

    describe('Integration', function (): void {
        test('can activate all features in database-backed group', function (): void {
            // Arrange
            Toggl::groups()->define('activatable', ['feat1', 'feat2']);
            Toggl::define('feat1', false);
            Toggl::define('feat2', false);

            // Act
            Toggl::activateGroup('activatable');

            // Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('feat1'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active('feat2'))->toBeTrue();
        });

        test('can check if all features in database-backed group are active', function (): void {
            // Arrange
            Toggl::groups()->define('checkable', ['feat1', 'feat2']);
            Toggl::define('feat1', true);
            Toggl::define('feat2', true);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->activeInGroup('checkable'))->toBeTrue();
        });

        test('mixed storage works - config and database groups coexist', function (): void {
            // Arrange
            config(['toggl.groups.config-group' => ['features' => ['feat1']]]);
            Toggl::loadGroupsFromConfig();
            Toggl::groups()->define('db-group', ['feat2']);

            // Act
            $all = Toggl::allGroups();

            // Assert
            expect($all)->toHaveKey('config-group');
            expect($all)->toHaveKey('db-group');
        });
    });
});
