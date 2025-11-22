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
 * Copy Conductor Test Suite
 *
 * Tests copying features from one context to another with support for
 * selective copying (only/except).
 */
describe('Copy Conductor', function (): void {
    describe('Basic Copy Operations', function (): void {
        test('copies all features from source to target', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate(['premium', 'analytics', 'api-access']);

            // Act
            Toggl::from($source)->copyTo($target);

            // Assert - Target has all source features
            expect(Toggl::for($target)->active('premium'))->toBeTrue();
            expect(Toggl::for($target)->active('analytics'))->toBeTrue();
            expect(Toggl::for($target)->active('api-access'))->toBeTrue();
        });

        test('copies features with custom values', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate('theme', 'dark');
            Toggl::for($source)->activate('language', 'es');

            // Act
            Toggl::from($source)->copyTo($target);

            // Assert - Values are copied
            expect(Toggl::for($target)->value('theme'))->toBe('dark');
            expect(Toggl::for($target)->value('language'))->toBe('es');
        });

        test('copies mixed boolean and valued features', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate('premium'); // Boolean
            Toggl::for($source)->activate('tier', 'professional'); // Value

            // Act
            Toggl::from($source)->copyTo($target);

            // Assert
            expect(Toggl::for($target)->active('premium'))->toBeTrue();
            expect(Toggl::for($target)->value('tier'))->toBe('professional');
        });

        test('does not copy inactive features', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate('active-feature');
            // 'inactive-feature' is not activated

            // Act
            Toggl::from($source)->copyTo($target);

            // Assert
            expect(Toggl::for($target)->active('active-feature'))->toBeTrue();
            expect(Toggl::for($target)->active('inactive-feature'))->toBeFalse();
        });
    });

    describe('Selective Copy with only()', function (): void {
        test('copies only specified features', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate(['f1', 'f2', 'f3', 'f4']);

            // Act - Only copy f2 and f3
            Toggl::from($source)
                ->only(['f2', 'f3'])
                ->copyTo($target);

            // Assert - Only f2 and f3 copied
            expect(Toggl::for($target)->active('f1'))->toBeFalse();
            expect(Toggl::for($target)->active('f2'))->toBeTrue();
            expect(Toggl::for($target)->active('f3'))->toBeTrue();
            expect(Toggl::for($target)->active('f4'))->toBeFalse();
        });

        test('only() with single feature', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate(['premium', 'analytics', 'debug']);

            // Act
            Toggl::from($source)
                ->only(['premium'])
                ->copyTo($target);

            // Assert
            expect(Toggl::for($target)->active('premium'))->toBeTrue();
            expect(Toggl::for($target)->active('analytics'))->toBeFalse();
            expect(Toggl::for($target)->active('debug'))->toBeFalse();
        });

        test('only() with non-existent features is no-op', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate(['f1', 'f2']);

            // Act - Only copy features that don't exist
            Toggl::from($source)
                ->only(['f3', 'f4'])
                ->copyTo($target);

            // Assert - Nothing copied
            expect(Toggl::for($target)->active('f1'))->toBeFalse();
            expect(Toggl::for($target)->active('f2'))->toBeFalse();
        });
    });

    describe('Filtered Copy with except()', function (): void {
        test('copies all except specified features', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate(['f1', 'f2', 'f3', 'f4']);

            // Act - Copy all except f2 and f3
            Toggl::from($source)
                ->except(['f2', 'f3'])
                ->copyTo($target);

            // Assert - f1 and f4 copied, f2 and f3 excluded
            expect(Toggl::for($target)->active('f1'))->toBeTrue();
            expect(Toggl::for($target)->active('f2'))->toBeFalse();
            expect(Toggl::for($target)->active('f3'))->toBeFalse();
            expect(Toggl::for($target)->active('f4'))->toBeTrue();
        });

        test('except() with single feature', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate(['premium', 'debug', 'testing']);

            // Act - Exclude debug mode
            Toggl::from($source)
                ->except(['debug'])
                ->copyTo($target);

            // Assert
            expect(Toggl::for($target)->active('premium'))->toBeTrue();
            expect(Toggl::for($target)->active('debug'))->toBeFalse();
            expect(Toggl::for($target)->active('testing'))->toBeTrue();
        });

        test('except() with non-existent features copies everything', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate(['f1', 'f2']);

            // Act - Exclude features that don't exist
            Toggl::from($source)
                ->except(['f3', 'f4'])
                ->copyTo($target);

            // Assert - All copied
            expect(Toggl::for($target)->active('f1'))->toBeTrue();
            expect(Toggl::for($target)->active('f2'))->toBeTrue();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('copy admin template to new admin user', function (): void {
            // Arrange - Template admin with all permissions
            $adminTemplate = User::factory()->create();
            Toggl::for($adminTemplate)->activate([
                'admin-panel',
                'user-management',
                'system-settings',
                'audit-logs',
                'reports',
            ]);

            $newAdmin = User::factory()->create();

            // Act - Copy all admin features
            Toggl::from($adminTemplate)->copyTo($newAdmin);

            // Assert
            expect(Toggl::for($newAdmin)->active('admin-panel'))->toBeTrue();
            expect(Toggl::for($newAdmin)->active('user-management'))->toBeTrue();
            expect(Toggl::for($newAdmin)->active('system-settings'))->toBeTrue();
            expect(Toggl::for($newAdmin)->active('audit-logs'))->toBeTrue();
            expect(Toggl::for($newAdmin)->active('reports'))->toBeTrue();
        });

        test('migrate user to new account excluding temporary features', function (): void {
            // Arrange - Old account with mix of permanent and temporary features
            $oldAccount = User::factory()->create();
            Toggl::for($oldAccount)->activate([
                'premium',
                'analytics',
                'trial-banner', // Temporary
                'onboarding-wizard', // Temporary
                'api-access',
            ]);

            $newAccount = User::factory()->create();

            // Act - Copy excluding temporary features
            Toggl::from($oldAccount)
                ->except(['trial-banner', 'onboarding-wizard'])
                ->copyTo($newAccount);

            // Assert
            expect(Toggl::for($newAccount)->active('premium'))->toBeTrue();
            expect(Toggl::for($newAccount)->active('analytics'))->toBeTrue();
            expect(Toggl::for($newAccount)->active('trial-banner'))->toBeFalse();
            expect(Toggl::for($newAccount)->active('onboarding-wizard'))->toBeFalse();
            expect(Toggl::for($newAccount)->active('api-access'))->toBeTrue();
        });

        test('copy only production-safe features to test user', function (): void {
            // Arrange
            $prodUser = User::factory()->create();
            Toggl::for($prodUser)->activate([
                'premium-ui',
                'advanced-search',
                'debug-mode', // Not safe for copy
                'experimental-feature', // Not safe for copy
                'export',
            ]);

            $testUser = User::factory()->create();

            // Act - Only copy production-safe features
            Toggl::from($prodUser)
                ->only(['premium-ui', 'advanced-search', 'export'])
                ->copyTo($testUser);

            // Assert
            expect(Toggl::for($testUser)->active('premium-ui'))->toBeTrue();
            expect(Toggl::for($testUser)->active('advanced-search'))->toBeTrue();
            expect(Toggl::for($testUser)->active('debug-mode'))->toBeFalse();
            expect(Toggl::for($testUser)->active('experimental-feature'))->toBeFalse();
            expect(Toggl::for($testUser)->active('export'))->toBeTrue();
        });
    });

    describe('Edge Cases', function (): void {
        test('copy from context with no features is no-op', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();
            // Source has no features

            // Act
            Toggl::from($source)->copyTo($target);

            // Assert - Target still has no features
            expect(Toggl::for($target)->all())->toBeEmpty();
        });

        test('copy to already-featured target overwrites', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate('theme', 'dark');
            Toggl::for($target)->activate('theme', 'light');

            // Act - Copy overwrites
            Toggl::from($source)->copyTo($target);

            // Assert - Source value wins
            expect(Toggl::for($target)->value('theme'))->toBe('dark');
        });

        test('copy from and to same context is safe', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['f1', 'f2']);

            // Act - Copy to self
            Toggl::from($user)->copyTo($user);

            // Assert - Still has same features
            expect(Toggl::for($user)->active('f1'))->toBeTrue();
            expect(Toggl::for($user)->active('f2'))->toBeTrue();
        });

        test('conductor exposes metadata', function (): void {
            // Arrange
            $source = User::factory()->create();

            // Act
            $conductor = Toggl::from($source)
                ->only(['f1', 'f2']);

            // Assert
            expect($conductor->sourceContext())->toBe($source);
            expect($conductor->onlyFeatures())->toBe(['f1', 'f2']);
            expect($conductor->exceptFeatures())->toBeNull();
        });

        test('except() overwrites only()', function (): void {
            // Arrange
            $source = User::factory()->create();
            $target = User::factory()->create();

            Toggl::for($source)->activate(['f1', 'f2', 'f3', 'f4']);

            // Act - Last wins (except overwrites only)
            Toggl::from($source)
                ->only(['f1', 'f2'])
                ->except(['f3'])
                ->copyTo($target);

            // Assert - except() takes precedence
            expect(Toggl::for($target)->active('f1'))->toBeTrue();
            expect(Toggl::for($target)->active('f2'))->toBeTrue();
            expect(Toggl::for($target)->active('f3'))->toBeFalse();
            expect(Toggl::for($target)->active('f4'))->toBeTrue();
        });
    });
});
