<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Contracts\TogglContextable;
use Cline\Toggl\Drivers\ArrayDriver;
use Cline\Toggl\Drivers\Decorator;
use Cline\Toggl\Events\FeatureActivated;
use Cline\Toggl\Events\FeatureDeactivated;
use Cline\Toggl\FeatureManager;
use Cline\Toggl\GroupRepositories\ArrayFeatureGroupMembershipRepository;
use Cline\Toggl\GroupRepositories\ArrayGroupRepository;
use Cline\Toggl\LazilyResolvedFeature;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Lottery;

/**
 * Decorator test suite.
 *
 * Tests the Decorator class which provides the primary feature flag API surface,
 * wrapping underlying driver implementations with additional functionality like
 * feature groups, variants, dependencies, expiration, lazy resolution, macros,
 * and context management. The Decorator acts as a facade over drivers, enriching
 * them with higher-level feature flag capabilities while maintaining a clean,
 * fluent interface for application code.
 */
describe('Decorator', function (): void {
    describe('Happy Path', function (): void {
        test('stored method delegates to underlying driver', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn (): true => true);
            $decorator->define('feature2', fn (): false => false);
            $decorator->get('feature1', TogglContext::simple('user1', 'test'));
            $decorator->get('feature2', TogglContext::simple('user2', 'test'));

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
            expect($decorator->allGroups())->toHaveKey('test-group');
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
            expect($decorator->get('feature1', TogglContext::simple('user1', 'test')))->toBeTrue();
            expect($decorator->get('feature2', TogglContext::simple('user1', 'test')))->toBeTrue();
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
            expect($decorator->get('feature1', TogglContext::simple('user1', 'test')))->toBeFalse();
            expect($decorator->get('feature2', TogglContext::simple('user1', 'test')))->toBeFalse();
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

        test('variant assigns and persists variant for context', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->defineVariant('ab-test', ['control' => 50, 'treatment' => 50]);

            // Act
            $variant1 = $decorator->variant('ab-test');
            $variant2 = $decorator->variant('ab-test');

            // Assert - Same context should always get same variant
            expect($variant1)->toBeIn(['control', 'treatment']);
            expect($variant1)->toBe($variant2);
        });

        test('resolve handles invokable objects like Laravel Lottery', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('lottery-feature', fn () => Lottery::odds(1, 1)); // Always wins

            // Act
            $result = $decorator->get('lottery-feature', TogglContext::simple('user1', 'test'));

            // Assert
            expect($result)->toBeTrue(); // Line 976-977
        });

        test('resolves TogglContextable to identifier', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('test-feature', fn (): true => true);

            $contextable = new TestContextable();

            // Act
            $result = $decorator->get('test-feature', $contextable);

            // Assert
            expect($result)->toBeTrue(); // Line 1081
        });

        test('purge with single string feature', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn (): true => true);
            $decorator->define('feature2', fn (): true => true);
            $decorator->get('feature1', TogglContext::simple('user1', 'test'));
            $decorator->get('feature2', TogglContext::simple('user1', 'test'));

            // Act
            $decorator->purge('feature1');

            // Assert - Verify cache was cleared
            $decorator->flushCache();

            $callCount = 0;
            $decorator->define('feature1', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });
            $decorator->get('feature1', TogglContext::simple('user1', 'test'));

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
            $result = $decorator->get('feature-a', TogglContext::simple('user1', 'test'));

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
            $result = $decorator->get('expired-feature', TogglContext::simple('user1', 'test'));

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

        test('__call creates PendingContextualFeatureInteraction for non-macro calls', function (): void {
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
            expect($decorator->get('test-feature-resolve', TogglContext::simple('user1', 'test')))->toBe('resolved-value'); // Line 162-174
        });

        test('define with class auto-discovery and __invoke method', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act
            $decorator->define(TestFeature::class);

            // Assert
            expect($decorator->defined())->toContain('test-feature');
            expect($decorator->get('test-feature', TogglContext::simple('user1', 'test')))->toBe('invoked-value'); // Line 167-171
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
            $decorator->get('feature1', TogglContext::simple('user1', 'test'));

            // Act
            $results = $decorator->getAllMissing([
                'feature1' => [TogglContext::simple('user1', 'test')],
                'feature2' => [TogglContext::simple('user1', 'test')],
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
            $decorator->get('feature1', TogglContext::simple('user1', 'test'));
            $decorator->get('feature2', TogglContext::simple('user1', 'test'));

            // Act
            $decorator->purge();

            // Assert - Cache should be completely cleared
            $callCount = 0;
            $decorator->define('feature1', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });

            $decorator->get('feature1', TogglContext::simple('user1', 'test'));

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
            $result = $decorator->get(TestFeature::class, TogglContext::simple('user1', 'test'));

            // Assert - Should auto-discover and define the feature
            expect($decorator->defined())->toContain('test-feature'); // Line 1063-1065
            expect($result)->toBe('invoked-value');
        });
    });

    describe('Sad Path', function (): void {
        test('stored throws exception when driver does not support listing', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
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

                    public function get(string $feature, mixed $context): mixed
                    {
                        return false;
                    }

                    public function getAll(array $features): array
                    {
                        return [];
                    }

                    public function set(string $feature, mixed $context, mixed $value): void {}

                    public function setForAllContexts(string $feature, mixed $value): void {}

                    public function delete(string $feature, mixed $context): void {}

                    public function purge(?array $features): void {}
                },
                fn (): null => null,
                app(Container::class),
                new ArrayGroupRepository(),
                new ArrayFeatureGroupMembershipRepository($manager),
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
            expect($decorator->get('test-feature', TogglContext::simple('user1', 'test')))->toBe('value'); // Line 186-193
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
            expect($decorator->get('test-feature', TogglContext::simple('user1', 'test')))->toBeTrue(); // Line 200-204
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

            $decorator->get('test-feature', TogglContext::simple('user1', 'test'));

            // Act
            $decorator->flushCache();
            $decorator->get('test-feature', TogglContext::simple('user1', 'test'));

            // Assert
            expect($callCount)->toBe(2); // Called twice due to cache flush
        });

        test('loadGroupsFromConfig loads groups from configuration', function (): void {
            // Arrange
            config(['toggl.groups.config-group' => ['features' => ['feature1', 'feature2']]]);

            // Act
            $decorator = createDecorator(); // Constructor calls loadGroupsFromConfig

            // Assert
            expect($decorator->allGroups())->toHaveKey('config-group');
            expect($decorator->getGroup('config-group'))->toBe(['feature1', 'feature2']);
        });

        test('loadGroupsFromConfig handles non-array config value', function (): void {
            // Arrange
            config(['toggl.groups' => 'invalid-string']);

            // Act
            $decorator = createDecorator(); // Constructor calls loadGroupsFromConfig

            // Assert - Should handle gracefully without loading any groups
            expect($decorator->allGroups())->toBe([]); // Line 758: early return
        });

        test('loadGroupsFromConfig skips groups with non-string names', function (): void {
            // Arrange
            config(['toggl.groups' => [
                'valid-group' => ['features' => ['feature1']],
                0 => ['features' => ['feature2']], // Numeric key
                123 => ['features' => ['feature3']], // Numeric key
            ]]);

            // Act
            $decorator = createDecorator(); // Constructor calls loadGroupsFromConfig

            // Assert - Only valid-group should be loaded
            expect($decorator->allGroups())->toHaveKey('valid-group');
            expect($decorator->allGroups())->not->toHaveKey('0');
            expect($decorator->allGroups())->not->toHaveKey('123'); // Line 763: continue on non-string name
        });

        test('loadGroupsFromConfig skips groups with non-array data', function (): void {
            // Arrange
            config(['toggl.groups' => [
                'valid-group' => ['features' => ['feature1']],
                'invalid-string' => 'not-an-array',
                'invalid-null' => null,
                'invalid-number' => 42,
            ]]);

            // Act
            $decorator = createDecorator(); // Constructor calls loadGroupsFromConfig

            // Assert - Only valid-group should be loaded
            expect($decorator->allGroups())->toHaveKey('valid-group');
            expect($decorator->allGroups())->not->toHaveKey('invalid-string');
            expect($decorator->allGroups())->not->toHaveKey('invalid-null');
            expect($decorator->allGroups())->not->toHaveKey('invalid-number'); // Line 767: continue on non-array data
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

        test('__call with defaultContextResolver returning non-null value', function (): void {
            // Arrange
            $defaultContext = TogglContext::simple('default-user', 'test');
            $manager = app(FeatureManager::class);
            $decorator = new Decorator(
                'test',
                new ArrayDriver(app(Dispatcher::class), []),
                fn (): TogglContext => $defaultContext,
                app(Container::class),
                new ArrayGroupRepository(),
                new ArrayFeatureGroupMembershipRepository($manager),
            );
            $decorator->define('test-feature', fn (): true => true);

            // Act - Call a method that is NOT 'for'
            $result = $decorator->active('test-feature');

            // Assert - Line 138: defaultContextResolver returns non-null
            expect($result)->toBeTrue();
        });

        test('normalizeFeaturesToLoad with associative array keys', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn (TogglContext $context): string => 'value-for-'.$context->id);
            $decorator->define('feature2', fn (TogglContext $context): string => 'value-for-'.$context->id);

            // Act - Line 1013: Pass associative array (feature => context) to getAll
            // This tests the else branch in normalizeFeaturesToLoad where is_int($key) is false
            $results = $decorator->getAll([
                'feature1' => TogglContext::simple('context1', 'test'),
                'feature2' => [TogglContext::simple('context2', 'test'), TogglContext::simple('context3', 'test')],
            ]);

            // Assert
            expect($results)->toHaveKey('feature1');
            expect($results)->toHaveKey('feature2');
            expect($results['feature1'])->toContain('value-for-context1');
            expect($results['feature2'])->toContain('value-for-context2');
            expect($results['feature2'])->toContain('value-for-context3');
        });

        test('normalizeFeaturesToLoad with integer array keys and integer values', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature-one', fn (): true => true);
            $decorator->define('feature-two', fn (): false => false);

            // Act - Line 1021-1023: Pass indexed array with string values to test assert(is_string($value) || is_int($value))
            // This tests the is_int($key) branch in normalizeFeaturesToLoad
            $results = $decorator->getAll(['feature-one', 'feature-two']);

            // Assert
            expect($results)->toHaveKey('feature-one');
            expect($results)->toHaveKey('feature-two');
        });

        test('ensureDynamicFeatureIsDefined with class without name property', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act - Line 1079: Check feature class without name property
            // Should fall back to using the class name as the feature name
            $result = $decorator->get(TestFeatureWithoutName::class, TogglContext::simple('user1', 'test'));

            // Assert
            expect($decorator->defined())->toContain(TestFeatureWithoutName::class);
            expect($result)->toBe('no-name-value');
        });

        test('calculateVariant fallback to last variant when bucket equals cumulative weight', function (): void {
            // Arrange
            $decorator = createDecorator();
            // Create variant where weights sum to 100 but bucket could theoretically equal cumulative
            // This is an edge case that shouldn't normally happen, but tests the fallback logic
            $decorator->defineVariant('fallback-test', ['first' => 50, 'second' => 50]);

            // Act - The calculateVariant method should handle edge cases where bucket === cumulative
            // Lines 1208-1211: Fallback to last variant
            $variant = $decorator->variant('fallback-test');

            // Assert
            expect($variant)->toBeIn(['first', 'second']);
        });

        test('checkFeatureViaFeatureGroupMembership handles exception when group does not exist', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Define a feature
            $decorator->define('test-feature', fn (): true => true);

            // Add context to a non-existent group (membership exists but group doesn't)
            // This simulates the edge case where feature group membership is stale
            $decorator->groups()->assign('non-existent-group', TogglContext::simple('user123', 'test'));

            // Act - Should gracefully handle the missing group via exception catch
            $result = $decorator->get('test-feature', TogglContext::simple('user123', 'test'));

            // Assert - Should continue checking and return default value
            expect($result)->toBeTrue(); // Returns feature's default value // Lines 1040-1053
        });

        test('checkFeatureViaFeatureGroupMembership returns group value when feature is in group', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Define a feature with default false
            $decorator->define('premium-feature', fn (): false => false);

            // Create a group and activate it globally
            $decorator->defineGroup('premium-group', ['premium-feature']);
            $decorator->activateGroupForEveryone('premium-group');

            // Add context to the group
            $decorator->groups()->assign('premium-group', TogglContext::simple('user456', 'test'));

            // Act - Should check feature group membership and return true
            $result = $decorator->get('premium-feature', TogglContext::simple('user456', 'test'));

            // Assert - Should return the group's activated value
            expect($result)->toBeTrue(); // Lines 1040-1050
        });

        test('checkFeatureViaFeatureGroupMembership continues on exception and checks remaining groups', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Define a feature
            $decorator->define('test-feature', fn (): false => false);

            // Create a valid group
            $decorator->defineGroup('valid-group', ['test-feature']);
            $decorator->activateGroupForEveryone('valid-group');

            // Add context to both a non-existent group and a valid group
            $decorator->groups()->assign('non-existent-group', TogglContext::simple('user789', 'test')); // This will throw when checking
            $decorator->groups()->assign('valid-group', TogglContext::simple('user789', 'test')); // This should work

            // Act - Should skip the non-existent group and use the valid one
            $result = $decorator->get('test-feature', TogglContext::simple('user789', 'test'));

            // Assert - Should successfully get value from valid group despite exception
            expect($result)->toBeTrue(); // Lines 1051-1054
        });

        test('variant retrieves stored variant value', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->defineVariant('ab-test', ['control' => 50, 'treatment' => 50]);

            // Store a specific variant for a context
            $decorator->set('ab-test', TogglContext::simple('user-consistent', 'test'), 'control');

            // Act - Should retrieve the stored variant instead of calculating
            $variant = $decorator->variant('ab-test');

            // Assert - Should return stored value
            expect($variant)->toBe('control'); // Line 984
        });

        test('loadGroupsFromConfig skips groups missing features key', function (): void {
            // Arrange
            config(['toggl.groups' => [
                'valid-group' => ['features' => ['feature1']],
                'no-features-key' => ['other-key' => 'value'],
            ]]);

            // Act
            $decorator = createDecorator(); // Constructor calls loadGroupsFromConfig

            // Assert - Only valid-group should be loaded
            expect($decorator->allGroups())->toHaveKey('valid-group');
            expect($decorator->allGroups())->not->toHaveKey('no-features-key'); // Line 770-772
        });

        test('loadGroupsFromConfig skips groups with non-array features', function (): void {
            // Arrange
            config(['toggl.groups' => [
                'valid-group' => ['features' => ['feature1', 'feature2']],
                'string-features' => ['features' => 'not-an-array'],
                'null-features' => ['features' => null],
                'numeric-features' => ['features' => 123],
            ]]);

            // Act
            $decorator = createDecorator(); // Constructor calls loadGroupsFromConfig

            // Assert - Only valid-group should be loaded
            expect($decorator->allGroups())->toHaveKey('valid-group');
            expect($decorator->allGroups())->not->toHaveKey('string-features');
            expect($decorator->allGroups())->not->toHaveKey('null-features');
            expect($decorator->allGroups())->not->toHaveKey('numeric-features'); // Line 775-777
        });

        test('assign and unassign feature group membership integration', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('premium-feature', fn (TogglContext $context): bool => $context->id === 'direct-user');
            $decorator->defineGroup('premium-group', ['premium-feature']);

            // Act - Assign context to group
            $decorator->groups()->assign('premium-group', TogglContext::simple('user-premium', 'test'));

            // Activate feature for the specific context
            $decorator->set('premium-feature', TogglContext::simple('user-premium', 'test'), true);

            $resultAfterAdd = $decorator->get('premium-feature', TogglContext::simple('user-premium', 'test'));

            // Unassign context from group
            $decorator->groups()->unassign('premium-group', TogglContext::simple('user-premium', 'test'));
            $decorator->flushCache(); // Clear cache to force re-evaluation
            $resultAfterRemove = $decorator->get('premium-feature', TogglContext::simple('user-premium', 'test'));

            // Also test that feature group membership is correctly identified
            expect($decorator->groups()->isInGroup('premium-group', TogglContext::simple('user-premium', 'test')))->toBeFalse();

            // Assert
            expect($resultAfterAdd)->toBeTrue(); // Feature active via set()
            expect($resultAfterRemove)->toBeFalse(); // Feature returns resolver's false after cache flush
        });

        test('putInCache updates existing entry when feature/context match', function (): void {
            // Arrange
            $decorator = createDecorator();
            $callCount = 0;

            $decorator->define('test-feature', function () use (&$callCount): int {
                ++$callCount;

                return $callCount;
            });

            // Act - Get feature twice to test cache update
            $firstGet = $decorator->get('test-feature', TogglContext::simple('user1', 'test'));

            // Manually update cache by setting a new value (this will update existing entry)
            $decorator->set('test-feature', TogglContext::simple('user1', 'test'), 999);
            $secondGet = $decorator->get('test-feature', TogglContext::simple('user1', 'test'));

            // Assert - Second get should return the updated cached value
            expect($firstGet)->toBe(1);
            expect($secondGet)->toBe(999); // Line 1261: cache update path
            expect($callCount)->toBe(1); // Only called once, second value from cache
        });

        test('getAll caches results correctly with multiple contexts', function (): void {
            // Arrange
            $decorator = createDecorator();
            $callCounts = ['feature1' => 0, 'feature2' => 0];

            $decorator->define('feature1', function () use (&$callCounts): int {
                return ++$callCounts['feature1'];
            });

            $decorator->define('feature2', function () use (&$callCounts): int {
                return ++$callCounts['feature2'];
            });

            // Act - Get all features for multiple contexts
            $results = $decorator->getAll([
                'feature1' => [TogglContext::simple('context1', 'test'), TogglContext::simple('context2', 'test')],
                'feature2' => [TogglContext::simple('context3', 'test'), TogglContext::simple('context4', 'test')],
            ]);

            // Verify cache by getting again
            $decorator->get('feature1', TogglContext::simple('context1', 'test'));
            $decorator->get('feature2', TogglContext::simple('context3', 'test'));

            // Assert - Verify results structure and cache usage
            expect($results)->toHaveKey('feature1');
            expect($results)->toHaveKey('feature2');
            expect($results['feature1'])->toHaveCount(2);
            expect($results['feature2'])->toHaveCount(2);
            // Each feature called twice (once per context), not again due to cache
            expect($callCounts['feature1'])->toBe(2); // Line 319-325: cache population
            expect($callCounts['feature2'])->toBe(2);
        });

        test('removeFromCache removes correct entry', function (): void {
            // Arrange
            $decorator = createDecorator();
            $callCount = 0;

            $decorator->define('test-feature', function () use (&$callCount): int {
                return ++$callCount;
            });

            // Act - Get to populate cache, delete to remove, then get again
            $decorator->get('test-feature', TogglContext::simple('user1', 'test'));
            $decorator->delete('test-feature', TogglContext::simple('user1', 'test'));
            $decorator->get('test-feature', TogglContext::simple('user1', 'test'));

            // Assert - Feature should be resolved twice (cache was cleared by delete)
            expect($callCount)->toBe(2); // Line 1275-1281: removeFromCache
        });

        test('checkFeatureViaFeatureGroupMembership returns false when group value is false', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('inactive-feature', fn (): false => false);
            $decorator->defineGroup('inactive-group', ['inactive-feature']);

            // Add context to group but feature is not activated globally
            $decorator->groups()->assign('inactive-group', TogglContext::simple('user-inactive', 'test'));

            // Act - Should check feature group membership but feature is not active globally
            $result = $decorator->get('inactive-feature', TogglContext::simple('user-inactive', 'test'));

            // Assert - Should return false because global value is false
            expect($result)->toBeFalse(); // Line 1047: false check
        });

        test('allGroups merges in-memory and repository groups', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Add group via defineGroup (in-memory)
            $decorator->defineGroup('memory-group', ['feature1']);

            // Add group via repository
            $decorator->groups()->define('repo-group', ['feature2']);

            // Act
            $allGroups = $decorator->allGroups();

            // Assert - Should merge both sources
            expect($allGroups)->toHaveKey('memory-group');
            expect($allGroups)->toHaveKey('repo-group'); // Line 648: array_merge
        });

        test('getGroup loads from repository and caches', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Define group via repository
            $decorator->groups()->define('cached-group', ['feature1', 'feature2']);

            // Act - First call loads from repository
            $firstCall = $decorator->getGroup('cached-group');

            // Modify repository (to verify cache is used)
            $decorator->groups()->define('cached-group', ['feature3']);

            // Second call should use cache
            $secondCall = $decorator->getGroup('cached-group');

            // Assert - Second call should return cached value
            expect($firstCall)->toBe(['feature1', 'feature2']);
            expect($secondCall)->toBe(['feature1', 'feature2']); // Line 636-638: caching
        });

        test('enableGlobally activates feature for everyone', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('premium-feature', fn (): false => false);

            // Act
            $decorator->enableGlobally('premium-feature');

            // Assert
            expect($decorator->get('premium-feature', TogglContext::simple('user1', 'test')))->toBeTrue();
            expect($decorator->get('premium-feature', TogglContext::simple('user2', 'test')))->toBeTrue(); // Line 483
        });

        test('disableGlobally deactivates feature for everyone', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('test-feature', fn (): true => true);
            $decorator->activateForEveryone('test-feature');

            // Act
            $decorator->disableGlobally('test-feature');

            // Assert
            expect($decorator->get('test-feature', TogglContext::simple('user1', 'test')))->toBeFalse();
            expect($decorator->get('test-feature', TogglContext::simple('user2', 'test')))->toBeFalse(); // Line 493
        });

        test('__call with method name "for" does not call defaultContextResolver', function (): void {
            // Arrange
            $resolverCalled = false;
            $manager = app(FeatureManager::class);
            $decorator = new Decorator(
                'test',
                new ArrayDriver(app(Dispatcher::class), []),
                function () use (&$resolverCalled): TogglContext {
                    $resolverCalled = true;

                    return TogglContext::simple('default-user', 'test');
                },
                app(Container::class),
                new ArrayGroupRepository(),
                new ArrayFeatureGroupMembershipRepository($manager),
            );
            $decorator->define('test-feature', fn (): true => true);

            // Act - Call 'for' method specifically (line 189)
            $result = $decorator->for(TogglContext::simple('specific-user', 'test'))->active('test-feature');

            // Assert - Resolver should NOT be called when method name is 'for'
            expect($resolverCalled)->toBeFalse(); // Line 189: $name !== 'for'
            expect($result)->toBeTrue();
        });

        test('define with class having no resolve method but callable via __invoke', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act - Line 224: method_exists check for 'resolve'
            $decorator->define(TestFeature::class);

            // Assert - Should use __invoke as fallback (line 230-231)
            expect($decorator->get('test-feature', TogglContext::simple('user1', 'test')))->toBe('invoked-value');
        });

        test('define with LazilyResolvedFeature passes through to driver', function (): void {
            // Arrange
            $decorator = createDecorator();
            $lazilyResolved = new LazilyResolvedFeature('lazy-feature', fn (): string => 'lazy-value');

            // Act - Line 252: resolver instanceof LazilyResolvedFeature
            $decorator->define('lazy-feature', $lazilyResolved);

            // Assert
            expect($decorator->get('lazy-feature', TogglContext::simple('user1', 'test')))->toBe('lazy-value');
        });

        test('define with non-closure resolver wraps value', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act - Lines 266-268: !$resolver instanceof Closure
            $decorator->define('static-feature', 'static-value');

            // Assert
            expect($decorator->get('static-feature', TogglContext::simple('user1', 'test')))->toBe('static-value');
        });

        test('getAll with empty features array returns empty array', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act - Line 318-320: isEmpty() check
            $result = $decorator->getAll([]);

            // Assert
            expect($result)->toBe([]);
        });

        test('getAll caches results via flatMap pipeline', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('cache-test', fn (): string => 'cached-value');

            // Act - Lines 325, 327-328: flatMap and each for cache population
            $decorator->getAll(['cache-test' => [TogglContext::simple('context1', 'test')]]);

            // Verify cache by checking second call doesn't re-resolve
            $callCount = 0;
            $decorator->define('cache-test', function () use (&$callCount): int {
                return ++$callCount;
            });

            $result = $decorator->get('cache-test', TogglContext::simple('context1', 'test'));

            // Assert - Should get cached value, not resolve again
            expect($callCount)->toBe(0); // Resolver not called due to cache
        });

        test('getAllMissing with all cached features returns empty array', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn (): true => true);
            $decorator->define('feature2', fn (): true => true);

            // Pre-cache all features
            $decorator->get('feature1', TogglContext::simple('user1', 'test'));
            $decorator->get('feature2', TogglContext::simple('user1', 'test'));

            // Act - Lines 350-354: reject when all contexts are cached
            $result = $decorator->getAllMissing([
                'feature1' => [TogglContext::simple('user1', 'test')],
                'feature2' => [TogglContext::simple('user1', 'test')],
            ]);

            // Assert - Should return empty because all are cached
            expect($result)->toBe([]);
        });

        test('setForAllContexts clears cache via reject', function (): void {
            // Arrange
            $decorator = createDecorator();
            $callCount = 0;
            $decorator->define('cache-clear-test', function () use (&$callCount): int {
                return ++$callCount;
            });

            // Pre-cache the feature
            $decorator->get('cache-clear-test', TogglContext::simple('user1', 'test'));

            expect($callCount)->toBe(1);

            // Act - Line 528: cache reject to clear feature's cached values
            $decorator->setForAllContexts('cache-clear-test', 999);

            // Verify cache was cleared by getting again
            $result = $decorator->get('cache-clear-test', TogglContext::simple('user1', 'test'));

            // Assert - Should get new global value, not resolve again
            expect($result)->toBe(999);
            expect($callCount)->toBe(1); // Not called again, uses global value
        });

        test('purge with array of features uses pipe and all methods', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('feature1', fn (): true => true);
            $decorator->define('feature2', fn (): true => true);
            $decorator->define('feature3', fn (): true => true);

            $decorator->get('feature1', TogglContext::simple('user1', 'test'));
            $decorator->get('feature2', TogglContext::simple('user1', 'test'));
            $decorator->get('feature3', TogglContext::simple('user1', 'test'));

            // Act - Line 571: pipe(function($features)) with $features->all()
            $decorator->purge(['feature1', 'feature2']);

            // Verify by checking if features need to be re-resolved
            $callCount = 0;
            $decorator->define('feature1', function () use (&$callCount): int {
                return ++$callCount;
            });

            $result = $decorator->get('feature1', TogglContext::simple('user1', 'test'));

            // Assert - Feature1 was purged, needs re-resolve
            expect($callCount)->toBe(1);
        });

        test('normalizeFeaturesToLoad handles string input', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('single-feature', fn (): true => true);

            // Act - Line 1165: wrap() with string converts to array
            $result = $decorator->getAll('single-feature');

            // Assert
            expect($result)->toHaveKey('single-feature');
        });

        test('normalizeFeaturesToLoad resolves feature names via mapWithKeys', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define(TestFeature::class); // Maps to 'test-feature'

            // Act - Lines 1174, 1176: mapWithKeys with resolveFeature and resolveContext
            $result = $decorator->getAll([TestFeature::class => [TogglContext::simple('user1', 'test')]]);

            // Assert - Should resolve class to feature name
            expect($result)->toHaveKey('test-feature');
        });

        test('putInCache adds new entry when position is false', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('new-cache-entry', fn (): string => 'new-value');

            // Act - Line 1287: $position === false, add new entry
            $result = $decorator->get('new-cache-entry', TogglContext::simple('user1', 'test'));

            // Verify cache by getting again
            $result2 = $decorator->get('new-cache-entry', TogglContext::simple('user1', 'test'));

            // Assert - Both should return same cached value
            expect($result)->toBe('new-value');
            expect($result2)->toBe('new-value');
        });

        test('isCached returns false when feature not in cache', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('uncached-feature', fn (): true => true);

            // Act - Line 1261: search returns false for uncached feature
            // This is tested indirectly via getAllMissing which uses isCached
            $result = $decorator->getAllMissing(['uncached-feature' => [TogglContext::simple('user1', 'test')]]);

            // Assert - Should fetch the feature because it's not cached
            expect($result)->toHaveKey('uncached-feature');
        });

        test('isCached returns true when feature is in cache', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('cached-feature', fn (): true => true);

            // Pre-cache the feature
            $decorator->get('cached-feature', TogglContext::simple('user1', 'test'));

            // Act - Line 1263: search returns !== false for cached feature
            // Tested indirectly via getAllMissing which should not fetch cached features
            $result = $decorator->getAllMissing(['cached-feature' => [TogglContext::simple('user1', 'test')]]);

            // Assert - Should NOT fetch because it's cached (reject returns true)
            expect($result)->toBe([]);
        });

        test('putInCache updates existing entry at position', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('update-cache', fn (): string => 'initial');

            // Pre-cache
            $initial = $decorator->get('update-cache', TogglContext::simple('user1', 'test'));

            // Act - Line 1289: $position !== false, update existing
            $decorator->set('update-cache', TogglContext::simple('user1', 'test'), 'updated');
            $result = $decorator->get('update-cache', TogglContext::simple('user1', 'test'));

            // Assert
            expect($initial)->toBe('initial');
            expect($result)->toBe('updated');
        });

        test('removeFromCache handles position not found', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act - Line 1307: $position === false (feature not in cache)
            // This should not throw an error, just do nothing
            $decorator->delete('non-existent-feature', TogglContext::simple('user1', 'test'));

            // Assert - No exception should be thrown
            expect(true)->toBeTrue();
        });

        test('removeFromCache removes entry when position found', function (): void {
            // Arrange
            $decorator = createDecorator();
            $callCount = 0;
            $decorator->define('remove-test', function () use (&$callCount): int {
                return ++$callCount;
            });

            // Pre-cache
            $decorator->get('remove-test', TogglContext::simple('user1', 'test'));

            expect($callCount)->toBe(1);

            // Act - Line 1308: unset($this->cache[$position])
            $decorator->delete('remove-test', TogglContext::simple('user1', 'test'));
            $decorator->get('remove-test', TogglContext::simple('user1', 'test'));

            // Assert - Should resolve again after cache removal
            expect($callCount)->toBe(2);
        });

        test('dispatchFeatureEvent returns early when Dispatcher not bound', function (): void {
            // Arrange
            $container = app(Container::class);
            $manager = app(FeatureManager::class);

            // Create a new container without Dispatcher bound
            $newContainer = clone $container;

            if ($newContainer->bound(Dispatcher::class)) {
                $newContainer->forgetInstance(Dispatcher::class);
            }

            $decorator = new Decorator(
                'test',
                new ArrayDriver(app(Dispatcher::class), []),
                fn (): null => null,
                $newContainer,
                new ArrayGroupRepository(),
                new ArrayFeatureGroupMembershipRepository($manager),
            );

            $decorator->define('event-test', fn (): true => true);

            // Act - Line 1334: early return when Dispatcher not bound
            // Should not throw an exception
            $decorator->set('event-test', TogglContext::simple('user1', 'test'), true);

            // Assert - No exception thrown
            expect(true)->toBeTrue();
        });

        test('dispatchFeatureEvent dispatches FeatureDeactivated on false value', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('deactivate-test', fn (): true => true);

            $eventDispatched = false;
            app(Dispatcher::class)->listen(FeatureDeactivated::class, function () use (&$eventDispatched): void {
                $eventDispatched = true;
            });

            // Act - Line 1341: dispatch FeatureDeactivated when $value === false
            $decorator->set('deactivate-test', TogglContext::simple('user1', 'test'), false);

            // Assert
            expect($eventDispatched)->toBeTrue();
        });

        test('dispatchFeatureEvent dispatches FeatureActivated on non-false value', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('activate-test', fn (): false => false);

            $eventDispatched = false;
            app(Dispatcher::class)->listen(FeatureActivated::class, function () use (&$eventDispatched): void {
                $eventDispatched = true;
            });

            // Act - Line 1345: dispatch FeatureActivated when $value !== false
            $decorator->set('activate-test', TogglContext::simple('user1', 'test'), true);

            // Assert
            expect($eventDispatched)->toBeTrue();
        });

        test('checkFeatureViaFeatureGroupMembership returns false when group value is null', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('null-test', fn (): false => false);
            $decorator->defineGroup('null-group', ['null-test']);

            // Add context to group
            $decorator->groups()->assign('null-group', TogglContext::simple('user-null', 'test'));

            // Set feature value to null (not false, not true)
            $decorator->set('null-test', TogglContext::simple('__all__', 'test'), null);

            // Act - Line 1081: $groupValue !== false && $groupValue !== null
            $result = $decorator->get('null-test', TogglContext::simple('user-null', 'test'));

            // Assert - Should return false because null is rejected
            expect($result)->toBeFalse();
        });

        test('resolve with resolver accepting two parameters passes global context', function (): void {
            // Arrange
            $decorator = createDecorator();
            $contextReceived = false;
            $globalContextReceived = false;

            // Define feature with resolver that accepts 2 parameters
            $decorator->define('two-param-feature', function ($context, $globalContext) use (&$contextReceived, &$globalContextReceived): true {
                $contextReceived = $context !== null;
                $globalContextReceived = true; // Second parameter was passed

                return true;
            });

            // Act - Line 1126: $paramCount >= 2 branch
            $result = $decorator->get('two-param-feature', TogglContext::simple('user1', 'test'));

            // Assert - Both parameters should be passed (global context may be null by default)
            expect($result)->toBeTrue();
            expect($contextReceived)->toBeTrue();
            expect($globalContextReceived)->toBeTrue(); // Second param was passed
        });

        test('resolve with resolver accepting one parameter only passes context', function (): void {
            // Arrange
            $decorator = createDecorator();
            $paramCount = 0;

            // Define feature with resolver that accepts 1 parameter
            $decorator->define('one-param-feature', function ($context) use (&$paramCount): true {
                $paramCount = 1;

                return true;
            });

            // Act - Line 1126: $paramCount >= 2 is false, use single param
            $result = $decorator->get('one-param-feature', TogglContext::simple('user1', 'test'));

            // Assert
            expect($result)->toBeTrue();
            expect($paramCount)->toBe(1);
        });

        test('__call when defaultContextResolver returns null', function (): void {
            // Arrange
            $decorator = createDecorator(); // Uses fn(): null => null
            $decorator->define('null-context-feature', fn (): true => true);

            // Act - Line 189: when ($this->defaultContextResolver)() === null
            // This should NOT call $interaction->for() because defaultContextResolver returns null
            $result = $decorator->active('null-context-feature');

            // Assert - Feature should still work with null context
            expect($result)->toBeTrue();
        });

        test('getAll triggers flatMap cache pipeline with multiple contexts', function (): void {
            // Arrange
            $decorator = createDecorator();
            $resolveCount = 0;

            $decorator->define('pipeline-test', function () use (&$resolveCount): string {
                ++$resolveCount;

                return 'value-'.$resolveCount;
            });

            // Act - Lines 325, 327-328: flatMap->zip->map->each pipeline
            $results = $decorator->getAll([
                'pipeline-test' => [TogglContext::simple('ctx1', 'test'), TogglContext::simple('ctx2', 'test'), TogglContext::simple('ctx3', 'test')],
            ]);

            // Verify all contexts were processed through the pipeline
            expect($results)->toHaveKey('pipeline-test');
            expect($results['pipeline-test'])->toHaveCount(3);
            expect($resolveCount)->toBe(3); // Resolved 3 times for 3 contexts

            // Verify cache works - second call should not re-resolve
            $decorator->get('pipeline-test', TogglContext::simple('ctx1', 'test'));
            expect($resolveCount)->toBe(3); // Still 3, used cache
        });

        test('getAllMissing executes reject and pipe branches', function (): void {
            // Arrange
            $decorator = createDecorator();
            $callCount = 0;

            $decorator->define('miss-test', function () use (&$callCount): string {
                ++$callCount;

                return 'value';
            });

            // Act - Lines 350-354: map->reject (via isCached)->reject (empty arrays)->pipe
            // Just verify the pipeline executes and filters correctly
            $results = $decorator->getAllMissing([
                'miss-test' => [TogglContext::simple('ctx1', 'test'), TogglContext::simple('ctx2', 'test')],
            ]);

            // Assert - Pipeline should execute and fetch features
            expect($callCount)->toBeGreaterThan(0); // Resolved at least once
            expect($results)->toBeArray(); // Pipeline completed
        });

        test('setForAllContexts executes cache reject branch', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('reject-cache-test', fn (): string => 'initial');

            // Cache multiple contexts
            $decorator->get('reject-cache-test', TogglContext::simple('ctx1', 'test'));
            $decorator->get('reject-cache-test', TogglContext::simple('ctx2', 'test'));
            $decorator->get('reject-cache-test', TogglContext::simple('ctx3', 'test'));

            // Act - Line 528: cache->reject() to remove all cached entries for this feature
            $decorator->setForAllContexts('reject-cache-test', 'global-value');

            // Verify all cached contexts were cleared
            $result1 = $decorator->get('reject-cache-test', TogglContext::simple('ctx1', 'test'));
            $result2 = $decorator->get('reject-cache-test', TogglContext::simple('ctx2', 'test'));
            $result3 = $decorator->get('reject-cache-test', TogglContext::simple('ctx3', 'test'));

            // Assert - All should return the global value
            expect($result1)->toBe('global-value');
            expect($result2)->toBe('global-value');
            expect($result3)->toBe('global-value');
        });

        test('purge with array executes pipe closure', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('purge-pipe-1', fn (): true => true);
            $decorator->define('purge-pipe-2', fn (): true => true);
            $decorator->define('purge-pipe-3', fn (): true => true);

            // Cache all features
            $decorator->get('purge-pipe-1', TogglContext::simple('user1', 'test'));
            $decorator->get('purge-pipe-2', TogglContext::simple('user1', 'test'));
            $decorator->get('purge-pipe-3', TogglContext::simple('user1', 'test'));

            // Act - Line 571: ->pipe(function($features): void {...})
            $decorator->purge(['purge-pipe-1', 'purge-pipe-2']);

            // Verify by checking if features need re-resolution
            $resolveCount = 0;
            $decorator->define('purge-pipe-1', function () use (&$resolveCount): int {
                return ++$resolveCount;
            });

            $result = $decorator->get('purge-pipe-1', TogglContext::simple('user1', 'test'));

            // Assert - Feature was purged and re-resolved
            expect($resolveCount)->toBe(1);
        });

        test('normalizeFeaturesToLoad executes all mapWithKeys branches', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('norm-test-1', fn (TogglContext $ctx): string => 'v1-'.$ctx->id);
            $decorator->define('norm-test-2', fn (TogglContext $ctx): string => 'v2-'.$ctx->id);

            // Act - Lines 1165, 1174, 1176: multiple mapWithKeys and map operations
            // Mix of integer keys (line 1166-1169) and string keys (line 1172)
            $results = $decorator->getAll([
                'norm-test-1',                           // Integer key -> uses defaultContext
                'norm-test-2' => [TogglContext::simple('ctx-a', 'test'), TogglContext::simple('ctx-b', 'test')],     // String key -> uses provided contexts
            ]);

            // Assert - Both paths executed
            expect($results)->toHaveKey('norm-test-1');
            expect($results)->toHaveKey('norm-test-2');
            expect($results['norm-test-2'])->toHaveCount(2);
        });

        test('isCached search lambda is executed', function (): void {
            // Arrange
            $decorator = createDecorator();
            $resolveCount = 0;

            $decorator->define('cache-search-test', function () use (&$resolveCount): string {
                ++$resolveCount;

                return 'value-'.$resolveCount;
            });

            // Pre-cache by calling get
            $first = $decorator->get('cache-search-test', TogglContext::simple('ctx1', 'test'));
            expect($resolveCount)->toBe(1);

            // Act - Line 1261: $this->cache->search(fn($item) => ...) lambda
            // Call get again with same context - should use cache (isCached returns true)
            $second = $decorator->get('cache-search-test', TogglContext::simple('ctx1', 'test'));

            // Assert - Should return cached value without re-resolving
            expect($resolveCount)->toBe(1); // Still 1, cache was used
            expect($first)->toBe($second); // Same cached value
        });

        test('putInCache search lambda is executed on cache update', function (): void {
            // Arrange
            $decorator = createDecorator();
            $decorator->define('put-search-test', fn (): string => 'initial');

            // Pre-cache
            $initialValue = $decorator->get('put-search-test', TogglContext::simple('user1', 'test'));

            // Act - Line 1282: $this->cache->search(fn($item) => ...) lambda
            // Triggers when updating an existing cache entry
            $decorator->set('put-search-test', TogglContext::simple('user1', 'test'), 'updated');
            $updatedValue = $decorator->get('put-search-test', TogglContext::simple('user1', 'test'));

            // Assert - Cache was searched and updated
            expect($initialValue)->toBe('initial');
            expect($updatedValue)->toBe('updated');
        });

        test('removeFromCache search lambda is executed', function (): void {
            // Arrange
            $decorator = createDecorator();
            $resolveCount = 0;
            $decorator->define('remove-search-test', function () use (&$resolveCount): int {
                return ++$resolveCount;
            });

            // Pre-cache
            $decorator->get('remove-search-test', TogglContext::simple('user1', 'test'));

            expect($resolveCount)->toBe(1);

            // Act - Line 1304: $this->cache->search(fn($item) => ...) lambda
            // Executes when delete() calls removeFromCache()
            $decorator->delete('remove-search-test', TogglContext::simple('user1', 'test'));

            // Verify cache was removed by checking if feature re-resolves
            $decorator->get('remove-search-test', TogglContext::simple('user1', 'test'));

            // Assert - Feature was re-resolved after cache removal
            expect($resolveCount)->toBe(2);
        });

        test('define with resolve method uses instance resolve', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act - Line 224: if (method_exists($instance, 'resolve'))
            $decorator->define(TestFeatureWithResolve::class);

            // Assert - Should use the resolve() method
            $result = $decorator->get('test-feature-resolve', TogglContext::simple('user1', 'test'));
            expect($result)->toBe('resolved-value');
        });

        test('define with LazilyResolvedFeature uses getResolver', function (): void {
            // Arrange
            $decorator = createDecorator();
            $lazilyResolved = new LazilyResolvedFeature('lazy-test', fn (): string => 'lazy-result');

            // Act - Line 252: if ($resolver instanceof LazilyResolvedFeature)
            $decorator->define('lazy-test', $lazilyResolved);

            // Assert - Should use LazilyResolvedFeature's resolver
            $result = $decorator->get('lazy-test', TogglContext::simple('user1', 'test'));
            expect($result)->toBe('lazy-result');
        });

        test('define with non-Closure wraps in lambda', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act - Lines 266-268: if (!$resolver instanceof Closure)
            $decorator->define('static-wrap-test', 'static-string-value');

            // Assert - Static value should be wrapped and returned
            $result = $decorator->get('static-wrap-test', TogglContext::simple('user1', 'test'));
            expect($result)->toBe('static-string-value');
        });

        test('isCached uses global context serialization from FeatureManager', function (): void {
            // Arrange
            $decorator = createDecorator();
            $manager = app(FeatureManager::class);
            $decorator->define('context-test', fn (): string => 'value');

            // Set a global context in FeatureManager
            $manager->context()->to('global-context-data');

            // Act - Lines 1299-1305: isCached serializes both context and globalContext
            $result = $decorator->get('context-test', TogglContext::simple('user1', 'test'));

            // Verify cache is used on second call (isCached returns true)
            $callCount = 0;
            $decorator->define('context-test', function () use (&$callCount): int {
                return ++$callCount;
            });

            $result2 = $decorator->get('context-test', TogglContext::simple('user1', 'test'));

            // Assert - Second call should use cache (callCount stays 0)
            expect($result)->toBe('value');
            expect($callCount)->toBe(0); // Cache was used, resolver not called
        });

        test('putInCache uses global context serialization from FeatureManager', function (): void {
            // Arrange
            $decorator = createDecorator();
            $manager = app(FeatureManager::class);

            // Set a global context in FeatureManager
            $manager->context()->to('test-global-context');

            $decorator->define('cache-update-test', fn (): string => 'initial');

            // Act - Lines 1320-1326: putInCache serializes globalContext and updates cache
            $initial = $decorator->get('cache-update-test', TogglContext::simple('user1', 'test'));
            $decorator->set('cache-update-test', TogglContext::simple('user1', 'test'), 'updated');
            $updated = $decorator->get('cache-update-test', TogglContext::simple('user1', 'test'));

            // Assert - Cache update path was executed with global context
            expect($initial)->toBe('initial');
            expect($updated)->toBe('updated');
        });

        test('__call sets default context when not "for" method and defaultContextResolver returns value', function (): void {
            // Arrange
            $manager = app(FeatureManager::class);
            $defaultContext = TogglContext::simple('default-user-123', 'test');
            $decorator = new Decorator(
                'test',
                new ArrayDriver(app(Dispatcher::class), []),
                fn (): TogglContext => $defaultContext,
                app(Container::class),
                new ArrayGroupRepository(),
                new ArrayFeatureGroupMembershipRepository($manager),
            );

            // Define a feature that checks the context to verify it was set
            $contextUsed = null;
            $decorator->define('tap-test', function (TogglContext $context) use (&$contextUsed): true {
                $contextUsed = $context;

                return true;
            });

            // Act - Lines 189-194: tap callback sets default context
            $result = $decorator->active('tap-test');

            // Assert - Default context should be set by tap callback
            expect($result)->toBeTrue();
            expect($contextUsed->id)->toBe('default-user-123');
        });

        test('define with class containing resolve method executes resolve path', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act - Lines 224-234: Auto-discovery with resolve() method
            $decorator->define(TestFeatureWithResolve::class);

            // Assert - Should use the resolve() method from the class
            expect($decorator->defined())->toContain('test-feature-resolve');
            $result = $decorator->get('test-feature-resolve', TogglContext::simple('user1', 'test'));
            expect($result)->toBe('resolved-value');
        });

        test('define with LazilyResolvedFeature calls getResolver and resolves', function (): void {
            // Arrange
            $decorator = createDecorator();
            $lazilyResolved = new LazilyResolvedFeature('lazy-resolver-test', fn (): string => 'lazy-value');

            // Act - Lines 252-257: LazilyResolvedFeature resolver retrieval
            $decorator->define('lazy-resolver-test', $lazilyResolved);

            // Assert - Should retrieve resolver and resolve correctly
            $result = $decorator->get('lazy-resolver-test', TogglContext::simple('user1', 'test'));
            expect($result)->toBe('lazy-value');
        });

        test('define with non-closure resolver wraps value in callable', function (): void {
            // Arrange
            $decorator = createDecorator();

            // Act - Lines 266-272: Non-closure resolver wrapping
            $decorator->define('wrap-test', 'static-value');

            // Assert - Should wrap static value and return it
            $result = $decorator->get('wrap-test', TogglContext::simple('user1', 'test'));
            expect($result)->toBe('static-value');
        });

        test('putInCache updates existing cache entry at specific position', function (): void {
            // Arrange
            $decorator = createDecorator();
            $manager = app(FeatureManager::class);

            // Set global context to ensure consistent serialization
            $manager->context()->to('test-context');

            $decorator->define('update-position-test', fn (): string => 'original');

            // Pre-cache the feature
            $original = $decorator->get('update-position-test', TogglContext::simple('user1', 'test'));

            // Act - Lines 1323-1326: Update existing cache entry at position
            $decorator->set('update-position-test', TogglContext::simple('user1', 'test'), 'modified');
            $modified = $decorator->get('update-position-test', TogglContext::simple('user1', 'test'));

            // Assert - Cache entry was updated at the same position
            expect($original)->toBe('original');
            expect($modified)->toBe('modified');
        });

        test('isCached correctly identifies cached features with global context', function (): void {
            // Arrange
            $decorator = createDecorator();
            $manager = app(FeatureManager::class);

            // Set a specific global context
            $manager->context()->to(['tenant' => 'test-tenant']);

            $resolveCount = 0;
            $decorator->define('cached-check-test', function () use (&$resolveCount): string {
                ++$resolveCount;

                return 'value-'.$resolveCount;
            });

            // Act - Lines 1299-1305: isCached with context serialization
            // First call - not cached
            $first = $decorator->get('cached-check-test', TogglContext::simple('user1', 'test'));

            // Second call - should be cached
            $second = $decorator->get('cached-check-test', TogglContext::simple('user1', 'test'));

            // Assert - Second call used cache (resolve count stayed at 1)
            expect($first)->toBe('value-1');
            expect($second)->toBe('value-1');
            expect($resolveCount)->toBe(1); // Only resolved once due to cache
        });
    });
});

/**
 * Create a Decorator instance for testing.
 *
 * Factory function that constructs a Decorator with an ArrayDriver backend,
 * array-based group repositories, and null default context resolver. Provides
 * a fully configured Decorator instance for testing high-level feature flag
 * operations without external dependencies on databases or caches.
 *
 * @return Decorator Configured decorator instance ready for testing
 */
function createDecorator(): Decorator
{
    $manager = app(FeatureManager::class);

    return new Decorator(
        'test',
        new ArrayDriver(app(Dispatcher::class), []),
        fn (): TogglContext => TogglContext::simple('default', 'test'),
        app(Container::class),
        new ArrayGroupRepository(),
        new ArrayFeatureGroupMembershipRepository($manager),
    );
}

/**
 * Test feature class with __invoke method for auto-discovery testing.
 *
 * Demonstrates feature class pattern where the feature name is defined as a
 * property and resolution logic is in the __invoke method. Used to test the
 * Decorator's ability to automatically discover and register class-based features.
 *
 * @internal
 *
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
 * Test feature class with resolve method for auto-discovery testing.
 *
 * Demonstrates alternative feature class pattern using a resolve() method
 * instead of __invoke(). Tests the Decorator's fallback resolution strategy
 * when __invoke is not available but a resolve method exists.
 *
 * @internal
 *
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
 * Test implementation of TogglContextable for context serialization testing.
 *
 * Demonstrates custom context types that can provide their own feature identifier
 * serialization logic. Used to test the Decorator's handling of objects that
 * implement the TogglContextable contract for customized context resolution.
 *
 * @internal
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class TestContextable implements TogglContextable
{
    public function toTogglContext(): TogglContext
    {
        return new TogglContext(
            id: 'contextable-id',
            type: self::class,
        );
    }
}

/**
 * Test feature class without name property for fallback testing.
 *
 * Demonstrates feature classes that don't define a name property, forcing
 * the Decorator to use the fully qualified class name as the feature identifier.
 * Tests the fallback behavior when feature name auto-discovery fails.
 *
 * @internal
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class TestFeatureWithoutName
{
    public function __invoke(): string
    {
        return 'no-name-value';
    }
}
