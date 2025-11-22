<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Database\FeatureSnapshot;
use Cline\Toggl\Database\FeatureSnapshotEntry;
use Cline\Toggl\Database\FeatureSnapshotEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Tests\Fixtures\Organization;
use Tests\Fixtures\User;

uses(RefreshDatabase::class);

describe('FeatureSnapshot', function (): void {
    describe('Happy Paths', function (): void {
        test('entries relationship returns snapshot entries', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'feature-1',
                'feature_value' => ['value' => 'test1'],
                'is_active' => true,
                'created_at' => now(),
            ]);

            FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'feature-2',
                'feature_value' => ['value' => 'test2'],
                'is_active' => false,
                'created_at' => now(),
            ]);

            // Act
            $entries = $snapshot->entries;

            // Assert
            expect($entries)->toHaveCount(2)
                ->and($entries[0])->toBeInstanceOf(FeatureSnapshotEntry::class)
                ->and($entries[1])->toBeInstanceOf(FeatureSnapshotEntry::class)
                ->and($entries[0]->feature_name)->toBe('feature-1')
                ->and($entries[1]->feature_name)->toBe('feature-2');
        });

        test('events relationship returns snapshot events', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'created',
                'performed_by_type' => User::class,
                'performed_by_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'restored',
                'performed_by_type' => User::class,
                'performed_by_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $events = $snapshot->events;

            // Assert
            expect($events)->toHaveCount(2)
                ->and($events[0])->toBeInstanceOf(FeatureSnapshotEvent::class)
                ->and($events[1])->toBeInstanceOf(FeatureSnapshotEvent::class)
                ->and($events[0]->event_type)->toBe('created')
                ->and($events[1]->event_type)->toBe('restored');
        });

        test('context relationship returns correct polymorphic model', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $context = $snapshot->context;

            // Assert
            expect($context)->toBeInstanceOf(User::class)
                ->and($context->getKey())->toBe($user->getKey())
                ->and($context->name)->toBe('John');
        });

        test('context relationship works with different model types', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $org = Organization::query()->create(['name' => 'Acme Inc']);

            $userSnapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $orgSnapshot = FeatureSnapshot::query()->create([
                'context_type' => Organization::class,
                'context_id' => $org->getKey(),
                'created_at' => now(),
            ]);

            // Act & Assert
            expect($userSnapshot->context)->toBeInstanceOf(User::class)
                ->and($userSnapshot->context->name)->toBe('John')
                ->and($orgSnapshot->context)->toBeInstanceOf(Organization::class)
                ->and($orgSnapshot->context->name)->toBe('Acme Inc');
        });

        test('createdBy relationship returns correct polymorphic model', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $creator = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_by_type' => User::class,
                'created_by_id' => $creator->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $createdBy = $snapshot->createdBy;

            // Assert
            expect($createdBy)->toBeInstanceOf(User::class)
                ->and($createdBy->getKey())->toBe($creator->getKey())
                ->and($createdBy->name)->toBe('Jane');
        });

        test('restoredBy relationship returns correct polymorphic model', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $restorer = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
                'restored_by_type' => User::class,
                'restored_by_id' => $restorer->getKey(),
                'restored_at' => now(),
            ]);

            // Act
            $restoredBy = $snapshot->restoredBy;

            // Assert
            expect($restoredBy)->toBeInstanceOf(User::class)
                ->and($restoredBy->getKey())->toBe($restorer->getKey())
                ->and($restoredBy->name)->toBe('Bob');
        });

        test('createdBy and restoredBy can be different model types', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $org = Organization::query()->create(['name' => 'Acme Inc']);
            $restorer = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com']);

            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_by_type' => Organization::class,
                'created_by_id' => $org->getKey(),
                'created_at' => now(),
                'restored_by_type' => User::class,
                'restored_by_id' => $restorer->getKey(),
                'restored_at' => now(),
            ]);

            // Act & Assert
            expect($snapshot->createdBy)->toBeInstanceOf(Organization::class)
                ->and($snapshot->createdBy->name)->toBe('Acme Inc')
                ->and($snapshot->restoredBy)->toBeInstanceOf(User::class)
                ->and($snapshot->restoredBy->name)->toBe('Admin');
        });

        test('deleting snapshot cascades to entries and events', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'feature-1',
                'feature_value' => [],
                'is_active' => true,
                'created_at' => now(),
            ]);

            FeatureSnapshotEntry::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'feature_name' => 'feature-2',
                'feature_value' => [],
                'is_active' => true,
                'created_at' => now(),
            ]);

            FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'created',
                'created_at' => now(),
            ]);

            // Act
            $snapshot->delete();

            // Assert
            expect(FeatureSnapshotEntry::query()->where('snapshot_id', $snapshot->getKey())->count())->toBe(0)
                ->and(FeatureSnapshotEvent::query()->where('snapshot_id', $snapshot->getKey())->count())->toBe(0);
        });
    });

    describe('Edge Cases', function (): void {
        test('entries relationship returns empty collection when no entries exist', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $entries = $snapshot->entries;

            // Assert
            expect($entries)->toBeEmpty();
        });

        test('events relationship returns empty collection when no events exist', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $events = $snapshot->events;

            // Assert
            expect($events)->toBeEmpty();
        });

        test('createdBy returns null when not set', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $createdBy = $snapshot->createdBy;

            // Assert
            expect($createdBy)->toBeNull();
        });

        test('restoredBy returns null when not set', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $restoredBy = $snapshot->restoredBy;

            // Assert
            expect($restoredBy)->toBeNull();
        });

        test('deleting snapshot with no entries or events succeeds', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $snapshotId = $snapshot->getKey();

            // Act
            $result = $snapshot->delete();

            // Assert
            expect($result)->toBe(true)
                ->and(FeatureSnapshot::query()->find($snapshotId))->toBeNull();
        });

        test('casts metadata to array', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $metadata = ['key1' => 'value1', 'key2' => ['nested' => 'value2']];

            // Act
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
                'metadata' => $metadata,
            ]);

            $snapshot->refresh();

            // Assert
            expect($snapshot->metadata)->toBe($metadata)
                ->and($snapshot->metadata['key1'])->toBe('value1')
                ->and($snapshot->metadata['key2']['nested'])->toBe('value2');
        });

        test('casts created_at to datetime', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $timestamp = now()->subDays(5);

            // Act
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => $timestamp,
            ]);

            $snapshot->refresh();

            // Assert
            expect($snapshot->created_at)->toBeInstanceOf(Carbon::class)
                ->and($snapshot->created_at->timestamp)->toBe($timestamp->timestamp);
        });

        test('casts restored_at to datetime', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $restoredTimestamp = now()->subHours(2);

            // Act
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
                'restored_at' => $restoredTimestamp,
            ]);

            $snapshot->refresh();

            // Assert
            expect($snapshot->restored_at)->toBeInstanceOf(Carbon::class)
                ->and($snapshot->restored_at->timestamp)->toBe($restoredTimestamp->timestamp);
        });

        test('does not auto-timestamp', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $specificTime = Date::create(2_024, 3, 15, 10, 0, 0);

            // Act
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => $specificTime,
            ]);

            // Assert - created_at should be exactly what we set, not auto-generated
            expect($snapshot->created_at->timestamp)->toBe($specificTime->timestamp)
                ->and($snapshot->updated_at)->toBeNull();
        });
    });
});
