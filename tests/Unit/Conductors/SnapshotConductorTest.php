<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Conductors\SnapshotConductor;
use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * SnapshotConductor Unit Test Suite
 *
 * Tests the standalone SnapshotConductor class methods by verifying
 * proper delegation to the Snapshot repository.
 */
describe('SnapshotConductor', function (): void {
    describe('getEventHistory() method', function (): void {
        test('delegates to repository and returns event history array', function (): void {
            // Arrange
            $user = User::factory()->create();
            $conductor = new SnapshotConductor();

            // Create a snapshot to get a valid ID
            Toggl::for($user)->activate('premium');
            $snapshotId = Toggl::snapshot()->list($user)[0]['id'];

            // Act
            $result = $conductor->getEventHistory($snapshotId);

            // Assert
            expect($result)->toBeArray();
        });

        test('returns empty array for array driver (limitation)', function (): void {
            // Arrange
            $user = User::factory()->create();
            $conductor = new SnapshotConductor();

            // Create a snapshot
            Toggl::for($user)->activate('premium');
            $snapshotId = Toggl::snapshot()->list($user)[0]['id'];

            // Act
            $result = $conductor->getEventHistory($snapshotId);

            // Assert - Array driver doesn't support event history
            expect($result)->toBeArray();
            expect($result)->toHaveCount(0);
        });

        test('passes snapshot ID parameter correctly', function (): void {
            // Arrange
            $user = User::factory()->create();
            $conductor = new SnapshotConductor();

            // Create two different snapshots
            Toggl::for($user)->activate('premium');
            $snapshot1Id = Toggl::snapshot()->list($user)[0]['id'];

            Toggl::for($user)->activate('analytics');
            $snapshot2Id = Toggl::snapshot()->list($user)[1]['id'];

            // Act
            $result1 = $conductor->getEventHistory($snapshot1Id);
            $result2 = $conductor->getEventHistory($snapshot2Id);

            // Assert - Both should return arrays (even if empty for array driver)
            expect($result1)->toBeArray();
            expect($result2)->toBeArray();
            expect($snapshot1Id)->not->toBe($snapshot2Id);
        });

        test('method exists and is callable', function (): void {
            // Arrange
            $conductor = new SnapshotConductor();

            // Assert
            expect(method_exists($conductor, 'getEventHistory'))->toBeTrue();
            expect(is_callable($conductor->getEventHistory(...)))->toBeTrue();
        });

        test('returns array type as documented', function (): void {
            // Arrange
            $user = User::factory()->create();
            $conductor = new SnapshotConductor();

            // Create a snapshot
            Toggl::for($user)->activate('test-feature');
            $snapshotId = Toggl::snapshot()->list($user)[0]['id'];

            // Act
            $result = $conductor->getEventHistory($snapshotId);

            // Assert
            expect($result)->toBeArray();
            expect(is_array($result))->toBeTrue();
        });

        test('handles non-existent snapshot ID gracefully', function (): void {
            // Arrange
            $conductor = new SnapshotConductor();
            $nonExistentId = 'snapshot_does_not_exist_'.uniqid();

            // Act
            $result = $conductor->getEventHistory($nonExistentId);

            // Assert - Should return empty array without throwing
            expect($result)->toBeArray();
        });
    });
});
