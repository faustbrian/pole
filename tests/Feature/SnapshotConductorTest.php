<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * Snapshot Conductor Test Suite
 *
 * Tests event-driven automatic snapshot creation and restoration.
 */
describe('Snapshot Conductor', function (): void {
    // Helper to create snapshot by activating a feature (triggers event)
    $createSnapshot = function (User $user): string {
        // Get snapshot count before
        $before = count(Toggl::snapshot()->list($user));

        // Activate a feature (triggers snapshot creation via event)
        Toggl::activate('test-feature-'.uniqid())->for($user);

        // Get the newly created snapshot
        $snapshots = Toggl::snapshot()->list($user);
        expect($snapshots)->toHaveCount($before + 1);

        return $snapshots[count($snapshots) - 1]['id'];
    };

    describe('Basic Snapshots', function () use ($createSnapshot): void {
        test('captures current state automatically on feature activation', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['premium', 'analytics']);

            // Act - Activating a feature triggers snapshot creation
            $snapshotId = $createSnapshot($user);

            // Assert
            expect($snapshotId)->toBeString();
            expect($snapshotId)->toStartWith('snapshot_');
        });

        test('restores from snapshot', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['premium', 'analytics']);
            $snapshotId = $createSnapshot($user);

            // Modify state
            Toggl::for($user)->deactivate('premium');
            Toggl::for($user)->activate('export');

            // Act
            Toggl::snapshot()->restore($snapshotId, $user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeFalse();
        });

        test('snapshots include feature values', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('theme', 'dark');
            Toggl::for($user)->activate('plan', 'enterprise');

            // Act
            $snapshotId = $createSnapshot($user);

            // Modify state
            Toggl::for($user)->activate('theme', 'light');
            Toggl::for($user)->activate('plan', 'basic');

            // Restore
            Toggl::snapshot()->restore($snapshotId, $user);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
            expect(Toggl::for($user)->value('plan'))->toBe('enterprise');
        });

        test('snapshot excludes internal features', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');
            Toggl::for($user)->activate('__internal__', 'test');

            // Act
            $snapshotId = $createSnapshot($user);
            $snapshot = Toggl::snapshot()->get($snapshotId, $user);

            // Assert
            expect($snapshot['features'])->toHaveKey('premium');
            expect($snapshot['features'])->not->toHaveKey('__internal__');
        });

        test('creates snapshot with auto-generated label', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $snapshotId = $createSnapshot($user);
            $snapshot = Toggl::snapshot()->get($snapshotId, $user);

            // Assert
            expect($snapshot['label'])->toContain('auto-');
        });
    });

    describe('Partial Restore', function () use ($createSnapshot): void {
        test('restores only specified features', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['premium', 'analytics', 'export']);
            $snapshotId = $createSnapshot($user);

            // Modify all features
            Toggl::for($user)->deactivate(['premium', 'analytics', 'export']);

            // Act - Restore only premium and analytics
            Toggl::snapshot()->restorePartial($snapshotId, $user, ['premium', 'analytics']);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeFalse(); // Not restored
        });

        test('restores feature values for specified features', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('theme', 'dark');
            Toggl::for($user)->activate('plan', 'enterprise');
            $snapshotId = $createSnapshot($user);

            // Modify
            Toggl::for($user)->activate('theme', 'light');
            Toggl::for($user)->activate('plan', 'basic');

            // Act - Restore only theme
            Toggl::snapshot()->restorePartial($snapshotId, $user, ['theme']);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark'); // Restored
            expect(Toggl::for($user)->value('plan'))->toBe('basic'); // Not restored
        });
    });

    describe('Snapshot Management', function () use ($createSnapshot): void {
        test('lists all snapshots for context', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();

            $snapshot1 = $createSnapshot($user);
            $snapshot2 = $createSnapshot($user);

            // Act
            $snapshots = Toggl::snapshot()->list($user);

            // Assert
            expect($snapshots)->toHaveCount(2);
            expect($snapshots[0]['id'])->toBe($snapshot1);
            expect($snapshots[1]['id'])->toBe($snapshot2);
        });

        test('gets specific snapshot', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');
            $snapshotId = $createSnapshot($user);

            // Act
            $snapshot = Toggl::snapshot()->get($snapshotId, $user);

            // Assert
            expect($snapshot)->not->toBeNull();
            expect($snapshot['id'])->toBe($snapshotId);
            expect($snapshot['features'])->toHaveKey('premium');
        });

        test('deletes snapshot', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            $snapshotId = $createSnapshot($user);

            // Act
            Toggl::snapshot()->delete($snapshotId, $user);

            // Assert
            expect(Toggl::snapshot()->get($snapshotId, $user))->toBeNull();
        });

        test('clears all snapshots', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();

            $createSnapshot($user);
            $createSnapshot($user);
            $createSnapshot($user);

            // Act
            Toggl::snapshot()->clearAll($user);

            // Assert
            expect(Toggl::snapshot()->list($user))->toBe([]);
        });
    });

    describe('Snapshot Isolation', function () use ($createSnapshot): void {
        test('snapshots are context-specific', function () use ($createSnapshot): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act - Creating snapshots automatically for each user
            $snapshot1 = $createSnapshot($user1);
            $snapshot2 = $createSnapshot($user2);

            // Assert
            $snapshots1 = Toggl::snapshot()->list($user1);
            $snapshots2 = Toggl::snapshot()->list($user2);

            expect($snapshots1)->toHaveCount(1);
            expect($snapshots2)->toHaveCount(1);
            expect($snapshots1[0]['id'])->not->toBe($snapshots2[0]['id']);
        });

        test('restoring snapshot does not affect other contexts', function () use ($createSnapshot): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            Toggl::for($user1)->activate('premium');
            Toggl::for($user2)->activate('basic');

            $snapshot1 = $createSnapshot($user1);

            // Modify user1
            Toggl::for($user1)->deactivate('premium');

            // Act
            Toggl::snapshot()->restore($snapshot1, $user1);

            // Assert
            expect(Toggl::for($user1)->active('premium'))->toBeTrue();
            expect(Toggl::for($user2)->active('basic'))->toBeTrue(); // Unchanged
            expect(Toggl::for($user2)->active('premium'))->toBeFalse();
        });
    });

    describe('Event History', function () use ($createSnapshot): void {
        test('tracks snapshot creation event', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $snapshotId = $createSnapshot($user);
            $events = Toggl::snapshot()->getEventHistory($snapshotId);

            // Assert - Array driver doesn't support event history without context
            expect($events)->toBeArray();
            // Event history is a limitation of the array driver - skip count assertions
        })->skip('Array snapshot driver does not support event history');

        test('tracks restore events', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');
            $snapshotId = $createSnapshot($user);

            // Act
            Toggl::snapshot()->restore($snapshotId, $user);
            $events = Toggl::snapshot()->getEventHistory($snapshotId);

            // Assert
            expect($events)->toHaveCount(2);
            expect($events[0]['event_type'])->toBe('created');
            expect($events[1]['event_type'])->toBe('restored');
        })->skip('Array snapshot driver does not support event history');

        test('tracks partial restore events', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['premium', 'analytics']);
            $snapshotId = $createSnapshot($user);

            // Act
            Toggl::snapshot()->restorePartial($snapshotId, $user, ['premium']);
            $events = Toggl::snapshot()->getEventHistory($snapshotId);

            // Assert
            expect($events)->toHaveCount(2);
            expect($events[1]['event_type'])->toBe('partial_restore');
            expect($events[1]['features'])->toBe(['premium']);
        })->skip('Array snapshot driver does not support event history');

        test('tracks delete events', function () use ($createSnapshot): void {
            // Arrange
            $user = User::factory()->create();
            $snapshotId = $createSnapshot($user);

            // Act
            Toggl::snapshot()->delete($snapshotId, $user);
            $events = Toggl::snapshot()->getEventHistory($snapshotId);

            // Assert
            $lastEvent = end($events);
            expect($lastEvent['event_type'])->toBe('deleted');
        })->skip('Array snapshot driver does not support event history');
    });
});
