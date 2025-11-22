<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\FeatureManager;
use Cline\Toggl\GroupBuilder;
use Cline\Toggl\GroupManager;
use Cline\Toggl\GroupManagerForContext;
use Cline\Toggl\GroupRepositories\ArrayFeatureGroupMembershipRepository;
use Cline\Toggl\GroupRepositories\ArrayGroupRepository;
use Cline\Toggl\Support\TogglContext;

/**
 * GroupManager test suite.
 *
 * Tests the fluent interface for managing feature groups, including group definition,
 * updates, member management, and bulk operations. The GroupManager provides a clean API
 * for working with feature groups and their memberships.
 */
describe('GroupManager', function (): void {
    describe('Happy Path', function (): void {
        test('defines a feature group', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act
            $result = $manager->define('beta-features', ['feature1', 'feature2']);

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->exists('beta-features'))->toBeTrue();
            expect($manager->get('beta-features'))->toBe(['feature1', 'feature2']);
        });

        test('creates a group with fluent builder', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act
            $builder = $manager->create('premium-features');

            // Assert
            expect($builder)->toBeInstanceOf(GroupBuilder::class);
        });

        test('updates an existing group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1']);

            // Act
            $result = $manager->update('test-group', ['feature2', 'feature3']);

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->get('test-group'))->toBe(['feature2', 'feature3']);
        });

        test('deletes a group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('temp-group', ['feature1']);

            // Act
            $result = $manager->delete('temp-group');

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->exists('temp-group'))->toBeFalse();
        });

        test('gets features in a group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('api-features', ['api-v1', 'api-v2', 'webhooks']);

            // Act
            $features = $manager->get('api-features');

            // Assert
            expect($features)->toBeArray();
            expect($features)->toBe(['api-v1', 'api-v2', 'webhooks']);
        });

        test('gets all groups', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('group1', ['feature1', 'feature2']);
            $manager->define('group2', ['feature3']);

            // Act
            $groups = $manager->all();

            // Assert
            expect($groups)->toBeArray();
            expect($groups)->toHaveKey('group1');
            expect($groups)->toHaveKey('group2');
            expect($groups['group1'])->toBe(['feature1', 'feature2']);
            expect($groups['group2'])->toBe(['feature3']);
        });

        test('checks if a group exists', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('existing-group', ['feature1']);

            // Act & Assert
            expect($manager->exists('existing-group'))->toBeTrue();
            expect($manager->exists('non-existent-group'))->toBeFalse();
        });

        test('adds features to an existing group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1']);

            // Act
            $result = $manager->add('test-group', ['feature2', 'feature3']);

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->get('test-group'))->toBe(['feature1', 'feature2', 'feature3']);
        });

        test('removes features from an existing group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1', 'feature2', 'feature3']);

            // Act
            $result = $manager->remove('test-group', ['feature2']);

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->get('test-group'))->toBe(['feature1', 'feature3']);
        });

        test('assigns a context to a group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('beta-testers', ['new-ui']);

            // Act
            $result = $manager->assign('beta-testers', TogglContext::simple('user:123', 'test'));

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->isInGroup('beta-testers', TogglContext::simple('user:123', 'test')))->toBeTrue();
        });

        test('assigns multiple contexts to a group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('premium-users', ['premium-feature']);

            // Act
            $result = $manager->assignMany('premium-users', [TogglContext::simple('user:1', 'test'), TogglContext::simple('user:2', 'test'), TogglContext::simple('user:3', 'test')]);

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->isInGroup('premium-users', TogglContext::simple('user:1', 'test')))->toBeTrue();
            expect($manager->isInGroup('premium-users', TogglContext::simple('user:2', 'test')))->toBeTrue();
            expect($manager->isInGroup('premium-users', TogglContext::simple('user:3', 'test')))->toBeTrue();
        });

        test('unassigns a context from a group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1']);
            $manager->assign('test-group', TogglContext::simple('user:123', 'test'));

            // Act
            $result = $manager->unassign('test-group', TogglContext::simple('user:123', 'test'));

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->isInGroup('test-group', TogglContext::simple('user:123', 'test')))->toBeFalse();
        });

        test('unassigns multiple contexts from a group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1']);
            $manager->assignMany('test-group', [TogglContext::simple('user:1', 'test'), TogglContext::simple('user:2', 'test'), TogglContext::simple('user:3', 'test'), TogglContext::simple('user:4', 'test')]);

            // Act
            $result = $manager->unassignMany('test-group', [TogglContext::simple('user:2', 'test'), TogglContext::simple('user:3', 'test')]);

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->isInGroup('test-group', TogglContext::simple('user:1', 'test')))->toBeTrue();
            expect($manager->isInGroup('test-group', TogglContext::simple('user:2', 'test')))->toBeFalse();
            expect($manager->isInGroup('test-group', TogglContext::simple('user:3', 'test')))->toBeFalse();
            expect($manager->isInGroup('test-group', TogglContext::simple('user:4', 'test')))->toBeTrue();
        });

        test('checks if a context is in a group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('beta-testers', ['feature1']);
            $manager->assign('beta-testers', TogglContext::simple('user:100', 'test'));

            // Act & Assert
            expect($manager->isInGroup('beta-testers', TogglContext::simple('user:100', 'test')))->toBeTrue();
            expect($manager->isInGroup('beta-testers', TogglContext::simple('user:999', 'test')))->toBeFalse();
        });

        test('gets all members of a group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('team-group', ['feature1']);
            $manager->assignMany('team-group', [TogglContext::simple('user:1', 'test'), TogglContext::simple('user:2', 'test'), TogglContext::simple('user:3', 'test')]);

            // Act
            $members = $manager->members('team-group');

            // Assert
            expect($members)->toBeArray();
            expect($members)->toContain('test|user:1');
            expect($members)->toContain('test|user:2');
            expect($members)->toContain('test|user:3');
            expect($members)->toHaveCount(3);
        });

        test('gets all groups a context belongs to', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('beta-testers', ['feature1']);
            $manager->define('premium-users', ['feature2']);
            $manager->define('admins', ['feature3']);
            $manager->assign('beta-testers', TogglContext::simple('user:5', 'test'));
            $manager->assign('premium-users', TogglContext::simple('user:5', 'test'));

            // Act
            $groups = $manager->groupsFor(TogglContext::simple('user:5', 'test'));

            // Assert
            expect($groups)->toBeArray();
            expect($groups)->toContain('beta-testers');
            expect($groups)->toContain('premium-users');
            expect($groups)->not->toContain('admins');
            expect($groups)->toHaveCount(2);
        });

        test('clears all members from a group', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1']);
            $manager->assignMany('test-group', [TogglContext::simple('user:1', 'test'), TogglContext::simple('user:2', 'test'), TogglContext::simple('user:3', 'test')]);

            // Act
            $result = $manager->clearMembers('test-group');

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->members('test-group'))->toBeEmpty();
        });
    });

    describe('Sad Path', function (): void {
        test('throws exception when getting non-existent group', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act & Assert
            expect(fn (): array => $manager->get('non-existent'))->toThrow(InvalidArgumentException::class);
        });

        test('throws exception when updating non-existent group', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act & Assert
            expect(fn (): GroupManager => $manager->update('non-existent', ['feature1']))->toThrow(InvalidArgumentException::class);
        });

        test('throws exception when adding features to non-existent group', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act & Assert
            expect(fn (): GroupManager => $manager->add('non-existent', ['feature1']))->toThrow(InvalidArgumentException::class);
        });

        test('throws exception when removing features from non-existent group', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act & Assert
            expect(fn (): GroupManager => $manager->remove('non-existent', ['feature1']))->toThrow(InvalidArgumentException::class);
        });
    });

    describe('Edge Cases', function (): void {
        test('handles empty feature list in group', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act
            $manager->define('empty-group', []);

            // Assert
            expect($manager->get('empty-group'))->toBeEmpty();
            expect($manager->exists('empty-group'))->toBeTrue();
        });

        test('handles duplicate features when adding', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1']);

            // Act
            $manager->add('test-group', ['feature1', 'feature2']);

            // Assert
            $features = $manager->get('test-group');
            expect($features)->toHaveCount(2);
            expect($features)->toBe(['feature1', 'feature2']);
        });

        test('handles removing non-existent feature', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1', 'feature2']);

            // Act
            $manager->remove('test-group', ['feature3']);

            // Assert
            expect($manager->get('test-group'))->toBe(['feature1', 'feature2']);
        });

        test('handles assigning same context multiple times', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1']);

            // Act
            $manager->assign('test-group', TogglContext::simple('user:1', 'test'));
            $manager->assign('test-group', TogglContext::simple('user:1', 'test'));
            $manager->assign('test-group', TogglContext::simple('user:1', 'test'));

            // Assert
            $members = $manager->members('test-group');
            expect($members)->toHaveCount(1);
            expect($members)->toContain('test|user:1');
        });

        test('handles unassigning context from non-existent group', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act
            $result = $manager->unassign('non-existent', TogglContext::simple('user:1', 'test'));

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
        });

        test('handles unassigning multiple contexts from non-existent group', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act
            $result = $manager->unassignMany('non-existent', [TogglContext::simple('user:1', 'test'), TogglContext::simple('user:2', 'test')]);

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
        });

        test('handles unassigning non-member context', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1']);
            $manager->assign('test-group', TogglContext::simple('user:1', 'test'));

            // Act
            $result = $manager->unassign('test-group', TogglContext::simple('user:999', 'test'));

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->members('test-group'))->toHaveCount(1);
        });

        test('handles getting members from non-existent group', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act
            $members = $manager->members('non-existent');

            // Assert
            expect($members)->toBeEmpty();
        });

        test('handles getting groups for context with no memberships', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1']);

            // Act
            $groups = $manager->groupsFor(TogglContext::simple('user:999', 'test'));

            // Assert
            expect($groups)->toBeEmpty();
        });

        test('handles clearing members from non-existent group', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act
            $result = $manager->clearMembers('non-existent');

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
        });

        test('preserves fluent interface through method chaining', function (): void {
            // Arrange
            $manager = createGroupManager();

            // Act
            $result = $manager
                ->define('chain-test', ['feature1'])
                ->add('chain-test', ['feature2'])
                ->assign('chain-test', TogglContext::simple('user:1', 'test'))
                ->assignMany('chain-test', [TogglContext::simple('user:2', 'test'), TogglContext::simple('user:3', 'test')]);

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->get('chain-test'))->toBe(['feature1', 'feature2']);
            expect($manager->members('chain-test'))->toHaveCount(3);
        });

        test('handles unassigning empty array of contexts', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1']);
            $manager->assignMany('test-group', [TogglContext::simple('user:1', 'test'), TogglContext::simple('user:2', 'test')]);

            // Act
            $result = $manager->unassignMany('test-group', []);

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->members('test-group'))->toHaveCount(2);
        });

        test('handles assigning empty array of contexts', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('test-group', ['feature1']);

            // Act
            $result = $manager->assignMany('test-group', []);

            // Assert
            expect($result)->toBeInstanceOf(GroupManager::class);
            expect($manager->members('test-group'))->toBeEmpty();
        });

        test('for returns GroupManagerForContext with isIn method', function (): void {
            // Arrange
            $manager = createGroupManager();
            $manager->define('premium-group', ['feature1']);
            $manager->assign('premium-group', TogglContext::simple('user:123', 'test'));

            // Act
            $contextdManager = $manager->for(TogglContext::simple('user:123', 'test'));
            $isInPremium = $contextdManager->isIn('premium-group');
            $isInOther = $contextdManager->isIn('other-group');

            // Assert - Line 86: isIn() method in GroupManagerForContext
            expect($contextdManager)->toBeInstanceOf(GroupManagerForContext::class);
            expect($isInPremium)->toBeTrue();
            expect($isInOther)->toBeFalse();
        });
    });
});

/**
 * Create a GroupManager instance for testing.
 *
 * Factory function that constructs a fresh GroupManager with array-based
 * repositories for both groups and memberships. Used throughout tests to create
 * isolated manager instances without shared state between test cases.
 *
 * @return GroupManager Configured group manager instance ready for testing
 */
function createGroupManager(): GroupManager
{
    $featureManager = app(FeatureManager::class);
    $groupRepository = new ArrayGroupRepository();
    $membershipRepository = new ArrayFeatureGroupMembershipRepository($featureManager);

    return new GroupManager($groupRepository, $membershipRepository);
}
