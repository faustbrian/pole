<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Database\FeatureSnapshot;
use Cline\Toggl\Database\FeatureSnapshotEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Tests\Fixtures\Organization;
use Tests\Fixtures\User;

uses(RefreshDatabase::class);

describe('FeatureSnapshotEvent', function (): void {
    describe('Happy Paths', function (): void {
        test('can create event with all attributes', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $metadata = ['reason' => 'testing', 'ip_address' => '127.0.0.1'];

            // Act
            $event = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'created',
                'performed_by_type' => User::class,
                'performed_by_id' => $user->getKey(),
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            // Assert
            expect($event->snapshot_id)->toBe($snapshot->getKey())
                ->and($event->event_type)->toBe('created')
                ->and($event->performed_by_type)->toBe(User::class)
                ->and($event->performed_by_id)->toBe($user->getKey())
                ->and($event->metadata)->toBe($metadata)
                ->and($event->created_at)->toBeInstanceOf(Carbon::class);
        });

        test('snapshot relationship returns correct snapshot', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $event = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'restored',
                'created_at' => now(),
            ]);

            // Act
            $result = $event->snapshot;

            // Assert
            expect($result)->toBeInstanceOf(FeatureSnapshot::class)
                ->and($result->getKey())->toBe($snapshot->getKey());
        });

        test('performedBy relationship returns correct user', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $event = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'created',
                'performed_by_type' => User::class,
                'performed_by_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $result = $event->performedBy;

            // Assert
            expect($result)->toBeInstanceOf(User::class)
                ->and($result->getKey())->toBe($user->getKey())
                ->and($result->name)->toBe('John');
        });

        test('performedBy relationship works with different model types', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $org = Organization::query()->create(['name' => 'Acme Inc']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $userEvent = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'created',
                'performed_by_type' => User::class,
                'performed_by_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $orgEvent = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'restored',
                'performed_by_type' => Organization::class,
                'performed_by_id' => $org->getKey(),
                'created_at' => now(),
            ]);

            // Act & Assert
            expect($userEvent->performedBy)->toBeInstanceOf(User::class)
                ->and($userEvent->performedBy->name)->toBe('John')
                ->and($orgEvent->performedBy)->toBeInstanceOf(Organization::class)
                ->and($orgEvent->performedBy->name)->toBe('Acme Inc');
        });

        test('casts metadata to array', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $complexMetadata = [
                'user_agent' => 'Mozilla/5.0',
                'ip_address' => '192.168.1.1',
                'tags' => ['production', 'critical'],
                'nested' => ['key' => 'value'],
            ];

            // Act
            $event = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'deleted',
                'metadata' => $complexMetadata,
                'created_at' => now(),
            ]);

            $event->refresh();

            // Assert - use toEqual instead of toBe since JSONB doesn't preserve key order
            expect($event->metadata)->toEqual($complexMetadata)
                ->and($event->metadata['user_agent'])->toBe('Mozilla/5.0')
                ->and($event->metadata['tags'])->toBe(['production', 'critical'])
                ->and($event->metadata['nested']['key'])->toBe('value');
        });

        test('casts created_at to datetime', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $timestamp = now()->subDays(2);

            // Act
            $event = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'restored',
                'created_at' => $timestamp,
            ]);

            $event->refresh();

            // Assert
            expect($event->created_at)->toBeInstanceOf(Carbon::class)
                ->and($event->created_at->timestamp)->toBe($timestamp->timestamp);
        });
    });

    describe('Edge Cases', function (): void {
        test('handles null performed_by fields', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $event = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'created',
                'performed_by_type' => null,
                'performed_by_id' => null,
                'created_at' => now(),
            ]);

            // Assert
            expect($event->performed_by_type)->toBeNull()
                ->and($event->performed_by_id)->toBeNull()
                ->and($event->performedBy)->toBeNull();
        });

        test('handles null metadata', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $event = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'created',
                'metadata' => null,
                'created_at' => now(),
            ]);

            $event->refresh();

            // Assert
            expect($event->metadata)->toBeNull();
        });

        test('handles empty array metadata', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $event = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'restored',
                'metadata' => [],
                'created_at' => now(),
            ]);

            $event->refresh();

            // Assert
            expect($event->metadata)->toBe([]);
        });

        test('does not auto-timestamp', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $specificTime = Date::create(2_024, 6, 15, 10, 30, 0);

            // Act
            $event = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'deleted',
                'created_at' => $specificTime,
            ]);

            // Assert - created_at should be exactly what we set, not auto-generated
            expect($event->created_at->timestamp)->toBe($specificTime->timestamp)
                ->and($event->updated_at)->toBeNull();
        });

        test('snapshot relationship uses correct foreign key', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $event = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'created',
                'created_at' => now(),
            ]);

            // Act
            $relation = $event->snapshot();

            // Assert
            expect($relation->getForeignKeyName())->toBe('snapshot_id')
                ->and($relation->getRelated())->toBeInstanceOf(FeatureSnapshot::class);
        });

        test('performedBy relationship uses correct morph name', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $snapshot = FeatureSnapshot::query()->create([
                'context_type' => User::class,
                'context_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            $event = FeatureSnapshotEvent::query()->create([
                'snapshot_id' => $snapshot->getKey(),
                'event_type' => 'created',
                'performed_by_type' => User::class,
                'performed_by_id' => $user->getKey(),
                'created_at' => now(),
            ]);

            // Act
            $relation = $event->performedBy();

            // Assert
            expect($relation->getMorphType())->toBe('performed_by_type')
                ->and($relation->getForeignKeyName())->toBe('performed_by_id');
        });
    });
});
