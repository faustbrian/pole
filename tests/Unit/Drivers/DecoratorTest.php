<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Contracts\Driver;
use Cline\Pole\Contracts\FeatureScopeable;
use Cline\Pole\Drivers\ArrayDriver;
use Cline\Pole\Drivers\Decorator;
use Cline\Pole\LazilyResolvedFeature;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Lottery;

describe('Decorator', function (): void {
    describe('Happy Path', function (): void {
        test('stored method delegates to underlying driver', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn (): true => true);
            $decorator->define('feature2', fn (): false => false);
            $decorator->get('feature1', 'user1');
            $decorator->get('feature2', 'user2');

            // Act
            $stored = $decorator->stored();

            // Assert
            expect($stored)->toBeArray();
            expect($stored)->toContain('feature1');
            expect($stored)->toContain('feature2'); // Line 229-233
        });

        test('instance returns closure for class-based feature', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define(TestFeature::class);

            // Act
            $instance = $decorator->instance('test-feature');

            // Assert
            expect($instance)->toBeInstanceOf(TestFeature::class); // Line 502-504
        });

        test('instance returns closure for callable feature', function (): void {
            // Arrange
            $decorator = createDecorator();
            $resolver = fn (): true => true;
            $decorator->define('test-feature', $resolver);

            // Act
            $instance = $decorator->instance('test-feature');

            // Assert
            expect($instance)->toBeCallable(); // Line 506-508
        });

        test('instance returns wrapped value for static feature', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('test-feature', 'static-value');

            // Act
            $instance = $decorator->instance('test-feature');

            // Assert
            $result = $instance();
            expect($result)->toBe('static-value'); // Line 510
        });

        test('defineGroup creates feature group', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act
            $decorator->defineGroup('test-group', ['feature1', 'feature2', 'feature3']);

            // Assert
            expect($decorator->groups())->toHaveKey('test-group');
            expect($decorator->getGroup('test-group'))->toBe(['feature1', 'feature2', 'feature3']);
        });

        test('activateGroupForEveryone activates all features in group', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn (): false => false);
            $decorator->define('feature2', fn (): false => false);
            $decorator->defineGroup('test-group', ['feature1', 'feature2']);

            // Act
            $decorator->activateGroupForEveryone('test-group');

            // Assert
            expect($decorator->get('feature1', 'user1'))->toBeTrue();
            expect($decorator->get('feature2', 'user1'))->toBeTrue();
        });

        test('deactivateGroupForEveryone deactivates all features in group', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn (): true => true);
            $decorator->define('feature2', fn (): true => true);
            $decorator->defineGroup('test-group', ['feature1', 'feature2']);

            // Act
            $decorator->deactivateGroupForEveryone('test-group');

            // Assert
            expect($decorator->get('feature1', 'user1'))->toBeFalse();
            expect($decorator->get('feature2', 'user1'))->toBeFalse();
        });

        test('expiringSoon returns features expiring within specified days', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $decorator = createDecorator();

            // Define feature expiring soon
            $lazilyResolved1 = $decorator->define('expiring-soon');
            $lazilyResolved1->expiresAt(Date::now()->addDays(3))->resolver(fn (): true => true);

            // Define feature expiring later
            $lazilyResolved2 = $decorator->define('expiring-later');
            $lazilyResolved2->expiresAt(Date::now()->addDays(30))->resolver(fn (): true => true);

            // Act
            $expiring = $decorator->expiringSoon(7);

            // Assert
            expect($expiring)->toContain('expiring-soon');
            expect($expiring)->not->toContain('expiring-later'); // Line 450-460

            // Cleanup
            Date::setTestNow();
        });

        test('dependenciesMet returns true when all dependencies are active', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('dependency1', fn (): true => true);
            $decorator->define('dependency2', fn (): true => true);

            $lazilyResolved = $decorator->define('test-feature');
            $lazilyResolved->requires(['dependency1', 'dependency2'])->resolver(fn (): true => true);

            // Act
            $result = $decorator->dependenciesMet('test-feature');

            // Assert
            expect($result)->toBeTrue(); // Line 486-510
        });

        test('defineVariant creates variant with weights', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act
            $decorator->defineVariant('ab-test', ['control' => 50, 'treatment' => 50]);

            // Assert
            expect($decorator->getVariants('ab-test'))->toBe(['control' => 50, 'treatment' => 50]);
            expect($decorator->variantNames('ab-test'))->toBe(['control', 'treatment']);
        });

        test('variant assigns and persists variant for scope', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->defineVariant('ab-test', ['control' => 50, 'treatment' => 50]);

            // Act
            $variant1 = $decorator->variant('ab-test');
            $variant2 = $decorator->variant('ab-test');

            // Assert - Same scope should always get same variant
            expect($variant1)->toBeIn(['control', 'treatment']);
            expect($variant1)->toBe($variant2);
        });

        test('resolve handles invokable objects like Laravel Lottery', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('lottery-feature', fn () => Lottery::odds(1, 1)); // Always wins

            // Act
            $result = $decorator->get('lottery-feature', 'user1');

            // Assert
            expect($result)->toBeTrue(); // Line 976-977
        });

        test('resolves FeatureScopeable to identifier', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('test-feature', fn (): true => true);

            $scopeable = new TestScopeable();

            // Act
            $result = $decorator->get('test-feature', $scopeable);

            // Assert
            expect($result)->toBeTrue(); // Line 1081
        });

        test('purge with single string feature', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn (): true => true);
            $decorator->define('feature2', fn (): true => true);
            $decorator->get('feature1', 'user1');
            $decorator->get('feature2', 'user1');

            // Act
            $decorator->purge('feature1');

            // Assert - Verify cache was cleared
            $decorator->flushCache();

            $callCount = 0;
            $decorator->define('feature1', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });
            $decorator->get('feature1', 'user1');

            expect($callCount)->toBe(1); // Should resolve again
        });

        test('circular dependency detection prevents infinite recursion', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Create circular dependency: A requires B, B requires A
            $featureA = $decorator->define('feature-a');
            $featureA->requires('feature-b')->resolver(fn (): true => true);

            $featureB = $decorator->define('feature-b');
            $featureB->requires('feature-a')->resolver(fn (): true => true);

            // Act
            $result = $decorator->get('feature-a', 'user1');

            // Assert - Should return false instead of infinite recursion
            expect($result)->toBeFalse(); // Line 956, 1013
        });

        test('expired feature returns false during get', function (): void {
            // Arrange
            Date::setTestNow('2024-01-01 12:00:00');
            $decorator = createDecorator();

            $lazilyResolved = $decorator->define('expired-feature');
            $lazilyResolved
                ->expiresAt(Date::now()->subDay())
                ->resolver(fn (): true => true);

            // Act
            $result = $decorator->get('expired-feature', 'user1');

            // Assert
            expect($result)->toBeFalse(); // Line 828

            // Cleanup
            Date::setTestNow();
        });

        test('macro functionality works via __call', function (): void {
            // Arrange
            $decorator = createDecorator();

            Decorator::macro('customMethod', fn (): string => 'macro-result');

            // Act
            $result = $decorator->customMethod();

            // Assert
            expect($result)->toBe('macro-result'); // Line 131
        });

        test('__call creates PendingScopedFeatureInteraction for non-macro calls', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('test-feature', fn (): true => true);

            // Act
            $result = $decorator->active('test-feature');

            // Assert
            expect($result)->toBeTrue(); // Line 138
        });

        test('define with class auto-discovery and resolve method', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act
            $decorator->define(TestFeatureWithResolve::class);

            // Assert
            expect($decorator->defined())->toContain('test-feature-resolve');
            expect($decorator->get('test-feature-resolve', 'user1'))->toBe('resolved-value'); // Line 162-174
        });

        test('define with class auto-discovery and __invoke method', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act
            $decorator->define(TestFeature::class);

            // Assert
            expect($decorator->defined())->toContain('test-feature');
            expect($decorator->get('test-feature', 'user1'))->toBe('invoked-value'); // Line 167-171
        });

        test('getAllMissing only fetches non-cached features', function (): void {
            // Arrange
            $decorator = createDecorator();
            $callCount1 = 0;
            $callCount2 = 0;

            $decorator->define('feature1', function () use (&$callCount1): string {
                ++$callCount1;

                return 'value1';
            });

            $decorator->define('feature2', function () use (&$callCount2): string {
                ++$callCount2;

                return 'value2';
            });

            // Pre-load feature1
            $decorator->get('feature1', 'user1');

            // Act
            $results = $decorator->getAllMissing([
                'feature1' => ['user1'],
                'feature2' => ['user1'],
            ]);

            // Assert - feature1 should not be resolved again, feature2 should
            expect($callCount1)->toBe(1); // Only called once during get()
            expect($callCount2)->toBe(1); // Called during getAllMissing
            expect($results)->toHaveKey('feature2'); // Line 1063-1065
        });

        test('setContainer updates container instance', function (): void {
            // Arrange
            $decorator = createDecorator();
            $newContainer = app(Container::class);

            // Act
            $result = $decorator->setContainer($newContainer);

            // Assert
            expect($result)->toBe($decorator); // Fluent interface
        });

        test('nameMap returns feature name mappings', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn (): true => true);
            $decorator->define('feature2', fn (): false => false);

            // Act
            $nameMap = $decorator->nameMap();

            // Assert
            expect($nameMap)->toHaveKey('feature1');
            expect($nameMap)->toHaveKey('feature2');
        });

        test('purge with null clears all features and cache', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn (): true => true);
            $decorator->define('feature2', fn (): true => true);
            $decorator->get('feature1', 'user1');
            $decorator->get('feature2', 'user1');

            // Act
            $decorator->purge();

            // Assert - Cache should be completely cleared
            $callCount = 0;
            $decorator->define('feature1', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });

            $decorator->get('feature1', 'user1');

            expect($callCount)->toBe(1); // Should resolve again // Line 450-460
        });

        test('name resolves feature class to name', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define(TestFeature::class);

            // Act
            $name = $decorator->name(TestFeature::class);

            // Assert
            expect($name)->toBe('test-feature');
        });

        test('dynamic feature definition when checking undefined class-based feature', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act - Check feature without explicitly defining it
            $result = $decorator->get(TestFeature::class, 'user1');

            // Assert - Should auto-discover and define the feature
            expect($decorator->defined())->toContain('test-feature'); // Line 1063-1065
            expect($result)->toBe('invoked-value');
        });
    });

    describe('Sad Path', function (): void {
        test('stored throws exception when driver does not support listing', function (): void {
            // Arrange
            $decorator = new Decorator(
                'test',
                new class() implements Driver
                {
                    public function define(string $feature, mixed $resolver = null): mixed
                    {
                        return null;
                    }

                    public function defined(): array
                    {
                        return [];
                    }

                    public function get(string $feature, mixed $scope): mixed
                    {
                        return false;
                    }

                    public function getAll(array $features): array
                    {
                        return [];
                    }

                    public function set(string $feature, mixed $scope, mixed $value): void {}

                    public function setForAllScopes(string $feature, mixed $value): void {}

                    public function delete(string $feature, mixed $scope): void {}

                    public function purge(?array $features): void {}
                },
                fn (): null => null,
                app(Container::class),
            );

            // Act & Assert
            expect(fn (): array => $decorator->stored())
                ->toThrow(RuntimeException::class, 'does not support listing stored features'); // Line 229-233
        });

        test('getGroup throws exception for undefined group', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act & Assert
            expect(fn (): array => $decorator->getGroup('non-existent'))
                ->toThrow(InvalidArgumentException::class, 'Feature group [non-existent] is not defined.');
        });

        test('defineVariant throws exception when weights do not sum to 100', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act & Assert
            expect(fn () => $decorator->defineVariant('bad-variant', ['control' => 60, 'treatment' => 50]))
                ->toThrow(InvalidArgumentException::class, 'Variant weights must sum to 100');
        });
    });

    describe('Edge Cases', function (): void {
        test('activeInGroup returns true for empty group', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->defineGroup('empty-group', []);

            // Act & Assert
            expect($decorator->activeInGroup('empty-group'))->toBeTrue();
        });

        test('someActiveInGroup returns false for empty group', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->defineGroup('empty-group', []);

            // Act & Assert
            expect($decorator->someActiveInGroup('empty-group'))->toBeFalse();
        });

        test('define with LazilyResolvedFeature instance', function (): void {
            // Arrange
            $decorator = createDecorator();
            $lazilyResolved = new LazilyResolvedFeature('test-feature', fn (): string => 'value');

            // Act
            $decorator->define('test-feature', $lazilyResolved);

            // Assert
            expect($decorator->defined())->toContain('test-feature');
            expect($decorator->get('test-feature', 'user1'))->toBe('value'); // Line 186-193
        });

        test('define returns LazilyResolvedFeature for fluent API', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act
            $lazilyResolved = $decorator->define('test-feature');

            // Assert
            expect($lazilyResolved)->toBeInstanceOf(LazilyResolvedFeature::class);
            expect($lazilyResolved->getName())->toBe('test-feature'); // Line 178-182
        });

        test('define with static value', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act
            $decorator->define('test-feature', true);

            // Assert
            expect($decorator->get('test-feature', 'user1'))->toBeTrue(); // Line 200-204
        });

        test('variant returns null when feature has no variants', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act
            $result = $decorator->variant('non-variant-feature');

            // Assert
            expect($result)->toBeNull();
        });

        test('variantNames returns empty array for undefined variant', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act
            $result = $decorator->variantNames('non-existent');

            // Assert
            expect($result)->toBe([]);
        });

        test('getVariants returns empty array for undefined variant', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act
            $result = $decorator->getVariants('non-existent');

            // Assert
            expect($result)->toBe([]);
        });

        test('isExpired returns false for feature with no expiration', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('test-feature', fn (): true => true);

            // Act & Assert
            expect($decorator->isExpired('test-feature'))->toBeFalse();
        });

        test('expiresAt returns null for feature with no expiration', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('test-feature', fn (): true => true);

            // Act & Assert
            expect($decorator->expiresAt('test-feature'))->toBeNull();
        });

        test('isExpiringSoon returns false for feature with no expiration', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('test-feature', fn (): true => true);

            // Act & Assert
            expect($decorator->isExpiringSoon('test-feature', 7))->toBeFalse();
        });

        test('getDependencies returns empty array for feature with no dependencies', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('test-feature', fn (): true => true);

            // Act & Assert
            expect($decorator->getDependencies('test-feature'))->toBe([]);
        });

        test('dependenciesMet returns true for feature with no dependencies', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('test-feature', fn (): true => true);

            // Act & Assert
            expect($decorator->dependenciesMet('test-feature'))->toBeTrue();
        });

        test('flushCache clears both decorator and underlying driver cache', function (): void {
            // Arrange
            $decorator = createDecorator();
            $callCount = 0;
            $decorator->define('test-feature', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });

            $decorator->get('test-feature', 'user1');

            // Act
            $decorator->flushCache();
            $decorator->get('test-feature', 'user1');

            // Assert
            expect($callCount)->toBe(2); // Called twice due to cache flush
        });

        test('loadGroupsFromConfig loads groups from configuration', function (): void {
            // Arrange
            config(['pole.groups.config-group' => ['features' => ['feature1', 'feature2']]]);

            // Act
            $decorator = createDecorator(); // Constructor calls loadGroupsFromConfig

            // Assert
            expect($decorator->groups())->toHaveKey('config-group');
            expect($decorator->getGroup('config-group'))->toBe(['feature1', 'feature2']);
        });

        test('calculateVariant consistently assigns to last variant', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->defineVariant('edge-test', ['first' => 0, 'second' => 0, 'third' => 100]);

            // Act
            $variant = $decorator->variant('edge-test');

            // Assert - With all weight on last variant, should always get it
            expect($variant)->toBe('third'); // Line 1190
        });

        test('getDriver returns underlying driver instance', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act
            $driver = $decorator->getDriver();

            // Assert - Line 828: getDriver() method
            expect($driver)->toBeInstanceOf(Driver::class);
            expect($driver)->toBeInstanceOf(ArrayDriver::class);
        });

        test('__call with defaultScopeResolver returning non-null value', function (): void {
            // Arrange
            $defaultScope = 'default-user';
            $decorator = new Decorator(
                'test',
                new ArrayDriver(app(Dispatcher::class), []),
                fn (): string => $defaultScope,
                app(Container::class),
            );
            $decorator->define('test-feature', fn (): true => true);

            // Act - Call a method that is NOT 'for'
            $result = $decorator->active('test-feature');

            // Assert - Line 138: defaultScopeResolver returns non-null
            expect($result)->toBeTrue();
        });

        test('normalizeFeaturesToLoad with associative array keys', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn ($scope): string => 'value-for-'.$scope);
            $decorator->define('feature2', fn ($scope): string => 'value-for-'.$scope);

            // Act - Line 1013: Pass associative array (feature => scope) to getAll
            // This tests the else branch in normalizeFeaturesToLoad where is_int($key) is false
            $results = $decorator->getAll([
                'feature1' => 'scope1',
                'feature2' => ['scope2', 'scope3'],
            ]);

            // Assert
            expect($results)->toHaveKey('feature1');
            expect($results)->toHaveKey('feature2');
            expect($results['feature1'])->toContain('value-for-scope1');
            expect($results['feature2'])->toContain('value-for-scope2');
            expect($results['feature2'])->toContain('value-for-scope3');
        });
    });
});

function createDecorator(): Decorator
{
    return new Decorator(
        'test',
        new ArrayDriver(app(Dispatcher::class), []),
        fn (): null => null,
        app(Container::class),
    );
}

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class TestFeature
{
    public string $name = 'test-feature';

    public function __invoke(): string
    {
        return 'invoked-value';
    }
}

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class TestFeatureWithResolve
{
    public string $name = 'test-feature-resolve';

    public function resolve(): string
    {
        return 'resolved-value';
    }
}

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class TestScopeable implements FeatureScopeable
{
    public function toFeatureIdentifier(string $driver): mixed
    {
        return 'scopeable-id';
    }
}
