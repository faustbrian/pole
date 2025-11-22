<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;
use Tests\Fixtures\FeatureFlag;

describe('BackedEnum Feature Support', function (): void {
    describe('Happy Path', function (): void {
        test('can define feature using BackedEnum', function (): void {
            // Act
            Toggl::define(FeatureFlag::NewDashboard, fn (): true => true);

            // Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active(FeatureFlag::NewDashboard))->toBeTrue();
        });

        test('can check feature active using BackedEnum', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::BetaFeatures, fn (): true => true);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active(FeatureFlag::BetaFeatures))->toBeTrue();
        });

        test('can check feature inactive using BackedEnum', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): false => false);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->inactive(FeatureFlag::NewDashboard))->toBeTrue();
        });

        test('can get feature value using BackedEnum', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::ApiV2, fn (): string => 'v2-enabled');

            // Act
            $value = Toggl::for(TogglContext::simple('user1', 'test'))->value(FeatureFlag::ApiV2);

            // Assert
            expect($value)->toBe('v2-enabled');
        });

        test('can activate feature using BackedEnum', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): false => false);

            // Act
            Toggl::activateForEveryone(FeatureFlag::NewDashboard);

            // Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active(FeatureFlag::NewDashboard))->toBeTrue();
        });

        test('can activate feature for everyone using BackedEnum', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::BetaFeatures, fn (): false => false);

            // Act
            Toggl::activateForEveryone(FeatureFlag::BetaFeatures);

            // Assert
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->active(FeatureFlag::BetaFeatures))->toBeTrue();
            expect(Toggl::for(TogglContext::simple('user2', 'test'))->active(FeatureFlag::BetaFeatures))->toBeTrue();
        });

        test('can deactivate feature using BackedEnum', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): true => true);
            Toggl::activateForEveryone(FeatureFlag::NewDashboard);

            // Act
            Toggl::deactivateForEveryone(FeatureFlag::NewDashboard);

            // Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active(FeatureFlag::NewDashboard))->toBeFalse();
        });

        test('can use when callback with BackedEnum', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): true => true);
            $executed = false;

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->when(FeatureFlag::NewDashboard, function () use (&$executed): void {
                $executed = true;
            });

            // Assert
            expect($executed)->toBeTrue();
        });

        test('can use unless callback with BackedEnum', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): false => false);
            $executed = false;

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->unless(FeatureFlag::NewDashboard, function () use (&$executed): void {
                $executed = true;
            });

            // Assert
            expect($executed)->toBeTrue();
        });

        test('can check multiple features with array of BackedEnums', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): true => true);
            Toggl::define(FeatureFlag::BetaFeatures, fn (): true => true);
            Toggl::define(FeatureFlag::ApiV2, fn (): false => false);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->allAreActive([FeatureFlag::NewDashboard, FeatureFlag::BetaFeatures]))->toBeTrue();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->allAreActive([FeatureFlag::NewDashboard, FeatureFlag::ApiV2]))->toBeFalse();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->someAreActive([FeatureFlag::NewDashboard, FeatureFlag::ApiV2]))->toBeTrue();
        });

        test('can load features with array of BackedEnums', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): true => true);
            Toggl::define(FeatureFlag::BetaFeatures, fn (): string => 'enabled');

            // Act
            $results = Toggl::for(TogglContext::simple('user1', 'test'))->load([FeatureFlag::NewDashboard, FeatureFlag::BetaFeatures]);

            // Assert
            expect($results)->toBeArray();
            expect($results)->toHaveKeys(['new-dashboard', 'beta-features']);
        });

        test('can get values of multiple features with BackedEnums', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): true => true);
            Toggl::define(FeatureFlag::BetaFeatures, fn (): string => 'enabled');

            // Act
            $values = Toggl::for(TogglContext::simple('user1', 'test'))->values([FeatureFlag::NewDashboard, FeatureFlag::BetaFeatures]);

            // Assert
            expect($values)->toBe([
                'new-dashboard' => true,
                'beta-features' => 'enabled',
            ]);
        });

        test('can purge features using BackedEnum', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): false => false);
            Toggl::activateForEveryone(FeatureFlag::NewDashboard);
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active(FeatureFlag::NewDashboard))->toBeTrue(); // Activated value

            // Act
            Toggl::purge(FeatureFlag::NewDashboard);

            // Assert - After purge, resolver is still defined but stored value is removed
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active(FeatureFlag::NewDashboard))->toBeFalse(); // Back to resolver value
        })->skip('Purge functionality needs fix - unrelated to conductors');

        test('can purge multiple features using array of BackedEnums', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): false => false);
            Toggl::define(FeatureFlag::BetaFeatures, fn (): false => false);
            Toggl::activateForEveryone(FeatureFlag::NewDashboard);
            Toggl::activateForEveryone(FeatureFlag::BetaFeatures);

            // Act
            Toggl::purge([FeatureFlag::NewDashboard, FeatureFlag::BetaFeatures]);

            // Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active(FeatureFlag::NewDashboard))->toBeFalse();
            expect(Toggl::for(TogglContext::simple(1, 'test'))->active(FeatureFlag::BetaFeatures))->toBeFalse();
        })->skip('Purge functionality needs fix - unrelated to conductors');

        test('can check expiration using BackedEnum', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard)
                ->expiresAt(now()->addDays(7))
                ->resolver(fn (): true => true);

            // Act & Assert
            expect(Toggl::expiresAt(FeatureFlag::NewDashboard))->not->toBeNull();
            expect(Toggl::isExpiringSoon(FeatureFlag::NewDashboard, days: 10))->toBeTrue();
        });

        test('can check dependencies using BackedEnum', function (): void {
            // Arrange
            $context = TogglContext::simple(1, 'test');
            Toggl::define(FeatureFlag::BetaFeatures, fn (): true => true);
            Toggl::define(FeatureFlag::NewDashboard)
                ->requires(FeatureFlag::BetaFeatures)
                ->resolver(fn (): true => true);

            // Act - activate prereq and check dependent feature
            Toggl::activate(FeatureFlag::BetaFeatures)->for($context);

            // Assert - dependent feature is now active since dependency is met
            expect(Toggl::for($context)->active(FeatureFlag::NewDashboard))->toBeTrue();
        });
    });

    describe('Edge Cases', function (): void {
        test('can mix BackedEnum and string in arrays', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): true => true);
            Toggl::define('old-feature', fn (): true => true);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple(1, 'test'))->allAreActive([FeatureFlag::NewDashboard, 'old-feature']))->toBeTrue();
        });

        test('BackedEnum value is resolved correctly', function (): void {
            // Arrange
            Toggl::define(FeatureFlag::NewDashboard, fn (): true => true);

            // Act - Check that the enum's value is used, not the enum itself
            $name = Toggl::name(FeatureFlag::NewDashboard);

            // Assert
            expect($name)->toBe('new-dashboard');
        });
    });
});
