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
 * Metadata Conductor Test Suite
 *
 * Tests managing feature metadata with fluent API.
 */
describe('Metadata Conductor', function (): void {
    describe('Setting Metadata', function (): void {
        test('sets metadata with with()', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::metadata('premium')
                ->with(['plan' => 'monthly', 'price' => 9.99])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe(['plan' => 'monthly', 'price' => 9.99]);
        });

        test('replaces existing metadata with with()', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', ['old' => 'data']);

            // Act
            Toggl::metadata('premium')
                ->with(['plan' => 'monthly', 'price' => 9.99])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe(['plan' => 'monthly', 'price' => 9.99]);
            expect($metadata)->not->toHaveKey('old');
        });

        test('sets empty array as metadata', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', ['existing' => 'data']);

            // Act
            Toggl::metadata('premium')
                ->with([])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe([]);
        });

        test('sets nested metadata', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::metadata('premium')
                ->with([
                    'plan' => ['type' => 'monthly', 'price' => 9.99],
                    'features' => ['analytics', 'export'],
                ])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata['plan'])->toBe(['type' => 'monthly', 'price' => 9.99]);
            expect($metadata['features'])->toBe(['analytics', 'export']);
        });
    });

    describe('Merging Metadata', function (): void {
        test('merges metadata with existing', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', ['plan' => 'monthly', 'price' => 9.99]);

            // Act
            Toggl::metadata('premium')
                ->merge(['upgraded_at' => '2024-01-01', 'previous_plan' => 'basic'])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe([
                'plan' => 'monthly',
                'price' => 9.99,
                'upgraded_at' => '2024-01-01',
                'previous_plan' => 'basic',
            ]);
        });

        test('merge overwrites existing keys', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', ['plan' => 'monthly', 'price' => 9.99]);

            // Act
            Toggl::metadata('premium')
                ->merge(['price' => 19.99, 'upgraded' => true])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata['price'])->toBe(19.99);
            expect($metadata['upgraded'])->toBeTrue();
        });

        test('merge works with non-array existing value', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', 'simple-value');

            // Act
            Toggl::metadata('premium')
                ->merge(['plan' => 'monthly'])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe(['plan' => 'monthly']);
        });

        test('merge works when feature not yet active', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::metadata('premium')
                ->merge(['plan' => 'monthly'])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe(['plan' => 'monthly']);
        });
    });

    describe('Forgetting Metadata Keys', function (): void {
        test('forgets specific keys', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', [
                'plan' => 'monthly',
                'price' => 9.99,
                'trial_ends' => '2024-01-01',
            ]);

            // Act
            Toggl::metadata('premium')
                ->forget(['trial_ends'])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe(['plan' => 'monthly', 'price' => 9.99]);
            expect($metadata)->not->toHaveKey('trial_ends');
        });

        test('forgets multiple keys', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', [
                'plan' => 'monthly',
                'price' => 9.99,
                'trial_ends' => '2024-01-01',
                'upgraded_at' => '2024-01-02',
            ]);

            // Act
            Toggl::metadata('premium')
                ->forget(['trial_ends', 'upgraded_at'])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe(['plan' => 'monthly', 'price' => 9.99]);
        });

        test('forget ignores non-existent keys', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', ['plan' => 'monthly']);

            // Act
            Toggl::metadata('premium')
                ->forget(['non_existent', 'also_missing'])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe(['plan' => 'monthly']);
        });

        test('forget works with non-array existing value', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', 'simple-value');

            // Act
            Toggl::metadata('premium')
                ->forget(['some_key'])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe([]);
        });
    });

    describe('Clearing Metadata', function (): void {
        test('clears all metadata', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', [
                'plan' => 'monthly',
                'price' => 9.99,
                'trial_ends' => '2024-01-01',
            ]);

            // Act
            Toggl::metadata('premium')
                ->clear()
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe([]);
        });

        test('clear works on already empty metadata', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', []);

            // Act
            Toggl::metadata('premium')
                ->clear()
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe([]);
        });

        test('clear works on non-array value', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', 'simple-value');

            // Act
            Toggl::metadata('premium')
                ->clear()
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->toBe([]);
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('subscription metadata lifecycle', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Initial subscription
            Toggl::metadata('premium')
                ->with([
                    'plan' => 'monthly',
                    'price' => 9.99,
                    'started_at' => '2024-01-01',
                ])
                ->for($user);

            // Assert - Initial state
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata['plan'])->toBe('monthly');

            // Act - Upgrade to yearly
            Toggl::metadata('premium')
                ->merge([
                    'plan' => 'yearly',
                    'price' => 99.99,
                    'upgraded_at' => '2024-06-01',
                ])
                ->for($user);

            // Assert - After upgrade
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata['plan'])->toBe('yearly');
            expect($metadata['price'])->toBe(99.99);
            expect($metadata['started_at'])->toBe('2024-01-01');

            // Act - Clean up temporary fields
            Toggl::metadata('premium')
                ->forget(['upgraded_at'])
                ->for($user);

            // Assert - After cleanup
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->not->toHaveKey('upgraded_at');
        });

        test('trial period management', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Start trial
            Toggl::metadata('premium')
                ->with([
                    'trial' => true,
                    'trial_ends' => '2024-01-15',
                    'plan' => 'monthly',
                ])
                ->for($user);

            // Assert - Trial active
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata['trial'])->toBeTrue();

            // Act - Convert to paid
            Toggl::metadata('premium')
                ->forget(['trial', 'trial_ends'])
                ->for($user);

            Toggl::metadata('premium')
                ->merge(['paid' => true, 'started_at' => '2024-01-15'])
                ->for($user);

            // Assert - Now paid
            $metadata = Toggl::for($user)->value('premium');
            expect($metadata)->not->toHaveKey('trial');
            expect($metadata['paid'])->toBeTrue();
        });

        test('feature configuration tracking', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - Set initial config
            Toggl::metadata('analytics')
                ->with([
                    'enabled_reports' => ['traffic', 'conversions'],
                    'refresh_interval' => 3_600,
                ])
                ->for($user);

            // Act - Add more reports
            Toggl::metadata('analytics')
                ->merge([
                    'enabled_reports' => ['traffic', 'conversions', 'revenue'],
                ])
                ->for($user);

            // Assert
            $metadata = Toggl::for($user)->value('analytics');
            expect($metadata['enabled_reports'])->toBe(['traffic', 'conversions', 'revenue']);
        });
    });

    describe('Edge Cases', function (): void {
        test('conductor exposes feature name', function (): void {
            // Arrange & Act
            $conductor = Toggl::metadata('premium');

            // Assert
            expect($conductor->feature())->toBe('premium');
        });

        test('conductor exposes metadata to set', function (): void {
            // Arrange & Act
            $conductor = Toggl::metadata('premium')
                ->with(['plan' => 'monthly']);

            // Assert
            expect($conductor->metadata())->toBe(['plan' => 'monthly']);
        });

        test('conductor exposes merge data', function (): void {
            // Arrange & Act
            $conductor = Toggl::metadata('premium')
                ->merge(['upgraded' => true]);

            // Assert
            expect($conductor->mergeData())->toBe(['upgraded' => true]);
        });

        test('conductor exposes forget keys', function (): void {
            // Arrange & Act
            $conductor = Toggl::metadata('premium')
                ->forget(['trial', 'trial_ends']);

            // Assert
            expect($conductor->forgetKeys())->toBe(['trial', 'trial_ends']);
        });

        test('conductor exposes clear flag', function (): void {
            // Arrange & Act
            $conductor = Toggl::metadata('premium')->clear();

            // Assert
            expect($conductor->isClear())->toBeTrue();
        });

        test('empty metadata with() is valid', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::metadata('premium')
                ->with([])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->value('premium'))->toBe([]);
        });

        test('empty merge() is valid', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', ['plan' => 'monthly']);

            // Act
            Toggl::metadata('premium')
                ->merge([])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->value('premium'))->toBe(['plan' => 'monthly']);
        });

        test('empty forget() is valid', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium', ['plan' => 'monthly']);

            // Act
            Toggl::metadata('premium')
                ->forget([])
                ->for($user);

            // Assert
            expect(Toggl::for($user)->value('premium'))->toBe(['plan' => 'monthly']);
        });
    });
});
