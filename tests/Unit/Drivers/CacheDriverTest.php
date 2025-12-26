<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Drivers\CacheDriver;
use Cline\Toggl\Events\UnknownFeatureResolved;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/**
 * CacheDriver test suite.
 *
 * Tests the cache-backed feature flag driver, verifying feature persistence using
 * Laravel's cache system. The CacheDriver stores resolved feature flags in the
 * configured cache store (Redis, Memcached, file, etc.), supporting TTL configuration,
 * custom prefixes, and persistent storage across requests. Tests cover cache hit/miss
 * behavior, bulk operations, configuration options, and index management.
 */
describe('CacheDriver', function (): void {
    /**
     * Clear the cache before each test to ensure isolation.
     */
    beforeEach(function (): void {
        Cache::flush();
    });

    describe('Happy Path', function (): void {
        test('defines a feature resolver', function (): void {
            // Arrange
            $driver = createCacheDriver();

            // Act
            $result = $driver->define('test-feature', fn (): true => true);

            // Assert
            expect($result)->toBeNull();
            expect($driver->defined())->toContain('test-feature');
        });

        test('defines a feature with static value', function (): void {
            // Arrange
            $driver = createCacheDriver();

            // Act
            $result = $driver->define('static-feature', true);

            // Assert
            expect($result)->toBeNull();
            expect($driver->defined())->toContain('static-feature');
            expect($driver->get('static-feature', TogglContext::simple('user', 'test')))->toBeTrue();
        });

        test('retrieves defined feature names', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): false => false);

            // Act
            $defined = $driver->defined();

            // Assert
            expect($defined)->toBeArray();
            expect($defined)->toContain('feature1');
            expect($defined)->toContain('feature2');
        });

        test('retrieves stored feature names from cache', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): false => false);

            // Trigger resolution to store in cache
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
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (TogglContext $context): bool => $context->id === 'admin');

            // Act
            $result = $driver->get('test-feature', TogglContext::simple('admin', 'test'));

            // Assert
            expect($result)->toBeTrue();
            expect(Cache::has('features:test-feature:test|admin'))->toBeTrue();
        });

        test('resolves and caches feature value on cache miss', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $callCount = 0;
            $driver->define('new-feature', function (TogglContext $context) use (&$callCount): string {
                ++$callCount;

                return 'resolved-value-'.$context->id;
            });

            // Ensure cache is empty
            Cache::flush();
            expect(Cache::has('features:new-feature:test|context1'))->toBeFalse();

            // Act - First call (cache miss, should resolve and cache)
            $result1 = $driver->get('new-feature', TogglContext::simple('context1', 'test'));

            // Assert - Value resolved, cached, and returned
            expect($result1)->toBe('resolved-value-context1');
            expect($callCount)->toBe(1);
            expect(Cache::has('features:new-feature:test|context1'))->toBeTrue();

            // Act - Second call (cache hit, should not resolve again)
            $result2 = $driver->get('new-feature', TogglContext::simple('context1', 'test'));

            // Assert - Same value returned from cache without resolving again
            expect($result2)->toBe('resolved-value-context1');
            expect($callCount)->toBe(1); // Still 1, not called again
        });

        test('gets cached feature value on second call', function (): void {
            // Arrange
            $driver = createCacheDriver();
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

        test('sets feature value in cache', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): false => false);

            // Act
            $driver->set('test-feature', TogglContext::simple('user', 'test'), true);

            $result = $driver->get('test-feature', TogglContext::simple('user', 'test'));

            // Assert
            expect($result)->toBeTrue();
            expect(Cache::has('features:test-feature:test|user'))->toBeTrue();
        });

        test('updates existing feature value in cache', function (): void {
            // Arrange
            $driver = createCacheDriver();
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
            $driver = createCacheDriver();
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

        test('deletes feature value from cache', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): true => true);
            $driver->get('test-feature', TogglContext::simple('user', 'test'));

            expect(Cache::has('features:test-feature:test|user'))->toBeTrue();

            // Act
            $driver->delete('test-feature', TogglContext::simple('user', 'test'));

            // Assert
            expect(Cache::has('features:test-feature:test|user'))->toBeFalse();
        });

        test('purges all features from cache when null provided', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): true => true);
            $driver->get('feature1', TogglContext::simple('user', 'test'));
            $driver->get('feature2', TogglContext::simple('user', 'test'));

            // Act
            $driver->purge(null);

            // Assert
            expect(Cache::has('features:feature1:test|user'))->toBeFalse();
            expect(Cache::has('features:feature2:test|user'))->toBeFalse();
        });

        test('purges specific features from cache', function (): void {
            // Arrange
            $driver = createCacheDriver();
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
            $driver = createCacheDriver();
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

        test('flushes all cached feature values', function (): void {
            // Arrange
            $driver = createCacheDriver();
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

        test('respects TTL configuration', function (): void {
            // Arrange
            config(['toggl.stores.cache.ttl' => 60]);
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): true => true);

            // Act
            $driver->set('test-feature', TogglContext::simple('user', 'test'), true);

            // Assert - Value should be in cache with TTL
            expect(Cache::has('features:test-feature:test|user'))->toBeTrue();
        });

        test('stores values forever when TTL is null', function (): void {
            // Arrange
            config(['toggl.stores.cache.ttl' => null]);
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): true => true);

            // Act
            $driver->set('test-feature', TogglContext::simple('user', 'test'), true);

            // Assert
            expect(Cache::has('features:test-feature:test|user'))->toBeTrue();
        });

        test('handles numeric string TTL', function (): void {
            // Arrange
            config(['toggl.stores.cache.ttl' => '120']);
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): true => true);

            // Act
            $driver->set('test-feature', TogglContext::simple('user', 'test'), true);

            // Assert - Value should be in cache with TTL
            expect(Cache::has('features:test-feature:test|user'))->toBeTrue();
        });

        test('handles float TTL by converting to int', function (): void {
            // Arrange
            config(['toggl.stores.cache.ttl' => 60.5]);
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): true => true);

            // Act
            $driver->set('test-feature', TogglContext::simple('user', 'test'), true);

            // Assert - Value should be in cache with TTL converted to int
            expect(Cache::has('features:test-feature:test|user'))->toBeTrue();
        });

        test('uses custom prefix from configuration', function (): void {
            // Arrange
            config(['toggl.stores.cache.prefix' => 'custom_prefix']);
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): true => true);

            // Act
            $driver->set('test-feature', TogglContext::simple('user', 'test'), true);

            // Assert
            expect(Cache::has('custom_prefix:test-feature:test|user'))->toBeTrue();
        });
    });

    describe('Sad Path', function (): void {
        test('dispatches unknown feature event when feature not defined', function (): void {
            // Arrange
            Event::fake([UnknownFeatureResolved::class]);
            $driver = createCacheDriver();

            // Act
            $result = $driver->get('unknown-feature', TogglContext::simple('user', 'test'));

            // Assert
            expect($result)->toBeFalse();
            Event::assertDispatched(UnknownFeatureResolved::class, fn ($event): bool => $event->feature === 'unknown-feature' && $event->context->id === 'user');
        });

        test('returns false on cache miss when resolveValue returns unknownFeatureValue', function (): void {
            // Arrange
            Event::fake([UnknownFeatureResolved::class]);
            $driver = createCacheDriver();

            // Ensure cache is empty
            Cache::flush();

            // Act - Get undefined feature (cache miss + unknown feature)
            $result = $driver->get('undefined-feature', TogglContext::simple('some-context', 'test'));

            // Assert - Should return false and not cache the result
            expect($result)->toBeFalse();
            expect(Cache::has('features:undefined-feature:test|some-context'))->toBeFalse();
            Event::assertDispatched(UnknownFeatureResolved::class);
        });

        test('caches and returns value on cache miss when resolveValue returns valid value', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('valid-feature', fn (): string => 'resolved-value');

            // Ensure cache is empty
            Cache::flush();
            expect(Cache::has('features:valid-feature:test|context1'))->toBeFalse();

            // Act - Get defined feature on cache miss (should resolve, cache, and return)
            $result = $driver->get('valid-feature', TogglContext::simple('context1', 'test'));

            // Assert - Should resolve, cache the value, and return it
            expect($result)->toBe('resolved-value');
            expect(Cache::has('features:valid-feature:test|context1'))->toBeTrue();
            expect(Cache::get('features:valid-feature:test|context1'))->toBe('resolved-value');
        });

        test('handles cache miss with false as resolved value', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('false-feature', fn (): false => false);

            // Ensure cache is empty
            Cache::flush();
            expect(Cache::has('features:false-feature:test|user'))->toBeFalse();

            // Act - Get feature that resolves to false (not unknown, but actual false)
            $result = $driver->get('false-feature', TogglContext::simple('user', 'test'));

            // Assert - Should cache false and return it (not treat as unknown)
            expect($result)->toBeFalse();
            expect(Cache::has('features:false-feature:test|user'))->toBeTrue();
            expect(Cache::get('features:false-feature:test|user'))->toBeFalse();
        });

        test('throws exception for non-numeric TTL configuration', function (): void {
            // Arrange
            config(['toggl.stores.cache.ttl' => 'invalid']);
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): true => true);

            // Act & Assert - Should throw InvalidArgumentException when getTtl is called
            expect(fn () => $driver->set('test-feature', TogglContext::simple('user', 'test'), true))
                ->toThrow(InvalidArgumentException::class, 'TTL configuration must be numeric or null');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles TogglContext with null id', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): true => true);

            // Act
            $result = $driver->get('test-feature', TogglContext::simple(null, 'test'));

            // Assert
            expect($result)->toBeTrue();
        });

        test('handles different TogglContext id types', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): true => true);

            // Act & Assert
            expect($driver->get('test-feature', TogglContext::simple('string-id', 'test')))->toBeTrue();
            expect($driver->get('test-feature', TogglContext::simple(123, 'test')))->toBeTrue();
            expect($driver->get('test-feature', TogglContext::simple(null, 'test')))->toBeTrue();
        });

        test('stores complex values', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): array => ['key' => 'value', 'nested' => ['data' => 123]]);

            // Act
            $result = $driver->get('test-feature', TogglContext::simple('user', 'test'));

            // Assert
            expect($result)->toBe(['key' => 'value', 'nested' => ['data' => 123]]);
        });

        test('distinguishes between false and unknown feature', function (): void {
            // Arrange
            Event::fake([UnknownFeatureResolved::class]);
            $driver = createCacheDriver();
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
            $driver = createCacheDriver();

            // Act
            $results = $driver->getAll([]);

            // Assert
            expect($results)->toBe([]);
        });

        test('handles multiple contexts for same feature in getAll', function (): void {
            // Arrange
            $driver = createCacheDriver();
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

        test('getAll executes nested closures for multiple features and contexts', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $callCounts = ['feature1' => 0, 'feature2' => 0, 'feature3' => 0];

            $driver->define('feature1', function (TogglContext $context) use (&$callCounts): bool {
                ++$callCounts['feature1'];

                return $context->id === 'admin';
            });
            $driver->define('feature2', function (TogglContext $context) use (&$callCounts): bool {
                ++$callCounts['feature2'];

                return $context->id !== 'guest';
            });
            $driver->define('feature3', function () use (&$callCounts): true {
                ++$callCounts['feature3'];

                return true;
            });

            // Act - Test with multiple features, each with multiple contexts
            $results = $driver->getAll([
                'feature1' => [TogglContext::simple('admin', 'test'), TogglContext::simple('user', 'test'), TogglContext::simple('guest', 'test')],
                'feature2' => [TogglContext::simple('admin', 'test'), TogglContext::simple('user', 'test'), TogglContext::simple('guest', 'test')],
                'feature3' => [TogglContext::simple('admin', 'test'), TogglContext::simple('user', 'test')],
            ]);

            // Assert - Verify nested closures executed for all features and contexts
            expect($results)->toHaveKeys(['feature1', 'feature2', 'feature3']);
            expect($results['feature1'])->toBe([true, false, false]);
            expect($results['feature2'])->toBe([true, true, false]);
            expect($results['feature3'])->toBe([true, true]);
            expect($callCounts['feature1'])->toBe(3); // Called for 3 contexts
            expect($callCounts['feature2'])->toBe(3); // Called for 3 contexts
            expect($callCounts['feature3'])->toBe(2); // Called for 2 contexts
        });

        test('purges empty array of features', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->get('feature1', TogglContext::simple('user', 'test'));

            // Act
            $driver->purge([]);

            // Assert
            expect($driver->stored())->toContain('feature1');
        });

        test('setForAllContexts updates resolver for new contexts', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('test-feature', fn (): false => false);
            $driver->get('test-feature', TogglContext::simple('user1', 'test'));
            $driver->get('test-feature', TogglContext::simple('user2', 'test'));

            // Act
            $driver->setForAllContexts('test-feature', true);

            // Assert - New context should get the updated value
            $result1 = $driver->get('test-feature', TogglContext::simple('new-user', 'test'));
            expect($result1)->toBeTrue();
        });

        test('returns empty array for stored when no features resolved', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): false => false);

            // Act - Don't resolve any features
            $stored = $driver->stored();

            // Assert
            expect($stored)->toBe([]);
        });

        test('maintains index when adding features', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): true => true);

            // Act
            $driver->get('feature1', TogglContext::simple('user', 'test'));
            $driver->get('feature2', TogglContext::simple('user', 'test'));

            // Assert
            $stored = $driver->stored();
            expect($stored)->toContain('feature1');
            expect($stored)->toContain('feature2');
            expect($stored)->toHaveCount(2);
        });

        test('does not duplicate features in index', function (): void {
            // Arrange
            $driver = createCacheDriver();
            $driver->define('feature1', fn (): true => true);

            // Act - Access same feature multiple times
            $driver->get('feature1', TogglContext::simple('user1', 'test'));
            $driver->get('feature1', TogglContext::simple('user2', 'test'));
            $driver->get('feature1', TogglContext::simple('user3', 'test'));

            // Assert
            $stored = $driver->stored();
            expect($stored)->toContain('feature1');
            expect($stored)->toHaveCount(1);
        });
    });
});

/**
 * Create a CacheDriver instance for testing.
 *
 * Factory function that constructs a fresh CacheDriver using Laravel's cache
 * repository and event dispatcher. The driver uses the application's default
 * cache store configuration and supports custom naming for multi-driver tests.
 *
 * @param  string      $name The driver name used for configuration lookups, defaults to 'cache'
 * @return CacheDriver Configured cache driver instance ready for testing
 */
function createCacheDriver(string $name = 'cache'): CacheDriver
{
    return new CacheDriver(
        resolve(CacheRepository::class),
        resolve(Dispatcher::class),
        $name,
        [],
    );
}
