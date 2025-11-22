<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Database\FeatureGroupMembership;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Organization;
use Tests\Fixtures\User;

uses(RefreshDatabase::class);

/**
 * FeatureGroupMembership Model Test Suite
 *
 * Tests the FeatureGroupMembership Eloquent model, specifically focusing on:
 * - context() morphTo relationship method (line 86)
 * - Polymorphic relationship with different model types (User, Organization)
 * - Proper context_type and context_id column linking
 * - Relationship query methods and eager loading
 * - Metadata handling in feature group memberships
 *
 * These tests ensure 100% coverage of the FeatureGroupMembership database model.
 */
describe('FeatureGroupMembership Model', function (): void {
    describe('context() Relationship', function (): void {
        describe('Happy Paths', function (): void {
            test('context() method returns MorphTo relationship instance', function (): void {
                // Arrange
                $membership = new FeatureGroupMembership();

                // Act
                $relationship = $membership->context();

                // Assert
                expect($relationship)->toBeInstanceOf(MorphTo::class);
            });

            test('context relationship returns correct User model', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'John Doe',
                    'email' => 'john@example.com',
                ]);

                $membership = FeatureGroupMembership::query()->create([
                    'group_name' => 'premium-users',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'metadata' => ['role' => 'subscriber'],
                ]);

                // Act
                $context = $membership->context;

                // Assert
                expect($context)->toBeInstanceOf(User::class)
                    ->and($context->getKey())->toBe($user->getKey())
                    ->and($context->name)->toBe('John Doe')
                    ->and($context->email)->toBe('john@example.com');
            });

            test('context relationship returns correct Organization model', function (): void {
                // Arrange
                $organization = Organization::query()->create([
                    'name' => 'Acme Corporation',
                ]);

                $membership = FeatureGroupMembership::query()->create([
                    'group_name' => 'enterprise-tier',
                    'context_type' => Organization::class,
                    'context_id' => $organization->getKey(),
                    'metadata' => ['tier' => 'platinum'],
                ]);

                // Act
                $context = $membership->context;

                // Assert
                expect($context)->toBeInstanceOf(Organization::class)
                    ->and($context->getKey())->toBe($organization->getKey())
                    ->and($context->name)->toBe('Acme Corporation');
            });

            test('multiple memberships can share same User context', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Jane Smith',
                    'email' => 'jane@example.com',
                ]);

                $membership1 = FeatureGroupMembership::query()->create([
                    'group_name' => 'beta-testers',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'metadata' => ['joined' => '2024-01-15'],
                ]);

                $membership2 = FeatureGroupMembership::query()->create([
                    'group_name' => 'early-adopters',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'metadata' => ['joined' => '2024-02-20'],
                ]);

                // Act
                $context1 = $membership1->context;
                $context2 = $membership2->context;

                // Assert
                expect($context1)->toBeInstanceOf(User::class)
                    ->and($context2)->toBeInstanceOf(User::class)
                    ->and($context1->getKey())->toBe($user->getKey())
                    ->and($context2->getKey())->toBe($user->getKey())
                    ->and($context1->email)->toBe($context2->email);
            });

            test('multiple memberships can share same Organization context', function (): void {
                // Arrange
                $organization = Organization::query()->create([
                    'name' => 'Tech Startup Inc',
                ]);

                $membership1 = FeatureGroupMembership::query()->create([
                    'group_name' => 'api-access',
                    'context_type' => Organization::class,
                    'context_id' => $organization->getKey(),
                    'metadata' => ['limit' => 1_000],
                ]);

                $membership2 = FeatureGroupMembership::query()->create([
                    'group_name' => 'premium-support',
                    'context_type' => Organization::class,
                    'context_id' => $organization->getKey(),
                    'metadata' => ['sla' => '24h'],
                ]);

                // Act
                $context1 = $membership1->context;
                $context2 = $membership2->context;

                // Assert
                expect($context1)->toBeInstanceOf(Organization::class)
                    ->and($context2)->toBeInstanceOf(Organization::class)
                    ->and($context1->getKey())->toBe($organization->getKey())
                    ->and($context2->getKey())->toBe($organization->getKey())
                    ->and($context1->name)->toBe($context2->name);
            });

            test('context relationship works with eager loading for User', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Alice Johnson',
                    'email' => 'alice@example.com',
                ]);

                FeatureGroupMembership::query()->create([
                    'group_name' => 'vip-members',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                ]);

                // Act
                $membership = FeatureGroupMembership::query()
                    ->with('context')
                    ->where('group_name', 'vip-members')
                    ->first();

                // Assert
                expect($membership)->not->toBeNull()
                    ->and($membership->relationLoaded('context'))->toBeTrue()
                    ->and($membership->context)->toBeInstanceOf(User::class)
                    ->and($membership->context->name)->toBe('Alice Johnson');
            });

            test('context relationship works with eager loading for Organization', function (): void {
                // Arrange
                $organization = Organization::query()->create([
                    'name' => 'Global Enterprises',
                ]);

                FeatureGroupMembership::query()->create([
                    'group_name' => 'corporate-accounts',
                    'context_type' => Organization::class,
                    'context_id' => $organization->getKey(),
                ]);

                // Act
                $membership = FeatureGroupMembership::query()
                    ->with('context')
                    ->where('group_name', 'corporate-accounts')
                    ->first();

                // Assert
                expect($membership)->not->toBeNull()
                    ->and($membership->relationLoaded('context'))->toBeTrue()
                    ->and($membership->context)->toBeInstanceOf(Organization::class)
                    ->and($membership->context->name)->toBe('Global Enterprises');
            });

            test('can query memberships by context type', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Bob Wilson',
                    'email' => 'bob@example.com',
                ]);

                $organization = Organization::query()->create([
                    'name' => 'Wilson Corp',
                ]);

                FeatureGroupMembership::query()->create([
                    'group_name' => 'test-group',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                ]);

                FeatureGroupMembership::query()->create([
                    'group_name' => 'test-group',
                    'context_type' => Organization::class,
                    'context_id' => $organization->getKey(),
                ]);

                // Act
                $userMemberships = FeatureGroupMembership::query()
                    ->where('context_type', User::class)
                    ->get();

                $orgMemberships = FeatureGroupMembership::query()
                    ->where('context_type', Organization::class)
                    ->get();

                // Assert
                expect($userMemberships)->toHaveCount(1)
                    ->and($orgMemberships)->toHaveCount(1)
                    ->and($userMemberships->first()->context)->toBeInstanceOf(User::class)
                    ->and($orgMemberships->first()->context)->toBeInstanceOf(Organization::class);
            });

            test('context relationship preserves membership metadata', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Charlie Brown',
                    'email' => 'charlie@example.com',
                ]);

                $metadata = [
                    'subscription_tier' => 'gold',
                    'features' => ['analytics', 'reporting'],
                    'expires_at' => '2025-12-31',
                ];

                $membership = FeatureGroupMembership::query()->create([
                    'group_name' => 'premium-analytics',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'metadata' => $metadata,
                ]);

                // Act
                $context = $membership->context;

                // Assert
                expect($context)->toBeInstanceOf(User::class)
                    ->and($membership->metadata)->toBe($metadata)
                    ->and($membership->metadata['subscription_tier'])->toBe('gold')
                    ->and($membership->metadata['features'])->toHaveCount(2);
            });
        });

        describe('Edge Cases', function (): void {
            test('context returns null when context model does not exist', function (): void {
                // Arrange
                $nonExistentId = match (config('toggl.primary_key_type')) {
                    'ulid' => '01JDABCDEFGHIJKLMNOPQRSTUV', // Non-existent ULID
                    'uuid' => '99999999-9999-9999-9999-999999999999', // Non-existent UUID
                    default => 999_999, // Non-existent numeric ID
                };

                $membership = FeatureGroupMembership::query()->create([
                    'group_name' => 'orphaned-membership',
                    'context_type' => User::class,
                    'context_id' => $nonExistentId,
                ]);

                // Act
                $context = $membership->context;

                // Assert
                expect($context)->toBeNull();
            });

            test('context relationship works with null metadata', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Diana Prince',
                    'email' => 'diana@example.com',
                ]);

                $membership = FeatureGroupMembership::query()->create([
                    'group_name' => 'basic-tier',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'metadata' => null,
                ]);

                // Act
                $context = $membership->context;

                // Assert
                expect($context)->toBeInstanceOf(User::class)
                    ->and($membership->metadata)->toBeNull();
            });

            test('context relationship works with empty metadata array', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Eve Adams',
                    'email' => 'eve@example.com',
                ]);

                $membership = FeatureGroupMembership::query()->create([
                    'group_name' => 'standard-tier',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                    'metadata' => [],
                ]);

                // Act
                $context = $membership->context;

                // Assert
                expect($context)->toBeInstanceOf(User::class)
                    ->and($membership->metadata)->toBe([]);
            });

            test('can update context relationship to different model instance', function (): void {
                // Arrange
                $user1 = User::query()->create([
                    'name' => 'Frank Miller',
                    'email' => 'frank@example.com',
                ]);

                $user2 = User::query()->create([
                    'name' => 'Grace Hopper',
                    'email' => 'grace@example.com',
                ]);

                $membership = FeatureGroupMembership::query()->create([
                    'group_name' => 'developers',
                    'context_type' => User::class,
                    'context_id' => $user1->getKey(),
                ]);

                // Act
                $membership->update(['context_id' => $user2->getKey()]);
                $membership->refresh();

                // Assert
                expect($membership->context)->toBeInstanceOf(User::class)
                    ->and($membership->context->getKey())->toBe($user2->getKey())
                    ->and($membership->context->name)->toBe('Grace Hopper');
            });

            test('can update context relationship to different model type', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Henry Ford',
                    'email' => 'henry@example.com',
                ]);

                $organization = Organization::query()->create([
                    'name' => 'Ford Industries',
                ]);

                $membership = FeatureGroupMembership::query()->create([
                    'group_name' => 'legacy-members',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                ]);

                // Act
                $membership->update([
                    'context_type' => Organization::class,
                    'context_id' => $organization->getKey(),
                ]);
                $membership->refresh();

                // Assert
                expect($membership->context)->toBeInstanceOf(Organization::class)
                    ->and($membership->context->getKey())->toBe($organization->getKey())
                    ->and($membership->context->name)->toBe('Ford Industries');
            });

            test('multiple memberships in different groups for same context', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Ivy Chen',
                    'email' => 'ivy@example.com',
                ]);

                $groups = ['alpha-group', 'beta-group', 'gamma-group'];

                foreach ($groups as $group) {
                    FeatureGroupMembership::query()->create([
                        'group_name' => $group,
                        'context_type' => User::class,
                        'context_id' => $user->getKey(),
                    ]);
                }

                // Act
                $memberships = FeatureGroupMembership::query()
                    ->where('context_type', User::class)
                    ->where('context_id', $user->getKey())
                    ->with('context')
                    ->get();

                // Assert
                expect($memberships)->toHaveCount(3);

                foreach ($memberships as $membership) {
                    expect($membership->context)->toBeInstanceOf(User::class)
                        ->and($membership->context->getKey())->toBe($user->getKey())
                        ->and($membership->context->name)->toBe('Ivy Chen');
                }
            });

            test('eager loading multiple memberships with mixed context types', function (): void {
                // Arrange
                $user = User::query()->create([
                    'name' => 'Jack Ryan',
                    'email' => 'jack@example.com',
                ]);

                $organization = Organization::query()->create([
                    'name' => 'Ryan Enterprises',
                ]);

                FeatureGroupMembership::query()->create([
                    'group_name' => 'mixed-group-1',
                    'context_type' => User::class,
                    'context_id' => $user->getKey(),
                ]);

                FeatureGroupMembership::query()->create([
                    'group_name' => 'mixed-group-2',
                    'context_type' => Organization::class,
                    'context_id' => $organization->getKey(),
                ]);

                // Act
                $memberships = FeatureGroupMembership::query()
                    ->with('context')
                    ->whereIn('group_name', ['mixed-group-1', 'mixed-group-2'])
                    ->get();

                // Assert
                expect($memberships)->toHaveCount(2)
                    ->and($memberships->every(fn ($m) => $m->relationLoaded('context')))->toBeTrue();

                $userMembership = $memberships->firstWhere('context_type', User::class);
                $orgMembership = $memberships->firstWhere('context_type', Organization::class);

                expect($userMembership->context)->toBeInstanceOf(User::class)
                    ->and($orgMembership->context)->toBeInstanceOf(Organization::class);
            });
        });
    });
});
