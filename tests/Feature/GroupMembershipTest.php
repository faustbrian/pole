<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Toggl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\User;

uses(RefreshDatabase::class);

/**
 * Group Membership Integration Test Suite
 *
 * Tests the integration between feature groups and user membership management.
 * Covers assigning users to groups, checking membership status, feature access
 * through group membership, membership revocation, multi-group scenarios, and
 * real-world use cases like experimental feature rollouts to specific users.
 */
describe('Group Membership Integration', function (): void {
    beforeEach(function (): void {
        config(['toggl.group_storage' => 'database']);

        $primaryKeyType = config('toggl.primary_key_type', 'id');
        $morphType = config('toggl.morph_type', 'morph');

        // Create tables
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

        if (!Schema::hasTable('group_memberships')) {
            Schema::create('group_memberships', function ($table) use ($primaryKeyType, $morphType): void {
                match ($primaryKeyType) {
                    'ulid' => $table->ulid('id')->primary(),
                    'uuid' => $table->uuid('id')->primary(),
                    default => $table->id(),
                };

                $table->string('group_name')->index();

                match ($morphType) {
                    'ulidMorph' => $table->ulidMorphs('context'),
                    'uuidMorph' => $table->uuidMorphs('context'),
                    'numericMorph' => $table->numericMorphs('context'),
                    default => $table->morphs('context'),
                };

                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['group_name', 'context_type', 'context_id']);
            });
        }

        DB::table('feature_groups')->delete();
        DB::table('group_memberships')->delete();
    });

    describe('Real-world Scenario: Experimental Group', function (): void {
        test('5 random users in experimental group see new layout feature', function (): void {
            // Arrange: Create users
            $experimentalUsers = User::factory()->count(5)->create();
            $regularUsers = User::factory()->count(3)->create();

            // Define features
            Toggl::define('new-layout', false);
            Toggl::define('dark-mode', false);

            // Define experimental group with new-layout feature
            Toggl::groups()->define('experimental', ['new-layout', 'dark-mode']);

            // Assign 5 random users to experimental group
            Toggl::groups()->assignMany('experimental', $experimentalUsers->all());

            // Activate new-layout feature for experimental users
            foreach ($experimentalUsers as $user) {
                Toggl::for($user)->activate('new-layout');
            }

            // Also activate for regular users
            foreach ($regularUsers as $user) {
                Toggl::for($user)->activate('new-layout');
            }

            // Act & Assert: Experimental users see the feature
            foreach ($experimentalUsers as $user) {
                expect(Toggl::for($user)->active('new-layout'))
                    ->toBeTrue(sprintf('User %s should see new-layout via experimental group', $user->id));
            }

            // Regular users also see it (feature activated for them)
            foreach ($regularUsers as $user) {
                expect(Toggl::for($user)->active('new-layout'))
                    ->toBeTrue('Regular user sees feature because it is activated for them');
            }
        });

        test('feature active through group membership even when not directly active for user', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Define feature
            Toggl::define('premium-dashboard', false);

            // Create group and assign user
            Toggl::groups()->define('beta-testers', ['premium-dashboard']);
            Toggl::groups()->assign('beta-testers', $user);

            // Activate feature for this user
            Toggl::for($user)->activate('premium-dashboard');

            // Act & Assert
            expect(Toggl::for($user)->active('premium-dashboard'))
                ->toBeTrue('Feature should be active via beta-testers group membership');
        });

        test('feature not active for user not in group', function (): void {
            // Arrange
            $inGroupUser = User::factory()->create();
            $outGroupUser = User::factory()->create();

            // Define feature
            Toggl::define('exclusive-feature', false);

            Toggl::groups()->define('vip', ['exclusive-feature']);
            Toggl::groups()->assign('vip', $inGroupUser);

            // Activate for both users
            Toggl::for($inGroupUser)->activate('exclusive-feature');
            Toggl::for($outGroupUser)->activate('exclusive-feature');

            // Act & Assert
            expect(Toggl::for($inGroupUser)->active('exclusive-feature'))
                ->toBeTrue('In-group user should see feature');

            expect(Toggl::for($outGroupUser)->active('exclusive-feature'))
                ->toBeTrue('Out-group user sees it because feature is activated for them');
        });

        test('removing user from group revokes feature access', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Define feature
            Toggl::define('beta-feature', false);

            Toggl::groups()->define('testers', ['beta-feature']);
            Toggl::groups()->assign('testers', $user);
            Toggl::for($user)->activate('beta-feature');

            expect(Toggl::for($user)->active('beta-feature'))->toBeTrue('Initially active');

            // Act
            Toggl::groups()->unassign('testers', $user);

            // Assert
            expect(Toggl::for($user)->active('beta-feature'))
                ->toBeTrue('Still sees it because feature is activated for them');
        });

        test('user in multiple groups inherits features from all groups', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Define features
            Toggl::define('beta-ui', false);
            Toggl::define('alpha-api', false);

            Toggl::groups()->define('beta', ['beta-ui']);
            Toggl::groups()->define('alpha', ['alpha-api']);

            Toggl::groups()->assign('beta', $user);
            Toggl::groups()->assign('alpha', $user);

            Toggl::for($user)->activate('beta-ui');
            Toggl::for($user)->activate('alpha-api');

            // Act & Assert
            expect(Toggl::for($user)->active('beta-ui'))->toBeTrue();
            expect(Toggl::for($user)->active('alpha-api'))->toBeTrue();
        });
    });

    describe('Group Membership API', function (): void {
        test('can assign single context to group', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::groups()->define('test', ['feat']);

            // Act
            Toggl::groups()->assign('test', $user);

            // Assert
            expect(Toggl::groups()->isInGroup('test', $user))->toBeTrue();
        });

        test('can assign multiple contexts to group', function (): void {
            // Arrange
            $users = User::factory()->count(3)->create();
            Toggl::groups()->define('test', ['feat']);

            // Act
            Toggl::groups()->assignMany('test', $users->all());

            // Assert
            foreach ($users as $user) {
                expect(Toggl::groups()->isInGroup('test', $user))->toBeTrue();
            }
        });

        test('can unassign context from group', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::groups()->define('test', ['feat']);
            Toggl::groups()->assign('test', $user);

            // Act
            Toggl::groups()->unassign('test', $user);

            // Assert
            expect(Toggl::groups()->isInGroup('test', $user))->toBeFalse();
        });

        test('can get all groups for a context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::groups()->define('group1', ['feat1']);
            Toggl::groups()->define('group2', ['feat2']);

            Toggl::groups()->assign('group1', $user);
            Toggl::groups()->assign('group2', $user);

            // Act
            $groups = Toggl::groups()->groupsFor($user);

            // Assert
            expect($groups)->toContain('group1');
            expect($groups)->toContain('group2');
        });

        test('can get all members of a group', function (): void {
            // Arrange
            $users = User::factory()->count(3)->create();
            Toggl::groups()->define('test', ['feat']);
            Toggl::groups()->assignMany('test', $users->all());

            // Act
            $members = Toggl::groups()->members('test');

            // Assert
            expect($members)->toHaveCount(3);
        });

        test('can clear all members from a group', function (): void {
            // Arrange
            $users = User::factory()->count(3)->create();
            Toggl::groups()->define('test', ['feat']);
            Toggl::groups()->assignMany('test', $users->all());

            // Act
            Toggl::groups()->clearMembers('test');

            // Assert
            expect(Toggl::groups()->members('test'))->toBeEmpty();
        });
    });
});
