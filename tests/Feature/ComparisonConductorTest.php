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
 * Comparison Conductor Test Suite
 *
 * Tests comparing feature states between contexts to identify differences,
 * unique features, and value changes.
 */
describe('Comparison Conductor', function (): void {
    describe('Basic Comparison', function (): void {
        test('compares two contexts with different features', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            Toggl::for($user1)->activate(['premium', 'analytics']);
            Toggl::for($user2)->activate(['premium', 'export']);

            // Act
            $diff = Toggl::compare($user1, $user2)->get();

            // Assert
            expect($diff['only_context1'])->toHaveKey('analytics');
            expect($diff['only_context2'])->toHaveKey('export');
            expect($diff['different_values'])->toBeEmpty();
        });

        test('compares two contexts with different values', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            Toggl::for($user1)->activate('theme', 'dark');
            Toggl::for($user2)->activate('theme', 'light');

            // Act
            $diff = Toggl::compare($user1, $user2)->get();

            // Assert
            expect($diff['only_context1'])->toBeEmpty();
            expect($diff['only_context2'])->toBeEmpty();
            expect($diff['different_values'])->toHaveKey('theme');
            expect($diff['different_values']['theme']['context1'])->toBe('dark');
            expect($diff['different_values']['theme']['context2'])->toBe('light');
        });

        test('compares two identical contexts', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            Toggl::for($user1)->activate(['premium', 'analytics']);
            Toggl::for($user2)->activate(['premium', 'analytics']);

            // Act
            $diff = Toggl::compare($user1, $user2)->get();

            // Assert
            expect($diff['only_context1'])->toBeEmpty();
            expect($diff['only_context2'])->toBeEmpty();
            expect($diff['different_values'])->toBeEmpty();
        });

        test('compares context with empty context', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            Toggl::for($user1)->activate(['premium', 'analytics']);

            // Act
            $diff = Toggl::compare($user1, $user2)->get();

            // Assert
            expect($diff['only_context1'])->toHaveKeys(['premium', 'analytics']);
            expect($diff['only_context2'])->toBeEmpty();
            expect($diff['different_values'])->toBeEmpty();
        });
    });

    describe('Against Method', function (): void {
        test('uses against() to compare with deferred context', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            Toggl::for($user1)->activate('premium');
            Toggl::for($user2)->activate('basic');

            // Act
            $diff = Toggl::compare($user1)->against($user2);

            // Assert
            expect($diff['only_context1'])->toHaveKey('premium');
            expect($diff['only_context2'])->toHaveKey('basic');
        });

        test('against() overrides second context parameter', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();

            Toggl::for($user1)->activate('feature1');
            Toggl::for($user2)->activate('feature2');
            Toggl::for($user3)->activate('feature3');

            // Act - against() should compare with user3, not user2
            $diff = Toggl::compare($user1)->against($user3);

            // Assert
            expect($diff['only_context1'])->toHaveKey('feature1');
            expect($diff['only_context2'])->toHaveKey('feature3');
            expect($diff['only_context1'])->not->toHaveKey('feature2');
        });
    });

    describe('Complex Scenarios', function (): void {
        test('compares contexts with multiple value differences', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            Toggl::for($user1)->activate('theme', 'dark');
            Toggl::for($user1)->activate('language', 'en');
            Toggl::for($user1)->activate('timezone', 'UTC');

            Toggl::for($user2)->activate('theme', 'light');
            Toggl::for($user2)->activate('language', 'en');
            Toggl::for($user2)->activate('timezone', 'PST');

            // Act
            $diff = Toggl::compare($user1, $user2)->get();

            // Assert
            expect($diff['different_values'])->toHaveKeys(['theme', 'timezone']);
            expect($diff['different_values'])->not->toHaveKey('language');
        });

        test('compares contexts with mixed differences', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            Toggl::for($user1)->activate('premium');
            Toggl::for($user1)->activate('theme', 'dark');
            Toggl::for($user1)->activate('analytics');

            Toggl::for($user2)->activate('premium');
            Toggl::for($user2)->activate('theme', 'light');
            Toggl::for($user2)->activate('export');

            // Act
            $diff = Toggl::compare($user1, $user2)->get();

            // Assert
            // Only in user1
            expect($diff['only_context1'])->toHaveKey('analytics');
            // Only in user2
            expect($diff['only_context2'])->toHaveKey('export');
            // Different values
            expect($diff['different_values'])->toHaveKey('theme');
            expect($diff['different_values']['theme']['context1'])->toBe('dark');
            expect($diff['different_values']['theme']['context2'])->toBe('light');
        });

        test('compares boolean vs value features', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            Toggl::for($user1)->activate('premium'); // true
            Toggl::for($user2)->activate('premium', 'gold'); // 'gold'

            // Act
            $diff = Toggl::compare($user1, $user2)->get();

            // Assert
            expect($diff['different_values'])->toHaveKey('premium');
            expect($diff['different_values']['premium']['context1'])->toBe(true);
            expect($diff['different_values']['premium']['context2'])->toBe('gold');
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('compare user against team baseline', function (): void {
            // Arrange
            $team = User::factory()->create(); // Using User as team for simplicity
            $user = User::factory()->create();

            // Team baseline features
            Toggl::for($team)->activate('basic-analytics');
            Toggl::for($team)->activate('standard-support');
            Toggl::for($team)->activate('max-storage', '10GB');

            // User has customized some features
            Toggl::for($user)->activate('basic-analytics');
            Toggl::for($user)->activate('standard-support');
            Toggl::for($user)->activate('max-storage', '50GB');
            Toggl::for($user)->activate('premium-export');

            // Act
            $diff = Toggl::compare($user)->against($team);

            // Assert - User has extra feature
            expect($diff['only_context1'])->toHaveKey('premium-export');
            // Assert - Different storage value
            expect($diff['different_values'])->toHaveKey('max-storage');
            expect($diff['different_values']['max-storage']['context1'])->toBe('50GB');
            expect($diff['different_values']['max-storage']['context2'])->toBe('10GB');
        });

        test('audit feature drift between environments', function (): void {
            // Arrange
            $production = User::factory()->create();
            $staging = User::factory()->create();

            Toggl::for($production)->activate('legacy-api');
            Toggl::for($production)->activate('old-dashboard');
            Toggl::for($production)->activate('legacy-mode');

            Toggl::for($staging)->activate('new-ui');
            Toggl::for($staging)->activate('experimental-api');
            Toggl::for($staging)->activate('debug-mode');

            // Act
            $diff = Toggl::compare($production, $staging)->get();

            // Assert - Features unique to each environment
            expect($diff['only_context1'])->toHaveKeys(['legacy-api', 'old-dashboard', 'legacy-mode']);
            expect($diff['only_context2'])->toHaveKeys(['new-ui', 'experimental-api', 'debug-mode']);
            expect($diff['different_values'])->toBeEmpty();
        });

        test('compare subscription tiers', function (): void {
            // Arrange
            $basicUser = User::factory()->create();
            $premiumUser = User::factory()->create();

            Toggl::for($basicUser)->activate('max-projects', 5);
            Toggl::for($basicUser)->activate('storage', '1GB');
            Toggl::for($basicUser)->activate('support', 'email');

            Toggl::for($premiumUser)->activate('max-projects', 50);
            Toggl::for($premiumUser)->activate('storage', '100GB');
            Toggl::for($premiumUser)->activate('support', 'priority');
            Toggl::for($premiumUser)->activate('api-access');
            Toggl::for($premiumUser)->activate('white-label');

            // Act
            $diff = Toggl::compare($basicUser, $premiumUser)->get();

            // Assert - Premium features
            expect($diff['only_context2'])->toHaveKeys(['api-access', 'white-label']);
            // Assert - Different limits
            expect($diff['different_values'])->toHaveKeys(['max-projects', 'storage', 'support']);
        });

        test('track feature rollout progress', function (): void {
            // Arrange
            $targetState = User::factory()->create();
            $currentState = User::factory()->create();

            // Target: all users should have these
            Toggl::for($targetState)->activate(['new-dashboard', 'improved-search', 'mobile-app']);

            // Current: partially rolled out
            Toggl::for($currentState)->activate(['new-dashboard', 'improved-search']);

            // Act
            $diff = Toggl::compare($currentState, $targetState)->get();

            // Assert - Still need to roll out
            expect($diff['only_context2'])->toHaveKey('mobile-app');
            // Assert - Already deployed
            expect($diff['only_context1'])->toBeEmpty();
            expect($diff['different_values'])->toBeEmpty();
        });
    });

    describe('Edge Cases', function (): void {
        test('throws exception when calling get() without second context', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act & Assert
            expect(fn () => Toggl::compare($user)->get())
                ->toThrow(LogicException::class, 'Second context not provided');
        });

        test('comparison with same context', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['premium', 'analytics']);

            // Act
            $diff = Toggl::compare($user, $user)->get();

            // Assert - No differences
            expect($diff['only_context1'])->toBeEmpty();
            expect($diff['only_context2'])->toBeEmpty();
            expect($diff['different_values'])->toBeEmpty();
        });

        test('comparison result structure is always consistent', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act - Empty contexts
            $diff = Toggl::compare($user1, $user2)->get();

            // Assert - All keys present even when empty
            expect($diff)->toHaveKeys(['only_context1', 'only_context2', 'different_values']);
            expect($diff['only_context1'])->toBeArray();
            expect($diff['only_context2'])->toBeArray();
            expect($diff['different_values'])->toBeArray();
        });

        test('filters out explicitly deactivated features from comparison', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Activate then deactivate features
            Toggl::for($user1)->activate('premium');
            Toggl::for($user1)->deactivate('premium'); // Sets to false
            Toggl::for($user1)->activate('analytics');

            Toggl::for($user2)->activate('export');
            Toggl::for($user2)->deactivate('dashboard'); // Sets to false

            // Act
            $diff = Toggl::compare($user1, $user2)->get();

            // Assert - Deactivated features should not appear in diff
            expect($diff['only_context1'])->toHaveKey('analytics');
            expect($diff['only_context1'])->not->toHaveKey('premium');
            expect($diff['only_context2'])->toHaveKey('export');
            expect($diff['only_context2'])->not->toHaveKey('dashboard');
            expect($diff['different_values'])->toBeEmpty();
        });
    });
});
