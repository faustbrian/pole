<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Drivers\ArrayDriver;
use Cline\Pole\Events\UnknownFeatureResolved;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

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
            $driver->get('feature1', 'user1');
            $driver->get('feature2', 'user2');

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
            $driver->define('test-feature', fn ($scope): bool => $scope === 'admin');

            // Act
            $result = $driver->get('test-feature', 'admin');

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
            $result1 = $driver->get('test-feature', 'user');
            $result2 = $driver->get('test-feature', 'user');

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
            $driver->set('test-feature', 'user', true);

            $result = $driver->get('test-feature', 'user');

            // Assert
            expect($result)->toBeTrue();
        });

        test('updates existing feature value in memory', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): false => false);
            $driver->set('test-feature', 'user', false);

            // Act
            $driver->set('test-feature', 'user', true);

            $result = $driver->get('test-feature', 'user');

            // Assert
            expect($result)->toBeTrue();
        });

        test('sets feature value for all scopes', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): false => false);
            $driver->get('test-feature', 'user1');
            $driver->get('test-feature', 'user2');
            $driver->get('test-feature', 'user3');

            // Act
            $driver->setForAllScopes('test-feature', true);

            // Assert
            expect($driver->get('test-feature', 'user1'))->toBeTrue();
            expect($driver->get('test-feature', 'user2'))->toBeTrue();
            expect($driver->get('test-feature', 'user3'))->toBeTrue();
        });

        test('deletes feature value from memory', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): true => true);
            $driver->get('test-feature', 'user');

            // Act
            $driver->delete('test-feature', 'user');

            // Need to redefine to avoid unknown feature event
            $callCount = 0;
            $driver->define('test-feature', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });
            $result = $driver->get('test-feature', 'user');

            // Assert - Resolver should be called again since cache was deleted
            expect($callCount)->toBe(1);
        });

        test('purges all features from memory when null provided', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): true => true);
            $driver->get('feature1', 'user');
            $driver->get('feature2', 'user');

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
            $driver->get('feature1', 'user');
            $driver->get('feature2', 'user');

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
            $driver->define('feature1', fn ($scope): bool => $scope === 'admin');
            $driver->define('feature2', fn (): true => true);

            // Act
            $results = $driver->getAll([
                'feature1' => ['admin', 'user'],
                'feature2' => ['admin', 'user'],
            ]);

            // Assert
            expect($results['feature1'][0])->toBeTrue();
            expect($results['feature1'][1])->toBeFalse();
            expect($results['feature2'][0])->toBeTrue();
            expect($results['feature2'][1])->toBeTrue();
        });

        test('flushes all cached feature values', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $callCount = 0;
            $driver->define('test-feature', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });
            $driver->get('test-feature', 'user');

            // Act
            $driver->flushCache();
            $driver->get('test-feature', 'user');

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
            $result = $driver->get('unknown-feature', 'user');

            // Assert
            expect($result)->toBeFalse();
            Event::assertDispatched(UnknownFeatureResolved::class, fn ($event): bool => $event->feature === 'unknown-feature' && $event->scope === 'user');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles null scope serialization', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): true => true);

            // Act
            $result = $driver->get('test-feature', null);

            // Assert
            expect($result)->toBeTrue();
        });

        test('handles different scope types', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): true => true);

            // Act & Assert
            expect($driver->get('test-feature', 'string-scope'))->toBeTrue();
            expect($driver->get('test-feature', 123))->toBeTrue();
            expect($driver->get('test-feature', null))->toBeTrue();
        });

        test('stores complex values', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): array => ['key' => 'value', 'nested' => ['data' => 123]]);

            // Act
            $result = $driver->get('test-feature', 'user');

            // Assert
            expect($result)->toBe(['key' => 'value', 'nested' => ['data' => 123]]);
        });

        test('distinguishes between false and unknown feature', function (): void {
            // Arrange
            Event::fake([UnknownFeatureResolved::class]);
            $driver = createArrayDriver();
            $driver->define('false-feature', fn (): false => false);

            // Act
            $definedResult = $driver->get('false-feature', 'user');
            $unknownResult = $driver->get('unknown-feature', 'user');

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

        test('handles multiple scopes for same feature in getAll', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn ($scope): bool => $scope === 'admin');

            // Act
            $results = $driver->getAll([
                'test-feature' => ['admin', 'user', 'guest'],
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
            $driver->get('feature1', 'user');

            // Act
            $driver->purge([]);

            // Assert
            expect($driver->stored())->toContain('feature1');
        });

        test('setForAllScopes clears existing cached states', function (): void {
            // Arrange
            $driver = createArrayDriver();
            $driver->define('test-feature', fn (): false => false);
            $driver->get('test-feature', 'user1');
            $driver->get('test-feature', 'user2');

            // Verify initial state is cached
            $storedBefore = $driver->stored();
            expect($storedBefore)->toContain('test-feature');

            // Act
            $driver->setForAllScopes('test-feature', true);

            // Assert - Cache should be cleared, so resolver will be called with new value
            $result1 = $driver->get('test-feature', 'user1');
            $result2 = $driver->get('test-feature', 'user2');
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
    });
});

function createArrayDriver(): ArrayDriver
{
    return new ArrayDriver(
        app(Dispatcher::class),
        [],
    );
}
