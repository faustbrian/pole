<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\GroupRepositories\ArrayGroupRepository;

/**
 * ArrayGroupRepository test suite.
 *
 * Tests the in-memory array-based group repository, which manages feature flag groups
 * by storing group definitions and their associated features in memory. The repository
 * supports defining groups, adding/removing features, updating group memberships, and
 * storing optional metadata. Tests verify CRUD operations, duplicate handling, error
 * conditions for non-existent groups, and edge cases like empty feature arrays.
 */
describe('ArrayGroupRepository', function (): void {
    /**
     * Initialize a fresh repository instance before each test.
     */
    beforeEach(function (): void {
        $this->repository = new ArrayGroupRepository();
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
            expect($all)->toBe([
                'group1' => ['feat1'],
                'group2' => ['feat2'],
            ]);
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

        test('stores metadata', function (): void {
            // Act
            $this->repository->define('test', ['feat1'], ['description' => 'Test group']);

            // Assert - metadata is stored but not returned by get()
            expect($this->repository->get('test'))->toBe(['feat1']);
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
                ->toThrow(InvalidArgumentException::class, 'Feature group [nonexistent] does not exist.');
        });

        test('throws exception when removing from non-existent group', function (): void {
            // Act & Assert
            expect(fn () => $this->repository->removeFeatures('nonexistent', ['feat']))
                ->toThrow(InvalidArgumentException::class, 'Feature group [nonexistent] does not exist.');
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

        test('redefining group replaces existing definition', function (): void {
            // Arrange
            $this->repository->define('test', ['feat1']);

            // Act
            $this->repository->define('test', ['feat2']);

            // Assert
            expect($this->repository->get('test'))->toBe(['feat2']);
        });

        test('delete on non-existent group is safe', function (): void {
            // Act & Assert
            expect(fn () => $this->repository->delete('nonexistent'))->not->toThrow(Exception::class);
        });

        test('all() returns empty array when no groups defined', function (): void {
            // Act
            $all = $this->repository->all();

            // Assert
            expect($all)->toBeEmpty();
            expect($all)->toBeArray();
        });
    });
});
