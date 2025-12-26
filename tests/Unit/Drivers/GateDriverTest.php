<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Drivers\GateDriver;
use Cline\Toggl\Events\UnknownFeatureResolved;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\User;

/**
 * GateDriver test suite.
 *
 * Tests the Laravel Gate-based feature flag driver, which delegates feature flag
 * evaluation to Laravel's authorization Gate system. The GateDriver provides a
 * read-only interface that leverages existing Gate definitions for feature access
 * control, making it ideal for permission-based feature flags. Tests verify gate
 * evaluation, user-specific authorization, configuration options, and proper
 * exception handling for unsupported write operations.
 */
describe('GateDriver', function (): void {
    describe('Happy Path', function (): void {
        test('defines a feature resolver', function (): void {
            // Arrange
            $driver = createGateDriver();

            // Act
            $result = $driver->define('test-feature', fn (): true => true);

            // Assert
            expect($result)->toBeNull();
            expect($driver->defined())->toContain('test-feature');
        });

        test('defines a feature with static value', function (): void {
            // Arrange
            $driver = createGateDriver();

            // Act
            $result = $driver->define('static-feature', true);

            // Assert
            expect($result)->toBeNull();
            expect($driver->defined())->toContain('static-feature');
        });

        test('retrieves defined feature names', function (): void {
            // Arrange
            $driver = createGateDriver();
            $driver->define('feature1', fn (): true => true);
            $driver->define('feature2', fn (): false => false);

            // Act
            $defined = $driver->defined();

            // Assert
            expect($defined)->toBeArray();
            expect($defined)->toContain('feature1');
            expect($defined)->toContain('feature2');
        });

        test('gets feature value through gate', function (): void {
            // Arrange
            resolve(Gate::class)->define('feature', fn ($user, $feature): bool => $feature === 'admin-feature');

            $driver = createGateDriver();
            $user = new User();

            // Act
            $result = $driver->get('admin-feature', userContext($user));

            // Assert
            expect($result)->toBeTrue();
        });

        test('returns false when gate denies feature', function (): void {
            // Arrange
            resolve(Gate::class)->define('feature', fn ($user, $feature): bool => $feature === 'admin-feature');

            $driver = createGateDriver();
            $user = new User();

            // Act
            $result = $driver->get('regular-feature', userContext($user));

            // Assert
            expect($result)->toBeFalse();
        });

        test('evaluates gate for different users', function (): void {
            // Arrange
            resolve(Gate::class)->define('feature', fn ($user, $feature): bool => $user->id === 1 && $feature === 'premium-feature');

            $driver = createGateDriver();
            $adminUser = new User(['id' => 1]);
            $regularUser = new User(['id' => 2]);

            // Act
            $adminResult = $driver->get('premium-feature', userContext($adminUser));
            $regularResult = $driver->get('premium-feature', userContext($regularUser));

            // Assert
            expect($adminResult)->toBeTrue();
            expect($regularResult)->toBeFalse();
        });

        test('gets all features in bulk through gate', function (): void {
            // Arrange
            resolve(Gate::class)->define('feature', fn ($user, $feature): bool => $feature === 'admin-feature');

            $driver = createGateDriver();
            $user1 = new User(['id' => 1]);
            $user2 = new User(['id' => 2]);

            // Act
            $results = $driver->getAll([
                'admin-feature' => [userContext($user1), userContext($user2)],
                'regular-feature' => [userContext($user1), userContext($user2)],
            ]);

            // Assert
            expect($results['admin-feature'][0])->toBeTrue();
            expect($results['admin-feature'][1])->toBeTrue();
            expect($results['regular-feature'][0])->toBeFalse();
            expect($results['regular-feature'][1])->toBeFalse();
        });

        test('getAll properly executes nested map closures for multiple features and contexts', function (): void {
            // This test ensures 100% coverage of getAll method including:
            // - Line 100: Outer closure ->map(fn ($contexts, string $feature) => ...)
            // - Line 101: Inner closure ->map(fn ($context): mixed => $this->get($feature, $context))
            // - Line 102: Inner ->all() converting inner collection to array
            // - Line 103: Outer ->all() converting outer collection to array

            // Arrange
            $innerClosureCalls = 0;

            resolve(Gate::class)->define('feature', function ($user, $feature) use (&$innerClosureCalls): bool {
                ++$innerClosureCalls; // Track inner closure calls

                return match (true) {
                    $feature === 'admin-feature' && $user->id === 1 => true,
                    $feature === 'premium-feature' && $user->id <= 2 => true,
                    $feature === 'basic-feature' => true,
                    default => false,
                };
            });

            $driver = createGateDriver();
            $admin = new User(['id' => 1]);
            $premium = new User(['id' => 2]);
            $regular = new User(['id' => 3]);

            // Act - Test with multiple features each having multiple contexts
            $results = $driver->getAll([
                'admin-feature' => [userContext($admin), userContext($premium), userContext($regular)],
                'premium-feature' => [userContext($admin), userContext($premium), userContext($regular)],
                'basic-feature' => [userContext($admin), userContext($premium), userContext($regular)],
                'nonexistent-feature' => [userContext($admin), userContext($premium), userContext($regular)],
            ]);

            // Assert - Verify outer closure processes each feature (4 features)
            expect($results)->toHaveKeys(['admin-feature', 'premium-feature', 'basic-feature', 'nonexistent-feature']);

            // Assert - Verify inner closures were called for ALL contexts (4 features × 3 contexts = 12 calls)
            expect($innerClosureCalls)->toBe(12);

            // Assert - Verify inner closure processes each context for admin-feature
            expect($results['admin-feature'])->toHaveCount(3);
            expect($results['admin-feature'][0])->toBeTrue();
            expect($results['admin-feature'][1])->toBeFalse();
            expect($results['admin-feature'][2])->toBeFalse();

            // Assert - Verify inner closure processes each context for premium-feature
            expect($results['premium-feature'])->toHaveCount(3);
            expect($results['premium-feature'][0])->toBeTrue();
            expect($results['premium-feature'][1])->toBeTrue();
            expect($results['premium-feature'][2])->toBeFalse();

            // Assert - Verify inner closure processes each context for basic-feature
            expect($results['basic-feature'])->toHaveCount(3);
            expect($results['basic-feature'][0])->toBeTrue();
            expect($results['basic-feature'][1])->toBeTrue();
            expect($results['basic-feature'][2])->toBeTrue();

            // Assert - Verify inner closure processes each context for nonexistent-feature
            expect($results['nonexistent-feature'])->toHaveCount(3);
            expect($results['nonexistent-feature'][0])->toBeFalse();
            expect($results['nonexistent-feature'][1])->toBeFalse();
            expect($results['nonexistent-feature'][2])->toBeFalse();
        });

        test('uses custom gate name from configuration', function (): void {
            // Arrange
            config(['toggl.stores.gate.gate' => 'custom-gate']);
            resolve(Gate::class)->define('custom-gate', fn ($user, $feature): bool => $feature === 'test-feature');

            $driver = createGateDriver();
            $user = new User();

            // Act
            $result = $driver->get('test-feature', userContext($user));

            // Assert
            expect($result)->toBeTrue();
        });
    });

    describe('Sad Path', function (): void {
        test('dispatches unknown feature event when gate not defined', function (): void {
            // Arrange
            Event::fake([UnknownFeatureResolved::class]);
            $driver = createGateDriver();
            $user = new User();

            // Act
            $result = $driver->get('unknown-feature', userContext($user));

            // Assert
            expect($result)->toBeFalse();
            Event::assertDispatched(UnknownFeatureResolved::class, fn ($event): bool => $event->feature === 'unknown-feature');
        });

        test('throws exception when trying to set feature value', function (): void {
            // Arrange
            $driver = createGateDriver();
            $user = new User();

            // Act & Assert
            expect(fn () => $driver->set('test-feature', userContext($user), true))
                ->toThrow(RuntimeException::class, 'The Gate driver does not support setting feature values. Define gates instead.');
        });

        test('throws exception when trying to set feature for all contexts', function (): void {
            // Arrange
            $driver = createGateDriver();

            // Act & Assert
            expect(fn () => $driver->setForAllContexts('test-feature', true))
                ->toThrow(RuntimeException::class, 'The Gate driver does not support setting feature values. Define gates instead.');
        });

        test('throws exception when trying to delete feature', function (): void {
            // Arrange
            $driver = createGateDriver();
            $user = new User();

            // Act & Assert
            expect(fn () => $driver->delete('test-feature', userContext($user)))
                ->toThrow(RuntimeException::class, 'The Gate driver does not support deleting feature values. Remove gate definitions instead.');
        });

        test('throws exception when trying to purge features', function (): void {
            // Arrange
            $driver = createGateDriver();

            // Act & Assert
            expect(fn () => $driver->purge(null))
                ->toThrow(RuntimeException::class, 'The Gate driver does not support purging feature values. Remove gate definitions instead.');
        });

        test('throws exception when trying to purge specific features', function (): void {
            // Arrange
            $driver = createGateDriver();

            // Act & Assert
            expect(fn () => $driver->purge(['feature1', 'feature2']))
                ->toThrow(RuntimeException::class, 'The Gate driver does not support purging feature values. Remove gate definitions instead.');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles TogglContext without source falling back to context', function (): void {
            // Arrange - Gate that accepts any user
            resolve(Gate::class)->define('feature', fn (mixed $user, $feature): bool => $feature === 'public-feature');

            $driver = createGateDriver();
            $context = TogglContext::simple('anonymous', 'guest');

            // Act - TogglContext without source model passes itself to gate
            $result = $driver->get('public-feature', $context);

            // Assert
            expect($result)->toBeTrue();
        });

        test('handles empty getAll request', function (): void {
            // Arrange
            resolve(Gate::class)->define('feature', fn (): true => true);
            $driver = createGateDriver();

            // Act
            $results = $driver->getAll([]);

            // Assert
            expect($results)->toBe([]);
        });

        test('handles multiple contexts for same feature in getAll', function (): void {
            // Arrange
            resolve(Gate::class)->define('feature', fn ($user, $feature): bool => $user->id === 1);

            $driver = createGateDriver();
            $admin = new User(['id' => 1]);
            $user = new User(['id' => 2]);
            $guest = new User(['id' => 3]);

            // Act
            $results = $driver->getAll([
                'test-feature' => [userContext($admin), userContext($user), userContext($guest)],
            ]);

            // Assert
            expect($results['test-feature'][0])->toBeTrue();
            expect($results['test-feature'][1])->toBeFalse();
            expect($results['test-feature'][2])->toBeFalse();
        });

        test('gate receives correct feature name parameter', function (): void {
            // Arrange
            $receivedFeature = null;
            resolve(Gate::class)->define('feature', function ($user, $feature) use (&$receivedFeature): true {
                $receivedFeature = $feature;

                return true;
            });

            $driver = createGateDriver();
            $user = new User();

            // Act
            $driver->get('my-custom-feature', userContext($user));

            // Assert
            expect($receivedFeature)->toBe('my-custom-feature');
        });

        test('gate always returns boolean result', function (): void {
            // Arrange
            resolve(Gate::class)->define('feature', fn (): string => 'string-value');

            $driver = createGateDriver();
            $user = new User();

            // Act
            $result = $driver->get('test-feature', userContext($user));

            // Assert - Gate driver should coerce to boolean
            expect($result)->toBeBool();
        });

        test('returns false when gate name does not exist in configuration', function (): void {
            // Arrange
            Event::fake([UnknownFeatureResolved::class]);
            config(['toggl.stores.gate.gate' => 'non-existent-gate']);
            $driver = createGateDriver();
            $user = new User();

            // Act
            $result = $driver->get('test-feature', userContext($user));

            // Assert
            expect($result)->toBeFalse();
            Event::assertDispatched(UnknownFeatureResolved::class);
        });

        test('uses default gate name when not configured', function (): void {
            // Arrange
            config(['toggl.stores.gate.gate' => null]);
            resolve(Gate::class)->define('feature', fn (): true => true);

            $driver = createGateDriver();
            $user = new User();

            // Act
            $result = $driver->get('test-feature', userContext($user));

            // Assert
            expect($result)->toBeTrue();
        });

        test('evaluates gate in real-time without caching', function (): void {
            // Arrange
            $callCount = 0;
            resolve(Gate::class)->define('feature', function ($user, $feature) use (&$callCount): true {
                ++$callCount;

                return true;
            });

            $driver = createGateDriver();
            $user = new User();

            // Act - Call multiple times
            $driver->get('test-feature', userContext($user));
            $driver->get('test-feature', userContext($user));
            $driver->get('test-feature', userContext($user));

            // Assert - Gate should be called every time (no caching)
            expect($callCount)->toBe(3);
        });
    });
});

/**
 * Create a GateDriver instance for testing.
 *
 * Factory function that constructs a GateDriver using Laravel's Gate contract
 * and event dispatcher. The driver delegates all feature flag checks to the
 * configured gate (defaults to 'feature'), making it a read-only driver that
 * integrates with Laravel's authorization system.
 *
 * @param  string     $name The driver configuration name, defaults to 'gate'
 * @return GateDriver Configured gate driver instance ready for testing
 */
function createGateDriver(string $name = 'gate'): GateDriver
{
    return new GateDriver(
        resolve(Gate::class),
        resolve(Dispatcher::class),
        $name,
        [],
    );
}
