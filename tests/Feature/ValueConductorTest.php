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
 * Value Conductor Test Suite
 *
 * Tests the value conductor pattern: Toggl::activate('theme')->withValue('dark')->for($user)
 * This provides explicit value setting in a fluent chain, making the intent clearer
 * than the shorthand activate('theme', 'dark')->for($user).
 */
describe('Value Conductor', function (): void {
    describe('String Values', function (): void {
        test('can activate with string value using withValue', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate('theme')->withValue('dark')->for($user);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
        });

        test('withValue overrides default true value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Without withValue, default is true
            Toggl::activate('setting')->for($user);

            // Assert
            expect(Toggl::for($user)->value('setting'))->toBe(true);

            // Act - With withValue
            Toggl::activate('config')->withValue('custom')->for($user);

            // Assert
            expect(Toggl::for($user)->value('config'))->toBe('custom');
        });

        test('can chain multiple contexts with same value', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $user3 = User::factory()->create();

            // Act
            Toggl::activate('language')->withValue('es')->for([$user1, $user2, $user3]);

            // Assert
            expect(Toggl::for($user1)->value('language'))->toBe('es');
            expect(Toggl::for($user2)->value('language'))->toBe('es');
            expect(Toggl::for($user3)->value('language'))->toBe('es');
        });
    });

    describe('Complex Values', function (): void {
        test('can activate with array value', function (): void {
            // Arrange
            $user = User::factory()->create();
            $config = [
                'email' => true,
                'sms' => false,
                'push' => true,
            ];

            // Act
            Toggl::activate('notifications')->withValue($config)->for($user);

            // Assert
            expect(Toggl::for($user)->value('notifications'))->toBe($config);
        });

        test('can activate with numeric value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate('rate-limit')->withValue(1_000)->for($user);

            // Assert
            expect(Toggl::for($user)->value('rate-limit'))->toBe(1_000);
        });

        test('can activate with null value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate('optional-setting')->withValue(null)->for($user);

            // Assert - Null is stored but feature is still considered "active" (has a stored value)
            expect(Toggl::for($user)->value('optional-setting'))->toBeNull();
            expect(Toggl::for($user)->active('optional-setting'))->toBeTrue();
        });

        test('can activate with boolean false', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate('disabled-feature')->withValue(false)->for($user);

            // Assert
            expect(Toggl::for($user)->value('disabled-feature'))->toBe(false);
            expect(Toggl::for($user)->active('disabled-feature'))->toBeFalse();
        });
    });

    describe('Multiple Features', function (): void {
        test('can activate multiple features with same value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate(['feat-1', 'feat-2', 'feat-3'])->withValue('shared')->for($user);

            // Assert
            expect(Toggl::for($user)->value('feat-1'))->toBe('shared');
            expect(Toggl::for($user)->value('feat-2'))->toBe('shared');
            expect(Toggl::for($user)->value('feat-3'))->toBe('shared');
        });

        test('can activate multiple features with array value', function (): void {
            // Arrange
            $user = User::factory()->create();
            $config = ['key' => 'value'];

            // Act
            Toggl::activate(['setting-1', 'setting-2'])->withValue($config)->for($user);

            // Assert
            expect(Toggl::for($user)->value('setting-1'))->toBe($config);
            expect(Toggl::for($user)->value('setting-2'))->toBe($config);
        });
    });

    describe('BackedEnum Support', function (): void {
        test('can activate enum feature with value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate(FeatureFlag::ApiV2)->withValue('v3')->for($user);

            // Assert
            expect(Toggl::for($user)->value(FeatureFlag::ApiV2))->toBe('v3');
        });

        test('can activate multiple enum features with value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate([
                FeatureFlag::NewDashboard,
                FeatureFlag::BetaFeatures,
            ])->withValue('enabled')->for($user);

            // Assert
            expect(Toggl::for($user)->value(FeatureFlag::NewDashboard))->toBe('enabled');
            expect(Toggl::for($user)->value(FeatureFlag::BetaFeatures))->toBe('enabled');
        });
    });

    describe('Immutability', function (): void {
        test('withValue returns new instance', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Create conductor, then modify with withValue
            $conductor1 = Toggl::activate('feature-a');
            $conductor2 = $conductor1->withValue('custom');

            // Execute both
            $conductor1->for($user);

            $user2 = User::factory()->create();
            $conductor2->for($user2);

            // Assert - conductor1 used default true, conductor2 used 'custom'
            expect(Toggl::for($user)->value('feature-a'))->toBe(true);
            expect(Toggl::for($user2)->value('feature-a'))->toBe('custom');
        });

        test('chaining withValue multiple times uses last value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate('setting')
                ->withValue('first')
                ->withValue('second')
                ->withValue('final')
                ->for($user);

            // Assert
            expect(Toggl::for($user)->value('setting'))->toBe('final');
        });
    });

    describe('Edge Cases', function (): void {
        test('activating without withValue defaults to true', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate('default-feature')->for($user);

            // Assert
            expect(Toggl::for($user)->value('default-feature'))->toBe(true);
        });

        test('withValue works with empty string', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate('empty')->withValue('')->for($user);

            // Assert
            expect(Toggl::for($user)->value('empty'))->toBe('');
        });

        test('withValue works with zero', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate('zero')->withValue(0)->for($user);

            // Assert
            expect(Toggl::for($user)->value('zero'))->toBe(0);
        });
    });

    describe('Integration', function (): void {
        test('withValue pattern is the only way to set custom values', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Must use withValue for custom values
            Toggl::activate('theme')->withValue('dark')->for($user);

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
        });

        test('multiple features can share same custom value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::activate(['setting-a', 'setting-b', 'setting-c'])
                ->withValue('shared-value')
                ->for($user);

            // Assert - All have same value
            expect(Toggl::for($user)->value('setting-a'))->toBe('shared-value');
            expect(Toggl::for($user)->value('setting-b'))->toBe('shared-value');
            expect(Toggl::for($user)->value('setting-c'))->toBe('shared-value');
        });
    });
});
