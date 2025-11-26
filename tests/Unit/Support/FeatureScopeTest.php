<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\FeatureScope;

describe('FeatureScope', function (): void {
    describe('Construction', function (): void {
        test('creates scope with kind and scopes', function (): void {
            // Act
            $scope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => 5,
            ]);

            // Assert
            expect($scope->kind)->toBe('user');
            expect($scope->constraints)->toBe([
                'company_id' => 3,
                'org_id' => 5,
            ]);
        });

        test('allows null values in scopes', function (): void {
            // Act
            $scope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => null,
                'team_id' => null,
            ]);

            // Assert
            expect($scope->constraints)->toHaveKey('org_id');
            expect($scope->constraints['org_id'])->toBeNull();
        });
    });

    describe('Defined Scopes', function (): void {
        test('filters out null values', function (): void {
            // Arrange
            $scope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => 5,
                'team_id' => null,
                'user_id' => null,
            ]);

            // Act
            $defined = $scope->definedConstraints();

            // Assert
            expect($defined)->toBe([
                'company_id' => 3,
                'org_id' => 5,
            ]);
        });

        test('returns empty array when all values are null', function (): void {
            // Arrange
            $scope = new FeatureScope('user', [
                'company_id' => null,
                'org_id' => null,
            ]);

            // Act
            $defined = $scope->definedConstraints();

            // Assert
            expect($defined)->toBe([]);
        });

        test('returns all scopes when none are null', function (): void {
            // Arrange
            $scope = new FeatureScope('team', [
                'company_id' => 1,
                'division_id' => 2,
                'org_id' => 3,
            ]);

            // Act
            $defined = $scope->definedConstraints();

            // Assert
            expect($defined)->toHaveCount(3);
            expect($defined)->toBe([
                'company_id' => 1,
                'division_id' => 2,
                'org_id' => 3,
            ]);
        });
    });

    describe('Matching Logic', function (): void {
        test('matches when all defined target scopes match this scope', function (): void {
            // Arrange
            $contextScope = new FeatureScope('user', [
                'company_id' => 3,
                'division_id' => 4,
                'org_id' => 5,
                'team_id' => 7,
                'user_id' => 10,
            ]);

            $featureScope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => 5,
                'user_id' => null,  // Wildcard
            ]);

            // Act & Assert
            expect($contextScope->matches($featureScope))->toBeTrue();
        });

        test('does not match when kinds differ', function (): void {
            // Arrange
            $userScope = new FeatureScope('user', [
                'company_id' => 3,
            ]);

            $teamScope = new FeatureScope('team', [
                'company_id' => 3,
            ]);

            // Act & Assert
            expect($userScope->matches($teamScope))->toBeFalse();
        });

        test('does not match when scope value differs', function (): void {
            // Arrange
            $contextScope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => 5,
            ]);

            $featureScope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => 99,  // Different
            ]);

            // Act & Assert
            expect($contextScope->matches($featureScope))->toBeFalse();
        });

        test('does not match when target has key not in context', function (): void {
            // Arrange
            $contextScope = new FeatureScope('user', [
                'company_id' => 3,
            ]);

            $featureScope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => 5,  // Context doesn't have this key
            ]);

            // Act & Assert
            expect($contextScope->matches($featureScope))->toBeFalse();
        });

        test('matches with all wildcards', function (): void {
            // Arrange
            $contextScope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => 5,
            ]);

            $featureScope = new FeatureScope('user', [
                'company_id' => null,
                'org_id' => null,
            ]);

            // Act & Assert - All wildcards means empty definedConstraints, so matches
            expect($contextScope->matches($featureScope))->toBeTrue();
        });
    });

    describe('Serialization', function (): void {
        test('toArray returns kind and scopes', function (): void {
            // Arrange
            $scope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => null,
            ]);

            // Act
            $array = $scope->toArray();

            // Assert
            expect($array)->toBe([
                'kind' => 'user',
                'scopes' => [
                    'company_id' => 3,
                    'org_id' => null,
                ],
            ]);
        });

        test('fromArray reconstructs scope', function (): void {
            // Arrange
            $data = [
                'kind' => 'team',
                'scopes' => [
                    'company_id' => 5,
                    'team_id' => 10,
                ],
            ];

            // Act
            $scope = FeatureScope::fromArray($data);

            // Assert
            expect($scope->kind)->toBe('team');
            expect($scope->constraints)->toBe([
                'company_id' => 5,
                'team_id' => 10,
            ]);
        });

        test('roundtrip serialization', function (): void {
            // Arrange
            $original = new FeatureScope('user', [
                'company_id' => 3,
                'division_id' => 4,
                'org_id' => 5,
                'team_id' => null,
            ]);

            // Act
            $array = $original->toArray();
            $restored = FeatureScope::fromArray($array);

            // Assert
            expect($restored->kind)->toBe($original->kind);
            expect($restored->constraints)->toBe($original->constraints);
        });
    });

    describe('Cache Key Generation', function (): void {
        test('generates deterministic cache key', function (): void {
            // Arrange
            $scope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => 5,
            ]);

            // Act
            $key = $scope->toCacheKey();

            // Assert
            expect($key)->toBeString();
            expect($key)->toContain('user');
            expect($key)->toContain('company_id=3');
            expect($key)->toContain('org_id=5');
        });

        test('cache key is consistent with different property order', function (): void {
            // Arrange
            $scope1 = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => 5,
            ]);

            $scope2 = new FeatureScope('user', [
                'org_id' => 5,
                'company_id' => 3,
            ]);

            // Act
            $key1 = $scope1->toCacheKey();
            $key2 = $scope2->toCacheKey();

            // Assert - Keys should be identical despite different order
            expect($key1)->toBe($key2);
        });

        test('null values included in cache key', function (): void {
            // Arrange
            $scope = new FeatureScope('user', [
                'company_id' => 3,
                'org_id' => null,
            ]);

            // Act
            $key = $scope->toCacheKey();

            // Assert
            expect($key)->toContain('org_id=null');
        });

        test('different kinds produce different cache keys', function (): void {
            // Arrange
            $userScope = new FeatureScope('user', [
                'company_id' => 3,
            ]);

            $teamScope = new FeatureScope('team', [
                'company_id' => 3,
            ]);

            // Act & Assert
            expect($userScope->toCacheKey())->not->toBe($teamScope->toCacheKey());
        });
    });
});
