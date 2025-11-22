<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Toggl;
use Tests\Fixtures\FeatureFlag;
use Tests\Fixtures\User;

/**
 * Sync Conductor Test Suite
 *
 * Tests the sync conductor pattern: Toggl::sync($user)->features(['premium', 'analytics'])
 * This pattern replaces all existing features/groups with the provided ones,
 * similar to Laravel's relationship sync() method.
 */
describe('Sync Conductor', function (): void {
    describe('Feature Sync', function (): void {
        test('can sync features for a context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['old-1', 'old-2', 'old-3']);

            // Act
            Toggl::sync($user)->features(['new-1', 'new-2']);

            // Assert - only new features are active
            expect(Toggl::for($user)->active('new-1'))->toBeTrue();
            expect(Toggl::for($user)->active('new-2'))->toBeTrue();
            expect(Toggl::for($user)->active('old-1'))->toBeFalse();
            expect(Toggl::for($user)->active('old-2'))->toBeFalse();
            expect(Toggl::for($user)->active('old-3'))->toBeFalse();
        });

        test('can sync empty array to remove all features', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['feat-1', 'feat-2', 'feat-3']);

            // Act
            Toggl::sync($user)->features([]);

            // Assert - all features removed
            expect(Toggl::for($user)->active('feat-1'))->toBeFalse();
            expect(Toggl::for($user)->active('feat-2'))->toBeFalse();
            expect(Toggl::for($user)->active('feat-3'))->toBeFalse();
        });

        test('sync only affects specified context', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            Toggl::for($user1)->activate(['feat-1', 'feat-2']);
            Toggl::for($user2)->activate(['feat-1', 'feat-2']);

            // Act - sync only user1
            Toggl::sync($user1)->features(['feat-3']);

            // Assert - user1 synced, user2 unchanged
            expect(Toggl::for($user1)->active('feat-1'))->toBeFalse();
            expect(Toggl::for($user1)->active('feat-2'))->toBeFalse();
            expect(Toggl::for($user1)->active('feat-3'))->toBeTrue();

            expect(Toggl::for($user2)->active('feat-1'))->toBeTrue();
            expect(Toggl::for($user2)->active('feat-2'))->toBeTrue();
            expect(Toggl::for($user2)->active('feat-3'))->toBeFalse();
        });

        test('sync with overlapping features preserves common ones', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['feat-1', 'feat-2', 'feat-3']);

            // Act - keep feat-2, add feat-4
            Toggl::sync($user)->features(['feat-2', 'feat-4']);

            // Assert
            expect(Toggl::for($user)->active('feat-1'))->toBeFalse();
            expect(Toggl::for($user)->active('feat-2'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-3'))->toBeFalse();
            expect(Toggl::for($user)->active('feat-4'))->toBeTrue();
        });
    });

    describe('Group Sync', function (): void {
        test('can sync groups for a context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::groups()->define('old-group-1', ['feat-1']);
            Toggl::groups()->define('old-group-2', ['feat-2']);
            Toggl::groups()->define('new-group-1', ['feat-3']);
            Toggl::groups()->define('new-group-2', ['feat-4']);

            Toggl::groups()->for($user)->assign('old-group-1');
            Toggl::groups()->for($user)->assign('old-group-2');

            // Act
            Toggl::sync($user)->groups(['new-group-1', 'new-group-2']);

            // Assert - only new groups
            $userGroups = Toggl::groups()->for($user)->groups();
            expect($userGroups)->toContain('new-group-1');
            expect($userGroups)->toContain('new-group-2');
            expect($userGroups)->not->toContain('old-group-1');
            expect($userGroups)->not->toContain('old-group-2');
        });

        test('can sync empty array to remove all feature group memberships', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::groups()->define('group-1', ['feat-1']);
            Toggl::groups()->define('group-2', ['feat-2']);
            Toggl::groups()->for($user)->assign('group-1');
            Toggl::groups()->for($user)->assign('group-2');

            // Act
            Toggl::sync($user)->groups([]);

            // Assert - all groups removed
            $userGroups = Toggl::groups()->for($user)->groups();
            expect($userGroups)->toBeEmpty();
        });

        test('group sync only affects specified context', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            Toggl::groups()->define('beta', ['feat-1']);
            Toggl::groups()->define('premium', ['feat-2']);

            Toggl::groups()->for($user1)->assign('beta');
            Toggl::groups()->for($user2)->assign('beta');

            // Act - sync only user1
            Toggl::sync($user1)->groups(['premium']);

            // Assert
            $user1Groups = Toggl::groups()->for($user1)->groups();
            $user2Groups = Toggl::groups()->for($user2)->groups();

            expect($user1Groups)->toContain('premium');
            expect($user1Groups)->not->toContain('beta');
            expect($user2Groups)->toContain('beta');
            expect($user2Groups)->not->toContain('premium');
        });
    });

    describe('Value Sync', function (): void {
        test('can sync features with values', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('old-theme', 'light');
            Toggl::for($user)->activate('old-lang', 'en');

            // Act
            Toggl::sync($user)->withValues([
                'theme' => 'dark',
                'language' => 'es',
                'notifications' => ['email' => true],
            ]);

            // Assert - only new features with values
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
            expect(Toggl::for($user)->value('language'))->toBe('es');
            expect(Toggl::for($user)->value('notifications'))->toBe(['email' => true]);
            expect(Toggl::for($user)->active('old-theme'))->toBeFalse();
            expect(Toggl::for($user)->active('old-lang'))->toBeFalse();
        });

        test('can sync empty values to remove all features', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('theme', 'dark');
            Toggl::for($user)->activate('language', 'es');

            // Act
            Toggl::sync($user)->withValues([]);

            // Assert - all removed
            expect(Toggl::for($user)->active('theme'))->toBeFalse();
            expect(Toggl::for($user)->active('language'))->toBeFalse();
        });

        test('value sync only affects specified context', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            Toggl::for($user1)->activate('theme', 'light');
            Toggl::for($user2)->activate('theme', 'light');

            // Act - sync only user1
            Toggl::sync($user1)->withValues(['theme' => 'dark']);

            // Assert
            expect(Toggl::for($user1)->value('theme'))->toBe('dark');
            expect(Toggl::for($user2)->value('theme'))->toBe('light');
        });
    });

    describe('Edge Cases', function (): void {
        test('syncing with same features is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['feat-1', 'feat-2']);

            // Act
            Toggl::sync($user)->features(['feat-1', 'feat-2']);
            Toggl::sync($user)->features(['feat-1', 'feat-2']);

            // Assert - still has same features
            expect(Toggl::for($user)->active('feat-1'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-2'))->toBeTrue();
        });

        test('sync works with no existing features', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::sync($user)->features(['feat-1', 'feat-2']);

            // Assert
            expect(Toggl::for($user)->active('feat-1'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-2'))->toBeTrue();
        });

        test('sync works with BackedEnum features', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(FeatureFlag::NewDashboard);
            Toggl::for($user)->activate(FeatureFlag::BetaFeatures);

            // Act
            Toggl::sync($user)->features([
                FeatureFlag::ApiV2->value,
            ]);

            // Assert
            expect(Toggl::for($user)->active(FeatureFlag::ApiV2))->toBeTrue();
            expect(Toggl::for($user)->active(FeatureFlag::NewDashboard))->toBeFalse();
            expect(Toggl::for($user)->active(FeatureFlag::BetaFeatures))->toBeFalse();
        });
    });

    describe('Integration', function (): void {
        test('can chain multiple sync operations', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::groups()->define('beta', ['feat-1']);

            // Act
            Toggl::sync($user)->features(['feat-1', 'feat-2']);
            Toggl::sync($user)->groups(['beta']);
            Toggl::sync($user)->withValues(['theme' => 'dark']);

            // Assert - last sync (withValues) removed features, but groups remain
            expect(Toggl::for($user)->active('theme'))->toBeTrue();
            expect(Toggl::for($user)->active('feat-1'))->toBeFalse(); // Removed by withValues sync
            expect(Toggl::for($user)->active('feat-2'))->toBeFalse(); // Removed by withValues sync

            $userGroups = Toggl::groups()->for($user)->groups();
            expect($userGroups)->toContain('beta'); // Groups are separate from features
        });

        test('sync respects defined feature resolvers', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::define('with-resolver', fn (): bool => false);
            Toggl::for($user)->activate('old-feature');

            // Act
            Toggl::sync($user)->features(['with-resolver']);

            // Assert - activated despite resolver returning false
            expect(Toggl::for($user)->active('with-resolver'))->toBeTrue();
            expect(Toggl::for($user)->active('old-feature'))->toBeFalse();
        });
    });
});
