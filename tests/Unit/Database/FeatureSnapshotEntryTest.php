<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Database\FeatureSnapshot;
use Cline\Toggl\Database\FeatureSnapshotEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Tests\Fixtures\User;

uses(RefreshDatabase::class);

describe('FeatureSnapshotEntry', function (): void {
    describe('Happy Paths', function (): void {
        test('can create entry with all attributes', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $featureValue = ['config' => 'value', 'enabled' => true];

            // Act
            $entry = FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'test-feature',
                'feature_value' => $featureValue,
                'is_active' => true,
                'created_at' => now(),
            ]);

            // Assert
            expect($entry->snapshot_id)->toBe($snapshot->getKey())
                ->and($entry->feature_name)->toBe('test-feature')
                ->and($entry->feature_value)->toBe($featureValue)
                ->and($entry->is_active)->toBe(true)
                ->and($entry->created_at)->toBeInstanceOf(Carbon::class);
        });

        test('snapshot relationship returns correct snapshot', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $entry = FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'test-feature',
                'feature_value' => [],
                'is_active' => true,
                'created_at' => now(),
            ]);

            // Act
            $result = $entry->snapshot;

            // Assert
            expect($result)->toBeInstanceOf(FeatureSnapshot::class)
                ->and($result->getKey())->toBe($snapshot->getKey());
        });

        test('casts feature_value to json', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $complexValue = [
                'nested' => ['key' => 'value'],
                'array' => [1, 2, 3],
                'boolean' => true,
            ];

            // Act
            $entry = FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'complex-feature',
                'feature_value' => $complexValue,
                'is_active' => true,
                'created_at' => now(),
            ]);

            $entry->refresh();

            // Assert - use toEqual instead of toBe since JSONB doesn't preserve key order
            expect($entry->feature_value)->toEqual($complexValue)
                ->and($entry->feature_value['nested']['key'])->toBe('value')
                ->and($entry->feature_value['array'])->toBe([1, 2, 3]);
        });

        test('casts is_active to boolean', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $activeEntry = FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'active-feature',
                'feature_value' => [],
                'is_active' => 1,
                'created_at' => now(),
            ]);

            $inactiveEntry = FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'inactive-feature',
                'feature_value' => [],
                'is_active' => 0,
                'created_at' => now(),
            ]);

            $activeEntry->refresh();
            $inactiveEntry->refresh();

            // Assert
            expect($activeEntry->is_active)->toBe(true)
                ->and($activeEntry->is_active)->toBeTrue()
                ->and($inactiveEntry->is_active)->toBe(false)
                ->and($inactiveEntry->is_active)->toBeFalse();
        });

        test('casts created_at to datetime', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $timestamp = now()->subHours(5);

            // Act
            $entry = FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'test-feature',
                'feature_value' => [],
                'is_active' => true,
                'created_at' => $timestamp,
            ]);

            $entry->refresh();

            // Assert
            expect($entry->created_at)->toBeInstanceOf(Carbon::class)
                ->and($entry->created_at->timestamp)->toBe($timestamp->timestamp);
        });
    });

    describe('Edge Cases', function (): void {
        test('handles null feature_value as json', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $entry = FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'null-feature',
                'feature_value' => null,
                'is_active' => false,
                'created_at' => now(),
            ]);

            $entry->refresh();

            // Assert
            expect($entry->feature_value)->toBeNull();
        });

        test('handles empty array feature_value', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $entry = FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'empty-feature',
                'feature_value' => [],
                'is_active' => true,
                'created_at' => now(),
            ]);

            $entry->refresh();

            // Assert
            expect($entry->feature_value)->toBe([]);
        });

        test('does not auto-timestamp', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $specificTime = Date::create(2_024, 1, 1, 12, 0, 0);

            // Act
            $entry = FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'test-feature',
                'feature_value' => [],
                'is_active' => true,
                'created_at' => $specificTime,
            ]);

            // Assert - created_at should be exactly what we set, not auto-generated
            expect($entry->created_at->timestamp)->toBe($specificTime->timestamp)
                ->and($entry->updated_at)->toBeNull();
        });

        test('snapshot relationship uses correct foreign key', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $entry = FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'test-feature',
                'feature_value' => [],
                'is_active' => true,
                'created_at' => now(),
            ]);

            // Act
            $relation = $entry->snapshot();

            // Assert
            expect($relation->getForeignKeyName())->toBe('snapshot_id')
                ->and($relation->getRelated())->toBeInstanceOf(FeatureSnapshot::class);
        });
    });
});
