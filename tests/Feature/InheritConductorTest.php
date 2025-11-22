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
 * Inherit Conductor Test Suite
 *
 * Tests context scope inheritance where child contexts inherit features
 * from parent contexts with support for selective inheritance (only/except).
 */
describe('Inherit Conductor', function (): void {
    describe('Basic Inheritance', function (): void {
        test('child inherits all features from parent', function (): void {
            // Arrange
            $team = User::factory()->create(); // Parent context
            $user = User::factory()->create(); // Child context

            Toggl::for($team)->activate(['premium', 'analytics', 'api-access']);

            // Act
            Toggl::inherit($user)->from($team);

            // Assert - Child has all parent features
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('api-access'))->toBeTrue();
        });

        test('child inherits features with custom values', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($team)->activate('theme', 'corporate');
            Toggl::for($team)->activate('tier', 'enterprise');

            // Act
            Toggl::inherit($user)->from($team);

            // Assert - Values are inherited
            expect(Toggl::for($user)->value('theme'))->toBe('corporate');
            expect(Toggl::for($user)->value('tier'))->toBe('enterprise');
        });

        test('child inherits mixed boolean and valued features', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($team)->activate('premium'); // Boolean
            Toggl::for($team)->activate('role', 'admin'); // Value

            // Act
            Toggl::inherit($user)->from($team);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->value('role'))->toBe('admin');
        });

        test('does not inherit inactive parent features', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($team)->activate('active-feature');
            // 'inactive-feature' is not activated on parent

            // Act
            Toggl::inherit($user)->from($team);

            // Assert
            expect(Toggl::for($user)->active('active-feature'))->toBeTrue();
            expect(Toggl::for($user)->active('inactive-feature'))->toBeFalse();
        });
    });

    describe('Child Precedence', function (): void {
        test('child own settings take precedence over parent', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($team)->activate('theme', 'dark');
            Toggl::for($user)->activate('theme', 'light'); // Child has own value

            // Act
            Toggl::inherit($user)->from($team);

            // Assert - Child's value is preserved
            expect(Toggl::for($user)->value('theme'))->toBe('light');
        });

        test('child boolean feature blocks parent inheritance', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($team)->activate('premium');
            Toggl::for($user)->activate('premium'); // Child already has it

            // Act
            Toggl::inherit($user)->from($team);

            // Assert - No duplicate activation
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('partial child settings only block matching features', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($team)->activate(['f1', 'f2', 'f3']);
            Toggl::for($user)->activate('f2'); // Child only has f2

            // Act
            Toggl::inherit($user)->from($team);

            // Assert - f1 and f3 inherited, f2 kept
            expect(Toggl::for($user)->active('f1'))->toBeTrue();
            expect(Toggl::for($user)->active('f2'))->toBeTrue();
            expect(Toggl::for($user)->active('f3'))->toBeTrue();
        });
    });

    describe('Selective Inheritance with only()', function (): void {
        test('inherits only specified features', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($team)->activate(['f1', 'f2', 'f3', 'f4']);

            // Act - Only inherit f2 and f3
            Toggl::inherit($user)
                ->only(['f2', 'f3'])
                ->from($team);

            // Assert - Only f2 and f3 inherited
            expect(Toggl::for($user)->active('f1'))->toBeFalse();
            expect(Toggl::for($user)->active('f2'))->toBeTrue();
            expect(Toggl::for($user)->active('f3'))->toBeTrue();
            expect(Toggl::for($user)->active('f4'))->toBeFalse();
        });

        test('only() with values inherits specified features', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($team)->activate('theme', 'dark');
            Toggl::for($team)->activate('lang', 'es');
            Toggl::for($team)->activate('tier', 'pro');

            // Act
            Toggl::inherit($user)
                ->only(['theme', 'lang'])
                ->from($team);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
            expect(Toggl::for($user)->value('lang'))->toBe('es');
            expect(Toggl::for($user)->active('tier'))->toBeFalse();
        });
    });

    describe('Selective Inheritance with except()', function (): void {
        test('inherits all except specified features', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($team)->activate(['f1', 'f2', 'f3', 'f4']);

            // Act - Inherit all except f2 and f3
            Toggl::inherit($user)
                ->except(['f2', 'f3'])
                ->from($team);

            // Assert - f1 and f4 inherited, f2 and f3 not
            expect(Toggl::for($user)->active('f1'))->toBeTrue();
            expect(Toggl::for($user)->active('f2'))->toBeFalse();
            expect(Toggl::for($user)->active('f3'))->toBeFalse();
            expect(Toggl::for($user)->active('f4'))->toBeTrue();
        });

        test('except() with values excludes specified features', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($team)->activate('theme', 'dark');
            Toggl::for($team)->activate('lang', 'es');
            Toggl::for($team)->activate('tier', 'pro');

            // Act
            Toggl::inherit($user)
                ->except(['lang'])
                ->from($team);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
            expect(Toggl::for($user)->active('lang'))->toBeFalse();
            expect(Toggl::for($user)->value('tier'))->toBe('pro');
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('user inherits team features', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($team)->activate(['team-dashboard', 'shared-files', 'team-chat']);

            // Act
            Toggl::inherit($user)->from($team);

            // Assert
            expect(Toggl::for($user)->active('team-dashboard'))->toBeTrue();
            expect(Toggl::for($user)->active('shared-files'))->toBeTrue();
            expect(Toggl::for($user)->active('team-chat'))->toBeTrue();
        });

        test('user inherits organization tier with exceptions', function (): void {
            // Arrange
            $organization = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($organization)->activate(['premium', 'analytics', 'api-access', 'admin-panel']);

            // Act - Inherit all except admin-panel
            Toggl::inherit($user)
                ->except(['admin-panel'])
                ->from($organization);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('api-access'))->toBeTrue();
            expect(Toggl::for($user)->active('admin-panel'))->toBeFalse();
        });

        test('cascading inheritance maintains child overrides', function (): void {
            // Arrange - 3-tier: Organization -> Team -> User
            $org = User::factory()->create();
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($org)->activate('org-feature');
            Toggl::for($team)->activate('team-feature');
            Toggl::for($user)->activate('user-feature');

            // Act - User inherits from team, team inherits from org
            Toggl::inherit($team)->from($org);
            Toggl::inherit($user)->from($team);

            // Assert - User has all three
            expect(Toggl::for($user)->active('org-feature'))->toBeTrue();
            expect(Toggl::for($user)->active('team-feature'))->toBeTrue();
            expect(Toggl::for($user)->active('user-feature'))->toBeTrue();
        });

        test('selective feature inheritance for premium users', function (): void {
            // Arrange
            $premiumTemplate = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($premiumTemplate)->activate([
                'advanced-analytics',
                'priority-support',
                'api-access',
                'white-label',
            ]);

            // Act - Basic premium inherits specific features
            Toggl::inherit($user)
                ->only(['advanced-analytics', 'priority-support'])
                ->from($premiumTemplate);

            // Assert
            expect(Toggl::for($user)->active('advanced-analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('priority-support'))->toBeTrue();
            expect(Toggl::for($user)->active('api-access'))->toBeFalse();
            expect(Toggl::for($user)->active('white-label'))->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('conductor exposes child context', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $conductor = Toggl::inherit($user);

            // Assert
            expect($conductor->childContext())->toBe($user);
        });

        test('conductor exposes only features', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $conductor = Toggl::inherit($user)->only(['f1', 'f2']);

            // Assert
            expect($conductor->onlyFeatures())->toBe(['f1', 'f2']);
        });

        test('conductor exposes except features', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $conductor = Toggl::inherit($user)->except(['f3', 'f4']);

            // Assert
            expect($conductor->exceptFeatures())->toBe(['f3', 'f4']);
        });

        test('inheriting from empty parent does nothing', function (): void {
            // Arrange
            $team = User::factory()->create();
            $user = User::factory()->create();

            Toggl::for($user)->activate('existing-feature');

            // Act
            Toggl::inherit($user)->from($team);

            // Assert - Only existing feature remains
            expect(Toggl::for($user)->active('existing-feature'))->toBeTrue();
        });

        test('method chaining creates new instances', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $conductor1 = Toggl::inherit($user);
            $conductor2 = $conductor1->only(['f1']);
            $conductor3 = $conductor1->except(['f2']);

            // Assert
            expect($conductor1)->not->toBe($conductor2);
            expect($conductor1)->not->toBe($conductor3);
            expect($conductor2)->not->toBe($conductor3);
        });
    });
});
