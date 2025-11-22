<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\FeatureManager;
use Cline\Toggl\GroupRepositories\ArrayGroupMembershipRepository;
use Cline\Toggl\Support\TogglContext;

/**
 * ArrayGroupMembershipRepository test suite.
 *
 * Tests the in-memory array-based group membership repository, which manages context
 * assignments to feature groups by storing memberships in memory for the duration
 * of the request. The repository supports adding/removing individual or multiple
 * contexts, checking membership, retrieving group members, finding groups for contexts,
 * and bulk operations. Tests verify all CRUD operations, idempotency, edge cases
 * like non-existent groups, duplicate additions, and proper context serialization.
 */
describe('ArrayGroupMembershipRepository', function (): void {
    /**
     * Initialize a fresh repository instance before each test.
     *
     * Creates a FeatureManager instance and ArrayGroupMembershipRepository
     * with clean state for test isolation.
     */
    beforeEach(function (): void {
        $this->manager = new FeatureManager(app());
        $this->repository = new ArrayGroupMembershipRepository($this->manager);
    });

    describe('Happy Path', function (): void {
        describe('addToGroup', function (): void {
            test('adds context to group', function (): void {
                // Arrange
                $context = TogglContext::simple('user-1', 'test');

                // Act
                $this->repository->addToGroup('beta-testers', $context);

                // Assert
                expect($this->repository->isInGroup('beta-testers', $context))->toBeTrue();
            });

            test('is idempotent when adding same context multiple times', function (): void {
                // Arrange
                $context = TogglContext::simple('user-1', 'test');

                // Act
                $this->repository->addToGroup('beta-testers', $context);
                $this->repository->addToGroup('beta-testers', $context);
                $this->repository->addToGroup('beta-testers', $context);

                // Assert
                $members = $this->repository->getGroupMembers('beta-testers');
                expect($members)->toHaveCount(1);
                expect($members)->toContain('test|user-1');
            });

            test('creates group array if it does not exist', function (): void {
                // Arrange
                $context = TogglContext::simple('user-1', 'test');

                // Act
                $this->repository->addToGroup('new-group', $context);

                // Assert
                expect($this->repository->getGroupMembers('new-group'))->toContain('test|user-1');
            });

            test('adds multiple different contexts to same group', function (): void {
                // Arrange
                $context1 = TogglContext::simple('user-1', 'test');
                $context2 = TogglContext::simple('user-2', 'test');
                $context3 = TogglContext::simple('user-3', 'test');

                // Act
                $this->repository->addToGroup('beta-testers', $context1);
                $this->repository->addToGroup('beta-testers', $context2);
                $this->repository->addToGroup('beta-testers', $context3);

                // Assert
                $members = $this->repository->getGroupMembers('beta-testers');
                expect($members)->toHaveCount(3);
                expect($members)->toContain('test|user-1');
                expect($members)->toContain('test|user-2');
                expect($members)->toContain('test|user-3');
            });
        });

        describe('addManyToGroup', function (): void {
            test('adds multiple contexts to group', function (): void {
                // Arrange
                $contexts = [
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-2', 'test'),
                    TogglContext::simple('user-3', 'test'),
                ];

                // Act
                $this->repository->addManyToGroup('beta-testers', $contexts);

                // Assert
                expect($this->repository->isInGroup('beta-testers', $contexts[0]))->toBeTrue();
                expect($this->repository->isInGroup('beta-testers', $contexts[1]))->toBeTrue();
                expect($this->repository->isInGroup('beta-testers', $contexts[2]))->toBeTrue();
            });

            test('removes duplicates when adding many contexts', function (): void {
                // Arrange
                $contexts = [
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-2', 'test'),
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-3', 'test'),
                    TogglContext::simple('user-2', 'test'),
                ];

                // Act
                $this->repository->addManyToGroup('beta-testers', $contexts);

                // Assert
                $members = $this->repository->getGroupMembers('beta-testers');
                expect($members)->toHaveCount(3);
                expect($members)->toContain('test|user-1');
                expect($members)->toContain('test|user-2');
                expect($members)->toContain('test|user-3');
            });

            test('handles empty array gracefully', function (): void {
                // Arrange
                $contexts = [];

                // Act
                $this->repository->addManyToGroup('beta-testers', $contexts);

                // Assert
                expect($this->repository->getGroupMembers('beta-testers'))->toBeEmpty();
            });
        });

        describe('removeFromGroup', function (): void {
            test('removes context from group', function (): void {
                // Arrange
                $context = TogglContext::simple('user-1', 'test');
                $this->repository->addToGroup('beta-testers', $context);

                // Act
                $this->repository->removeFromGroup('beta-testers', $context);

                // Assert
                expect($this->repository->isInGroup('beta-testers', $context))->toBeFalse();
            });

            test('removes context while keeping others', function (): void {
                // Arrange
                $contexts = [
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-2', 'test'),
                    TogglContext::simple('user-3', 'test'),
                ];
                $this->repository->addManyToGroup('beta-testers', $contexts);

                // Act
                $this->repository->removeFromGroup('beta-testers', $contexts[1]);

                // Assert
                expect($this->repository->isInGroup('beta-testers', $contexts[0]))->toBeTrue();
                expect($this->repository->isInGroup('beta-testers', $contexts[1]))->toBeFalse();
                expect($this->repository->isInGroup('beta-testers', $contexts[2]))->toBeTrue();
            });

            test('reindexes array after removal', function (): void {
                // Arrange
                $contexts = [
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-2', 'test'),
                    TogglContext::simple('user-3', 'test'),
                ];
                $this->repository->addManyToGroup('beta-testers', $contexts);

                // Act
                $this->repository->removeFromGroup('beta-testers', $contexts[1]);

                // Assert
                $members = $this->repository->getGroupMembers('beta-testers');
                expect($members)->toBe(['test|user-1', 'test|user-3']);
            });

            test('does nothing if group does not exist', function (): void {
                // Act & Assert - should not throw exception
                $context = TogglContext::simple('user-1', 'test');
                $this->repository->removeFromGroup('nonexistent', $context);

                expect($this->repository->isInGroup('nonexistent', $context))->toBeFalse();
            });

            test('does nothing if context is not in group', function (): void {
                // Arrange
                $context1 = TogglContext::simple('user-1', 'test');
                $context2 = TogglContext::simple('user-2', 'test');
                $this->repository->addToGroup('beta-testers', $context1);

                // Act
                $this->repository->removeFromGroup('beta-testers', $context2);

                // Assert
                expect($this->repository->isInGroup('beta-testers', $context1))->toBeTrue();
                expect($this->repository->getGroupMembers('beta-testers'))->toHaveCount(1);
            });
        });

        describe('removeManyFromGroup', function (): void {
            test('removes multiple contexts from group', function (): void {
                // Arrange
                $contexts = [
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-2', 'test'),
                    TogglContext::simple('user-3', 'test'),
                ];
                $this->repository->addManyToGroup('beta-testers', $contexts);

                // Act
                $this->repository->removeManyFromGroup('beta-testers', [$contexts[0], $contexts[2]]);

                // Assert
                expect($this->repository->isInGroup('beta-testers', $contexts[0]))->toBeFalse();
                expect($this->repository->isInGroup('beta-testers', $contexts[1]))->toBeTrue();
                expect($this->repository->isInGroup('beta-testers', $contexts[2]))->toBeFalse();
            });

            test('handles empty array gracefully', function (): void {
                // Arrange
                $contexts = [
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-2', 'test'),
                ];
                $this->repository->addManyToGroup('beta-testers', $contexts);

                // Act
                $this->repository->removeManyFromGroup('beta-testers', []);

                // Assert
                expect($this->repository->getGroupMembers('beta-testers'))->toHaveCount(2);
            });

            test('handles non-existent contexts gracefully', function (): void {
                // Arrange
                $contexts = [
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-2', 'test'),
                ];
                $this->repository->addManyToGroup('beta-testers', $contexts);

                // Act
                $this->repository->removeManyFromGroup('beta-testers', [
                    TogglContext::simple('user-3', 'test'),
                    TogglContext::simple('user-4', 'test'),
                ]);

                // Assert
                expect($this->repository->getGroupMembers('beta-testers'))->toHaveCount(2);
            });
        });

        describe('isInGroup', function (): void {
            test('returns true when context is in group', function (): void {
                // Arrange
                $context = TogglContext::simple('user-1', 'test');
                $this->repository->addToGroup('beta-testers', $context);

                // Act & Assert
                expect($this->repository->isInGroup('beta-testers', $context))->toBeTrue();
            });

            test('returns false when context is not in group', function (): void {
                // Arrange
                $context1 = TogglContext::simple('user-1', 'test');
                $context2 = TogglContext::simple('user-2', 'test');
                $this->repository->addToGroup('beta-testers', $context1);

                // Act & Assert
                expect($this->repository->isInGroup('beta-testers', $context2))->toBeFalse();
            });

            test('returns false for non-existent group', function (): void {
                // Act & Assert
                $context = TogglContext::simple('user-1', 'test');
                expect($this->repository->isInGroup('nonexistent', $context))->toBeFalse();
            });

            test('works with contexts having same id but different type', function (): void {
                // Arrange
                $context1 = TogglContext::simple('user-1', 'type-a');
                $context2 = TogglContext::simple('user-1', 'type-b');
                $this->repository->addToGroup('mixed', $context1);

                // Act & Assert
                expect($this->repository->isInGroup('mixed', $context1))->toBeTrue();
                expect($this->repository->isInGroup('mixed', $context2))->toBeFalse();
            });
        });

        describe('getGroupMembers', function (): void {
            test('returns all members of group', function (): void {
                // Arrange
                $contexts = [
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-2', 'test'),
                    TogglContext::simple('user-3', 'test'),
                ];
                $this->repository->addManyToGroup('beta-testers', $contexts);

                // Act
                $members = $this->repository->getGroupMembers('beta-testers');

                // Assert
                expect($members)->toHaveCount(3);
                expect($members)->toContain('test|user-1');
                expect($members)->toContain('test|user-2');
                expect($members)->toContain('test|user-3');
            });

            test('returns empty array for non-existent group', function (): void {
                // Act
                $members = $this->repository->getGroupMembers('nonexistent');

                // Assert
                expect($members)->toBeEmpty();
            });

            test('returns empty array for group with no members', function (): void {
                // Arrange
                $context = TogglContext::simple('user-1', 'test');
                $this->repository->addToGroup('beta-testers', $context);
                $this->repository->removeFromGroup('beta-testers', $context);

                // Act
                $members = $this->repository->getGroupMembers('beta-testers');

                // Assert
                expect($members)->toBeEmpty();
            });
        });

        describe('getGroupsForContext', function (): void {
            test('returns all groups containing context', function (): void {
                // Arrange
                $context = TogglContext::simple('user-1', 'test');
                $this->repository->addToGroup('beta-testers', $context);
                $this->repository->addToGroup('premium', $context);
                $this->repository->addToGroup('developers', $context);

                // Act
                $groups = $this->repository->getGroupsForContext($context);

                // Assert
                expect($groups)->toHaveCount(3);
                expect($groups)->toContain('beta-testers');
                expect($groups)->toContain('premium');
                expect($groups)->toContain('developers');
            });

            test('returns empty array when context is in no groups', function (): void {
                // Arrange
                $context1 = TogglContext::simple('user-1', 'test');
                $context2 = TogglContext::simple('user-2', 'test');
                $this->repository->addToGroup('beta-testers', $context1);

                // Act
                $groups = $this->repository->getGroupsForContext($context2);

                // Assert
                expect($groups)->toBeEmpty();
            });

            test('returns empty array when no groups exist', function (): void {
                // Act
                $context = TogglContext::simple('user-1', 'test');
                $groups = $this->repository->getGroupsForContext($context);

                // Assert
                expect($groups)->toBeEmpty();
            });

            test('does not return groups after context is removed', function (): void {
                // Arrange
                $context = TogglContext::simple('user-1', 'test');
                $this->repository->addToGroup('beta-testers', $context);
                $this->repository->addToGroup('premium', $context);
                $this->repository->removeFromGroup('beta-testers', $context);

                // Act
                $groups = $this->repository->getGroupsForContext($context);

                // Assert
                expect($groups)->toHaveCount(1);
                expect($groups)->toContain('premium');
                expect($groups)->not->toContain('beta-testers');
            });
        });

        describe('clearGroup', function (): void {
            test('removes all members from group', function (): void {
                // Arrange
                $contexts = [
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-2', 'test'),
                    TogglContext::simple('user-3', 'test'),
                ];
                $this->repository->addManyToGroup('beta-testers', $contexts);

                // Act
                $this->repository->clearGroup('beta-testers');

                // Assert
                expect($this->repository->getGroupMembers('beta-testers'))->toBeEmpty();
                expect($this->repository->isInGroup('beta-testers', $contexts[0]))->toBeFalse();
                expect($this->repository->isInGroup('beta-testers', $contexts[1]))->toBeFalse();
                expect($this->repository->isInGroup('beta-testers', $contexts[2]))->toBeFalse();
            });

            test('does nothing for non-existent group', function (): void {
                // Act & Assert - should not throw exception
                $this->repository->clearGroup('nonexistent');

                expect($this->repository->getGroupMembers('nonexistent'))->toBeEmpty();
            });

            test('allows group to be repopulated after clearing', function (): void {
                // Arrange
                $contexts = [
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-2', 'test'),
                ];
                $context3 = TogglContext::simple('user-3', 'test');
                $this->repository->addManyToGroup('beta-testers', $contexts);
                $this->repository->clearGroup('beta-testers');

                // Act
                $this->repository->addToGroup('beta-testers', $context3);

                // Assert
                expect($this->repository->getGroupMembers('beta-testers'))->toHaveCount(1);
                expect($this->repository->isInGroup('beta-testers', $context3))->toBeTrue();
            });

            test('only clears specified group', function (): void {
                // Arrange
                $this->repository->addManyToGroup('beta-testers', [
                    TogglContext::simple('user-1', 'test'),
                    TogglContext::simple('user-2', 'test'),
                ]);
                $this->repository->addManyToGroup('premium', [
                    TogglContext::simple('user-3', 'test'),
                    TogglContext::simple('user-4', 'test'),
                ]);

                // Act
                $this->repository->clearGroup('beta-testers');

                // Assert
                expect($this->repository->getGroupMembers('beta-testers'))->toBeEmpty();
                expect($this->repository->getGroupMembers('premium'))->toHaveCount(2);
            });
        });

        describe('removeContextFromAllGroups', function (): void {
            test('removes context from all groups', function (): void {
                // Arrange
                $context = TogglContext::simple('user-1', 'test');
                $this->repository->addToGroup('beta-testers', $context);
                $this->repository->addToGroup('premium', $context);
                $this->repository->addToGroup('developers', $context);

                // Act
                $this->repository->removeContextFromAllGroups($context);

                // Assert
                expect($this->repository->isInGroup('beta-testers', $context))->toBeFalse();
                expect($this->repository->isInGroup('premium', $context))->toBeFalse();
                expect($this->repository->isInGroup('developers', $context))->toBeFalse();
                expect($this->repository->getGroupsForContext($context))->toBeEmpty();
            });

            test('keeps other contexts in groups', function (): void {
                // Arrange
                $context1 = TogglContext::simple('user-1', 'test');
                $context2 = TogglContext::simple('user-2', 'test');
                $context3 = TogglContext::simple('user-3', 'test');
                $context4 = TogglContext::simple('user-4', 'test');
                $this->repository->addManyToGroup('beta-testers', [$context1, $context2, $context3]);
                $this->repository->addManyToGroup('premium', [$context1, $context4]);

                // Act
                $this->repository->removeContextFromAllGroups($context1);

                // Assert
                expect($this->repository->getGroupMembers('beta-testers'))->toHaveCount(2);
                expect($this->repository->isInGroup('beta-testers', $context2))->toBeTrue();
                expect($this->repository->isInGroup('beta-testers', $context3))->toBeTrue();
                expect($this->repository->getGroupMembers('premium'))->toHaveCount(1);
                expect($this->repository->isInGroup('premium', $context4))->toBeTrue();
            });

            test('reindexes all affected groups', function (): void {
                // Arrange
                $context1 = TogglContext::simple('user-1', 'test');
                $context2 = TogglContext::simple('user-2', 'test');
                $context3 = TogglContext::simple('user-3', 'test');
                $context4 = TogglContext::simple('user-4', 'test');
                $context5 = TogglContext::simple('user-5', 'test');
                $this->repository->addManyToGroup('group-1', [$context1, $context2, $context3]);
                $this->repository->addManyToGroup('group-2', [$context4, $context1, $context5]);

                // Act
                $this->repository->removeContextFromAllGroups($context1);

                // Assert
                expect($this->repository->getGroupMembers('group-1'))->toBe(['test|user-2', 'test|user-3']);
                expect($this->repository->getGroupMembers('group-2'))->toBe(['test|user-4', 'test|user-5']);
            });

            test('does nothing if context is in no groups', function (): void {
                // Arrange
                $context1 = TogglContext::simple('user-1', 'test');
                $context2 = TogglContext::simple('user-2', 'test');
                $context3 = TogglContext::simple('user-3', 'test');
                $this->repository->addManyToGroup('beta-testers', [$context1, $context2]);

                // Act
                $this->repository->removeContextFromAllGroups($context3);

                // Assert
                expect($this->repository->getGroupMembers('beta-testers'))->toHaveCount(2);
            });
        });
    });

    describe('Edge Cases', function (): void {
        test('adding and removing context multiple times maintains consistency', function (): void {
            // Arrange
            $context = TogglContext::simple('user-1', 'test');

            // Act
            $this->repository->addToGroup('beta-testers', $context);
            $this->repository->removeFromGroup('beta-testers', $context);
            $this->repository->addToGroup('beta-testers', $context);
            $this->repository->removeFromGroup('beta-testers', $context);
            $this->repository->addToGroup('beta-testers', $context);

            // Assert
            expect($this->repository->isInGroup('beta-testers', $context))->toBeTrue();
            expect($this->repository->getGroupMembers('beta-testers'))->toHaveCount(1);
        });

        test('adding context to multiple groups is independent', function (): void {
            // Arrange
            $context = TogglContext::simple('user-1', 'test');

            // Act
            $this->repository->addToGroup('group-1', $context);
            $this->repository->addToGroup('group-2', $context);
            $this->repository->removeFromGroup('group-1', $context);

            // Assert
            expect($this->repository->isInGroup('group-1', $context))->toBeFalse();
            expect($this->repository->isInGroup('group-2', $context))->toBeTrue();
        });

        test('handles groups with special characters in names', function (): void {
            // Arrange
            $groupName = 'beta-testers_2024.premium@v1';
            $context = TogglContext::simple('user-1', 'test');

            // Act
            $this->repository->addToGroup($groupName, $context);

            // Assert
            expect($this->repository->isInGroup($groupName, $context))->toBeTrue();
            expect($this->repository->getGroupsForContext($context))->toContain($groupName);
        });

        test('handles large number of contexts in group', function (): void {
            // Arrange
            $contexts = [];

            for ($i = 1; $i <= 1_000; ++$i) {
                $contexts[] = TogglContext::simple('user-'.$i, 'test');
            }

            // Act
            $this->repository->addManyToGroup('large-group', $contexts);

            // Assert
            expect($this->repository->getGroupMembers('large-group'))->toHaveCount(1_000);
            expect($this->repository->isInGroup('large-group', TogglContext::simple('user-500', 'test')))->toBeTrue();
        });

        test('handles context being member of many groups', function (): void {
            // Arrange
            $context = TogglContext::simple('user-1', 'test');

            for ($i = 1; $i <= 50; ++$i) {
                $this->repository->addToGroup('group-'.$i, $context);
            }

            // Act
            $groups = $this->repository->getGroupsForContext($context);

            // Assert
            expect($groups)->toHaveCount(50);
        });

        test('serialization is consistent across calls', function (): void {
            // Arrange
            $context1 = TogglContext::simple('test-id', 'test');
            $context2 = TogglContext::simple('test-id', 'test');

            // Act
            $this->repository->addToGroup('test-group', $context1);

            // Assert - different object instances with same serialization should match
            expect($this->repository->isInGroup('test-group', $context2))->toBeTrue();
        });

        test('group state is isolated between groups', function (): void {
            // Arrange
            $this->repository->addManyToGroup('group-1', [
                TogglContext::simple('user-1', 'test'),
                TogglContext::simple('user-2', 'test'),
            ]);
            $this->repository->addManyToGroup('group-2', [
                TogglContext::simple('user-3', 'test'),
                TogglContext::simple('user-4', 'test'),
            ]);

            // Act
            $this->repository->clearGroup('group-1');

            // Assert
            expect($this->repository->getGroupMembers('group-1'))->toBeEmpty();
            expect($this->repository->getGroupMembers('group-2'))->toHaveCount(2);
        });
    });
});
