<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Drivers\ArrayDriver;
use Cline\Toggl\Events\UnknownFeatureResolved;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

/**
 * ArrayDriver test suite.
 *
 * Tests the in-memory array-based feature flag driver, verifying feature definition,
 * resolution, caching, bulk operations, and event dispatching. The ArrayDriver stores
 * all feature flags and their resolved values in memory, providing fast access without
 * external dependencies on databases or caches.
 */
describe('ArrayDriver', function (): void {
    describe('Happy Path', function (): void {
        test('defines a feature resolver', function (): void {
            // Arrange
            $driver = createArrayDriver();

            // Act
            $result = $driver->define('test-feature', fn (): true => true);

            // Assert
            expect($result)->toBeNull();
            expect($driver->defined())->toContain('test-feature');
        });

        test('defines a feature with static value', function (): void {
            // Arrange
            $driver = createArrayDriver();

            // Act
            $result = $driver->define('static-feature', true);

            // Assert
            expect($result)->toBeNull();
            expect($driver->defined())->toContain('static-feature');
            expect($driver->get('static-feature', TogglContext::simple('user', 'test')))->toBeTrue();
        });

        test('retrieves defined feature names', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): false => false);

            // Act
            $defined = $driver->defined();

            // Assert
            expect($defined)->toBeArray();
            expect($defined)->toContain('feature1');
            expect($defined)->toContain('feature2');
        });

        test('retrieves stored feature names from memory', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): false => false);

            // Trigger resolution to store in memory
            $driver->get('feature1', TogglContext::simple('user1', 'test'));
            $driver->get('feature2', TogglContext::simple('user2', 'test'));

            // Act
            $stored = $driver->stored();

            // Assert
            expect($stored)->toBeArray();
            expect($stored)->toContain('feature1');
            expect($stored)->toContain('feature2');
        });

        test('gets feature value and caches it', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (TogglContext $context): bool => $context->id === 'admin');

            // Act
            $result = $driver->get('test-feature', TogglContext::simple('admin', 'test'));

            // Assert
            expect($result)->toBeTrue();
        });

        test('gets cached feature value on second call', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $callCount = 0;
            $driver->define('test-feature', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });

            // Act
            $result1 = $driver->get('test-feature', TogglContext::simple('user', 'test'));
            $result2 = $driver->get('test-feature', TogglContext::simple('user', 'test'));

            // Assert
            expect($result1)->toBeTrue();
            expect($result2)->toBeTrue();
            expect($callCount)->toBe(1); // Resolver only called once
        });

        test('sets feature value in memory', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): false => false);

            // Act
            $driver->set('test-feature', TogglContext::simple('user', 'test'), true);

            $result = $driver->get('test-feature', TogglContext::simple('user', 'test'));

            // Assert
            expect($result)->toBeTrue();
        });

        test('updates existing feature value in memory', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): false => false);
            $driver->set('test-feature', TogglContext::simple('user', 'test'), false);

            // Act
            $driver->set('test-feature', TogglContext::simple('user', 'test'), true);

            $result = $driver->get('test-feature', TogglContext::simple('user', 'test'));

            // Assert
            expect($result)->toBeTrue();
        });

        test('sets feature value for all contexts', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): false => false);
            $driver->get('test-feature', TogglContext::simple('user1', 'test'));
            $driver->get('test-feature', TogglContext::simple('user2', 'test'));
            $driver->get('test-feature', TogglContext::simple('user3', 'test'));

            // Act
            $driver->setForAllContexts('test-feature', true);

            // Assert
            expect($driver->get('test-feature', TogglContext::simple('user1', 'test')))->toBeTrue();
            expect($driver->get('test-feature', TogglContext::simple('user2', 'test')))->toBeTrue();
            expect($driver->get('test-feature', TogglContext::simple('user3', 'test')))->toBeTrue();
        });

        test('deletes feature value from memory', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): true => true);
            $driver->get('test-feature', TogglContext::simple('user', 'test'));

            // Act
            $driver->delete('test-feature', TogglContext::simple('user', 'test'));

            // Need to redefine to avoid unknown feature event
            $callCount = 0;
            $driver->define('test-feature', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });
            $result = $driver->get('test-feature', TogglContext::simple('user', 'test'));

            // Assert - Resolver should be called again since cache was deleted
            expect($callCount)->toBe(1);
        });

        test('purges all features from memory when null provided', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): true => true);
            $driver->get('feature1', TogglContext::simple('user', 'test'));
            $driver->get('feature2', TogglContext::simple('user', 'test'));

            // Act
            $driver->purge(null);

            // Assert
            expect($driver->stored())->toBe([]);
        });

        test('purges specific features from memory', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): true => true);
            $driver->get('feature1', TogglContext::simple('user', 'test'));
            $driver->get('feature2', TogglContext::simple('user', 'test'));

            // Act
            $driver->purge(['feature1']);

            // Assert
            $stored = $driver->stored();
            expect($stored)->not->toContain('feature1');
            expect($stored)->toContain('feature2');
        });

        test('gets all features in bulk', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('feature1', fn (TogglContext $context): bool => $context->id === 'admin');
            $driver->define('feature2', fn (): true => true);

            // Act
            $results = $driver->getAll([
                'feature1' => [TogglContext::simple('admin', 'test'), TogglContext::simple('user', 'test')],
                'feature2' => [TogglContext::simple('admin', 'test'), TogglContext::simple('user', 'test')],
            ]);

            // Assert
            expect($results['feature1'][0])->toBeTrue();
            expect($results['feature1'][1])->toBeFalse();
            expect($results['feature2'][0])->toBeTrue();
            expect($results['feature2'][1])->toBeTrue();
        });

        test('gets all features with nested closure execution for multiple features and contexts', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $feature1CallCount = 0;
            $feature2CallCount = 0;
            $feature3CallCount = 0;

            $driver->define('feature1', function (TogglContext $context) use (&$feature1CallCount): bool {
                ++$feature1CallCount;

                return $context->id === 'admin';
            });
            $driver->define('feature2', function (TogglContext $context) use (&$feature2CallCount): bool {
                ++$feature2CallCount;

                return $context->id === 'user';
            });
            $driver->define('feature3', function () use (&$feature3CallCount): bool {
                ++$feature3CallCount;

                return true;
            });

            // Act - This exercises lines 119-120 (nested map closures)
            $results = $driver->getAll([
                'feature1' => [TogglContext::simple('admin', 'test'), TogglContext::simple('user', 'test'), TogglContext::simple('guest', 'test')],
                'feature2' => [TogglContext::simple('admin', 'test'), TogglContext::simple('user', 'test'), TogglContext::simple('guest', 'test')],
                'feature3' => [TogglContext::simple('admin', 'test'), TogglContext::simple('user', 'test')],
            ]);

            // Assert - Verify nested closures executed for all features and contexts
            expect($results)->toHaveKeys(['feature1', 'feature2', 'feature3']);
            expect($results['feature1'])->toBe([true, false, false]);
            expect($results['feature2'])->toBe([false, true, false]);
            expect($results['feature3'])->toBe([true, true]);
            expect($feature1CallCount)->toBe(3); // Called for 3 contexts
            expect($feature2CallCount)->toBe(3); // Called for 3 contexts
            expect($feature3CallCount)->toBe(2); // Called for 2 contexts
        });

        test('get method resolves and stores unknown feature returning false', function (): void {
            // Arrange
            Event::fake([UnknownFeatureResolved::class]);
            $driver = createArrayDriver();

            // Act - This exercises lines 145-146 (unknownFeatureValue branch)
            $result = $driver->get('undefined-feature', TogglContext::simple('user', 'test'));

            // Assert - Verify the with() callback returned false for unknown feature
            expect($result)->toBeFalse();
            Event::assertDispatched(UnknownFeatureResolved::class);

            // Verify it was NOT stored (unknown features don't get cached)
            expect($driver->stored())->not->toContain('undefined-feature');
        });

        test('get method resolves and stores real feature value', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $callCount = 0;
            $driver->define('test-feature', function (TogglContext $context) use (&$callCount): string {
                ++$callCount;

                return 'resolved-'.$context->id;
            });

            // Act - This exercises lines 149-151 (set and return value branch)
            $result = $driver->get('test-feature', TogglContext::simple('admin', 'test'));

            // Assert - Verify the with() callback stored and returned the value
            expect($result)->toBe('resolved-admin');
            expect($callCount)->toBe(1);

            // Verify it WAS stored in cache
            expect($driver->stored())->toContain('test-feature');

            // Second call should use cached value (not call resolver again)
            $result2 = $driver->get('test-feature', TogglContext::simple('admin', 'test'));
            expect($result2)->toBe('resolved-admin');
            expect($callCount)->toBe(1); // Still 1, not called again
        });

        test('flushes all cached feature values', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $callCount = 0;
            $driver->define('test-feature', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });
            $driver->get('test-feature', TogglContext::simple('user', 'test'));

            // Act
            $driver->flushCache();
            $driver->get('test-feature', TogglContext::simple('user', 'test'));

            // Assert - Resolver should be called twice (before and after flush)
            expect($callCount)->toBe(2);
        });
    });

    describe('Sad Path', function (): void {
        test('dispatches unknown feature event when feature not defined', function (): void {
            // Arrange
            Event::fake([UnknownFeatureResolved::class]);
            $driver = createArrayDriver();

            // Act
            $result = $driver->get('unknown-feature', TogglContext::simple('user', 'test'));

            // Assert
            expect($result)->toBeFalse();
            Event::assertDispatched(UnknownFeatureResolved::class, fn ($event): bool => $event->feature === 'unknown-feature' && $event->context->id === 'user');
        });
    });

    describe('Edge Cases', function (): void {
        test('stores complex values', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): array => ['key' => 'value', 'nested' => ['data' => 123]]);

            // Act
            $result = $driver->get('test-feature', TogglContext::simple('user', 'test'));

            // Assert
            expect($result)->toBe(['key' => 'value', 'nested' => ['data' => 123]]);
        });

        test('distinguishes between false and unknown feature', function (): void {
            // Arrange
            Event::fake([UnknownFeatureResolved::class]);
            $driver = createArrayDriver();
            $driver->define('false-feature', fn (): false => false);

            // Act
            $definedResult = $driver->get('false-feature', TogglContext::simple('user', 'test'));
            $unknownResult = $driver->get('unknown-feature', TogglContext::simple('user', 'test'));

            // Assert
            expect($definedResult)->toBeFalse();
            expect($unknownResult)->toBeFalse();
            Event::assertDispatched(UnknownFeatureResolved::class);
            Event::assertDispatchedTimes(UnknownFeatureResolved::class, 1); // Only for unknown
        });

        test('handles empty getAll request', function (): void {
            // Arrange
            $driver = createArrayDriver();

            // Act
            $results = $driver->getAll([]);

            // Assert
            expect($results)->toBe([]);
        });

        test('handles multiple contexts for same feature in getAll', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (TogglContext $context): bool => $context->id === 'admin');

            // Act
            $results = $driver->getAll([
                'test-feature' => [TogglContext::simple('admin', 'test'), TogglContext::simple('user', 'test'), TogglContext::simple('guest', 'test')],
            ]);

            // Assert
            expect($results['test-feature'][0])->toBeTrue();
            expect($results['test-feature'][1])->toBeFalse();
            expect($results['test-feature'][2])->toBeFalse();
        });

        test('purges empty array of features', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->get('feature1', TogglContext::simple('user', 'test'));

            // Act
            $driver->purge([]);

            // Assert
            expect($driver->stored())->toContain('feature1');
        });

        test('setForAllContexts clears existing cached states', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): false => false);
            $driver->get('test-feature', TogglContext::simple('user1', 'test'));
            $driver->get('test-feature', TogglContext::simple('user2', 'test'));

            // Verify initial state is cached
            $storedBefore = $driver->stored();
            expect($storedBefore)->toContain('test-feature');

            // Act
            $driver->setForAllContexts('test-feature', true);

            // Assert - Cache should be cleared, so resolver will be called with new value
            $result1 = $driver->get('test-feature', TogglContext::simple('user1', 'test'));
            $result2 = $driver->get('test-feature', TogglContext::simple('user2', 'test'));
            expect($result1)->toBeTrue();
            expect($result2)->toBeTrue();
        });

        test('returns empty array for stored when no features resolved', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): false => false);

            // Act - Don't resolve any features
            $stored = $driver->stored();

            // Assert
            expect($stored)->toBe([]);
        });

        test('get method returns exact resolver value including null', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('null-feature', fn (): null => null);
            $driver->define('zero-feature', fn (): int => 0);
            $driver->define('empty-string-feature', fn (): string => '');

            // Act - This exercises line 149 with() return path for various falsy values
            $nullResult = $driver->get('null-feature', TogglContext::simple('user', 'test'));
            $zeroResult = $driver->get('zero-feature', TogglContext::simple('user', 'test'));
            $emptyStringResult = $driver->get('empty-string-feature', TogglContext::simple('user', 'test'));

            // Assert - Verify exact values are returned (not converted to false)
            expect($nullResult)->toBeNull();
            expect($zeroResult)->toBe(0);
            expect($emptyStringResult)->toBe('');

            // Verify they were all stored in cache
            expect($driver->stored())->toContain('null-feature');
            expect($driver->stored())->toContain('zero-feature');
            expect($driver->stored())->toContain('empty-string-feature');
        });

        test('get method with() closure captures feature and context variables', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $capturedContext = null;
            $driver->define('context-capture-feature', function (TogglContext $context) use (&$capturedContext): string {
                $capturedContext = $context;

                return 'processed-'.$context->id;
            });

            // Act - This ensures line 149 with() closure properly captures both $feature and $context
            $result = $driver->get('context-capture-feature', TogglContext::simple('complex-context-value', 'test'));

            // Assert - Verify context was passed through correctly
            expect($result)->toBe('processed-complex-context-value');
            expect($capturedContext->id)->toBe('complex-context-value');
        });

        test('get method executes with() helper return path for both unknown and known features', function (): void {
            // Arrange
            Event::fake([UnknownFeatureResolved::class]);
            $driver = createArrayDriver();
            $driver->define('known-feature', fn (TogglContext $context): string => 'value-for-'.$context->id);

            // Act - Execute line 149 with() for unknown feature (returns unknownFeatureValue)
            $unknownResult = $driver->get('undefined-feature', TogglContext::simple('ctx1', 'test'));

            // Act - Execute line 149 with() for known feature (returns actual value)
            $knownResult = $driver->get('known-feature', TogglContext::simple('ctx2', 'test'));

            // Assert - Both execution paths through line 149's with() are covered
            expect($unknownResult)->toBeFalse(); // Unknown path: line 149 -> 150-152
            expect($knownResult)->toBe('value-for-ctx2'); // Known path: line 149 -> 154-156
            Event::assertDispatched(UnknownFeatureResolved::class);
        });
    });
});

/**
 * Create an ArrayDriver instance for testing.
 *
 * Factory function that constructs a fresh ArrayDriver with the application's
 * event dispatcher and an empty feature set. Used throughout tests to create
 * isolated driver instances without shared state between test cases.
 *
 * @return ArrayDriver Configured array driver instance ready for testing
 */
function createArrayDriver(): ArrayDriver
{
    return new ArrayDriver(
        app(Dispatcher::class),
        [],
    );
}
