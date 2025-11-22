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
 * Fluent Definition Conductor Test Suite
 *
 * Tests fluent API for defining features with resolvers and defaults.
 */
describe('Fluent Definition Conductor', function (): void {
    describe('Basic Feature Definition', function (): void {
        test('defines feature with resolver', function (): void {
            // Arrange
            $user1 = User::factory()->create(['name' => 'Regular User']);
            $user2 = User::factory()->create(['name' => 'Premium User']);

            // Act - Resolver receives model instances
            Toggl::definition('premium')
                ->resolvedBy(fn ($context): bool =>
                    // Resolver gets the full context (model), so we compare IDs
                    $context->id === $user2->id)
                ->register();

            // Assert
            expect(Toggl::for($user1)->active('premium'))->toBeFalse();
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
        });

        test('defines feature with default value', function (): void {
            // Arrange & Act
            Toggl::definition('theme')
                ->defaultTo('dark')
                ->register();

            $user = User::factory()->create();

            // Assert
            expect(Toggl::for($user)->value('theme'))->toBe('dark');
        });

        test('defines feature with boolean default', function (): void {
            // Arrange & Act
            Toggl::definition('newsletter')
                ->defaultTo(true)
                ->register();

            $user = User::factory()->create();

            // Assert
            expect(Toggl::for($user)->active('newsletter'))->toBeTrue();
        });

        test('throws exception when no resolver or default provided', function (): void {
            // Arrange & Act & Assert
            expect(fn () => Toggl::definition('incomplete')->register())
                ->toThrow(RuntimeException::class, 'must have a resolver or default value');
        });
    });

    describe('Feature Definition with Description', function (): void {
        test('defines feature with description and resolver', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $conductor = Toggl::definition('api-access')
                ->describedAs('API access for integrations')
                ->resolvedBy(fn ($context): bool => true); // Always enabled for testing

            $conductor->register();

            // Assert - Feature works
            expect(Toggl::for($user)->active('api-access'))->toBeTrue();

            // Assert - Metadata accessible
            expect($conductor->description())->toBe('API access for integrations');
        });

        test('defines feature with description and default', function (): void {
            // Arrange & Act
            $conductor = Toggl::definition('language')
                ->describedAs('User interface language')
                ->defaultTo('en');

            $conductor->register();

            $user = User::factory()->create();

            // Assert
            expect(Toggl::for($user)->value('language'))->toBe('en');
            expect($conductor->description())->toBe('User interface language');
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('define premium feature based on user attributes', function (): void {
            // Arrange
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();

            // Act
            Toggl::definition('premium')
                ->describedAs('Premium subscription features')
                ->resolvedBy(fn ($context): bool => $context->id === $user2->id)
                ->register();

            // Assert
            expect(Toggl::for($user1)->active('premium'))->toBeFalse();
            expect(Toggl::for($user2)->active('premium'))->toBeTrue();
        });

        test('define feature with environment-based default', function (): void {
            // Arrange & Act
            Toggl::definition('debug-mode')
                ->describedAs('Debug mode for development')
                ->defaultTo(false)
                ->register();

            $user = User::factory()->create();

            // Assert
            expect(Toggl::for($user)->active('debug-mode'))->toBeFalse();
        });

        test('define attribute-based feature', function (): void {
            // Arrange
            $regularUser = User::factory()->create();
            $adminUser = User::factory()->create();

            // Act
            Toggl::definition('admin-panel')
                ->describedAs('Administrative panel access')
                ->resolvedBy(fn ($context): bool => $context->id === $adminUser->id)
                ->register();

            // Assert
            expect(Toggl::for($regularUser)->active('admin-panel'))->toBeFalse();
            expect(Toggl::for($adminUser)->active('admin-panel'))->toBeTrue();
        });

        test('define tiered feature based on user attributes', function (): void {
            // Arrange
            $freeUser = User::factory()->create();
            $proUser = User::factory()->create();
            $enterpriseUser = User::factory()->create();

            // Act
            Toggl::definition('export-limit')
                ->describedAs('Maximum number of exports per month')
                ->resolvedBy(fn ($context): int => match ($context->id) {
                    $enterpriseUser->id => 1_000,
                    $proUser->id => 100,
                    default => 10,
                })
                ->register();

            // Assert
            expect(Toggl::for($freeUser)->value('export-limit'))->toBe(10);
            expect(Toggl::for($proUser)->value('export-limit'))->toBe(100);
            expect(Toggl::for($enterpriseUser)->value('export-limit'))->toBe(1_000);
        });
    });

    describe('Method Chaining', function (): void {
        test('can chain description before resolver', function (): void {
            // Arrange & Act
            Toggl::definition('feature-1')
                ->describedAs('First feature')
                ->resolvedBy(fn (): true => true)
                ->register();

            $user = User::factory()->create();

            // Assert
            expect(Toggl::for($user)->active('feature-1'))->toBeTrue();
        });

        test('can chain description after resolver', function (): void {
            // Arrange & Act
            Toggl::definition('feature-2')
                ->resolvedBy(fn (): true => true)
                ->describedAs('Second feature')
                ->register();

            $user = User::factory()->create();

            // Assert
            expect(Toggl::for($user)->active('feature-2'))->toBeTrue();
        });

        test('can chain description before default', function (): void {
            // Arrange & Act
            Toggl::definition('feature-3')
                ->describedAs('Third feature')
                ->defaultTo('value')
                ->register();

            $user = User::factory()->create();

            // Assert
            expect(Toggl::for($user)->value('feature-3'))->toBe('value');
        });

        test('can chain description after default', function (): void {
            // Arrange & Act
            Toggl::definition('feature-4')
                ->defaultTo('value')
                ->describedAs('Fourth feature')
                ->register();

            $user = User::factory()->create();

            // Assert
            expect(Toggl::for($user)->value('feature-4'))->toBe('value');
        });
    });

    describe('Edge Cases', function (): void {
        test('conductor exposes metadata', function (): void {
            // Arrange & Act
            $conductor = Toggl::definition('test-feature')
                ->describedAs('Test description')
                ->resolvedBy(fn (): true => true);

            // Assert
            expect($conductor->feature())->toBe('test-feature');
            expect($conductor->description())->toBe('Test description');
            expect($conductor->resolver())->toBeInstanceOf(Closure::class);
        });

        test('resolver receives context ID parameter', function (): void {
            // Arrange
            $capturedContext = null;

            Toggl::definition('capture-test')
                ->resolvedBy(function ($context) use (&$capturedContext): true {
                    $capturedContext = $context;

                    return true;
                })
                ->register();

            $user = User::factory()->create();

            // Act
            Toggl::for($user)->active('capture-test');

            // Assert - Resolver receives full context (model instance)
            expect($capturedContext->id)->toBe($user->id);
        });

        test('default value can be any type', function (): void {
            // Arrange & Act
            Toggl::definition('array-feature')->defaultTo(['a', 'b', 'c'])->register();
            Toggl::definition('int-feature')->defaultTo(42)->register();
            Toggl::definition('string-feature')->defaultTo('default')->register();

            $user = User::factory()->create();

            // Assert
            expect(Toggl::for($user)->value('array-feature'))->toBe(['a', 'b', 'c']);
            expect(Toggl::for($user)->value('int-feature'))->toBe(42);
            expect(Toggl::for($user)->value('string-feature'))->toBe('default');
        });

        test('description is optional', function (): void {
            // Arrange & Act
            Toggl::definition('no-description')
                ->defaultTo('value')
                ->register();

            // Assert - Works without description
            $user = User::factory()->create();
            expect(Toggl::for($user)->value('no-description'))->toBe('value');
        });
    });
});
