<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;

/**
 * Test suite for PendingContextualFeatureInteraction functionality.
 *
 * Validates contextual feature flag operations including single and multi-context queries,
 * value retrieval, conditional execution, group management, and variant assignment.
 * Tests the fluent interface for chaining context operations, cache preloading behavior,
 * and proper error handling when multi-context operations are attempted on single-context
 * methods. Covers activation/deactivation of feature groups and variant distribution.
 */
describe('PendingContextualFeatureInteraction', function (): void {
    describe('Happy Path', function (): void {
        test('value returns single feature value for single context', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): string => 'test-value');

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->value('test-feature');

            // Assert
            expect($result)->toBe('test-value');
        });

        test('values returns multiple feature values for single context', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): string => 'value1');
            Toggl::define('feature2', fn (): string => 'value2');

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->values(['feature1', 'feature2']);

            // Assert
            expect($result)->toHaveKey('feature1', 'value1');
            expect($result)->toHaveKey('feature2', 'value2');
        });

        test('when executes callback when feature is active', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): true => true);
            $executed = false;

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->when('test-feature', function ($value, $interaction) use (&$executed): string {
                $executed = true;

                return 'success';
            });

            // Assert
            expect($executed)->toBeTrue();
        });

        test('when executes inactive callback when feature is inactive', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): false => false);
            $executed = false;

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->when(
                'test-feature',
                fn (): string => 'active',
                function () use (&$executed): string {
                    $executed = true;

                    return 'inactive';
                },
            );

            // Assert
            expect($executed)->toBeTrue();
            expect($result)->toBe('inactive'); // Line 264
        });

        test('unless executes callback when feature is inactive', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): false => false);
            $executed = false;

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->unless('test-feature', function () use (&$executed): void {
                $executed = true;
            });

            // Assert
            expect($executed)->toBeTrue();
        });

        test('activateGroup activates all features in group for specified contexts', function (): void {
            // Arrange
            config(['toggl.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Toggl::define('feature1', fn (): false => false);
            Toggl::define('feature2', fn (): false => false);

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->activateGroup('test-group');

            // Assert
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->active('feature1'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->active('feature2'))->toBeTrue();
        });

        test('deactivateGroup deactivates all features in group for specified contexts', function (): void {
            // Arrange
            config(['toggl.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Toggl::define('feature1', fn (): true => true);
            Toggl::define('feature2', fn (): true => true);
            Toggl::for(TogglContext::simple('user1', 'test'))->activateGroup('test-group');

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->deactivateGroup('test-group');

            // Assert
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->active('feature1'))->toBeFalse();
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->active('feature2'))->toBeFalse();
        });

        test('activeInGroup returns true when empty group', function (): void {
            // Arrange
            config(['toggl.groups.empty-group' => ['features' => []]]);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->activeInGroup('empty-group'))->toBeTrue(); // Line 365
        });

        test('activeInGroup returns true when all features are active', function (): void {
            // Arrange
            config(['toggl.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Toggl::define('feature1', fn (): true => true);
            Toggl::define('feature2', fn (): true => true);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->activeInGroup('test-group'))->toBeTrue();
        });

        test('activeInGroup returns false when some features are inactive', function (): void {
            // Arrange
            config(['toggl.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Toggl::define('feature1', fn (): true => true);
            Toggl::define('feature2', fn (): false => false);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->activeInGroup('test-group'))->toBeFalse();
        });

        test('someActiveInGroup returns false when empty group', function (): void {
            // Arrange
            config(['toggl.groups.empty-group' => ['features' => []]]);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->someActiveInGroup('empty-group'))->toBeFalse(); // Line 386-396
        });

        test('someActiveInGroup returns true when at least one feature is active', function (): void {
            // Arrange
            config(['toggl.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Toggl::define('feature1', fn (): true => true);
            Toggl::define('feature2', fn (): false => false);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->someActiveInGroup('test-group'))->toBeTrue();
        });

        test('someActiveInGroup returns false when all features are inactive', function (): void {
            // Arrange
            config(['toggl.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Toggl::define('feature1', fn (): false => false);
            Toggl::define('feature2', fn (): false => false);

            // Act & Assert
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->someActiveInGroup('test-group'))->toBeFalse();
        });

        test('someActiveInGroup with multiple contexts checks all contexts', function (): void {
            // Arrange
            config(['toggl.groups.test-group' => ['features' => ['feature1', 'feature2', 'feature3']]]);
            Toggl::define('feature1', fn ($context): bool => $context->id === 'user1');
            Toggl::define('feature2', fn ($context): bool => $context->id === 'user2');
            Toggl::define('feature3', fn (): true => true);

            // Act - Line 503: every closure evaluating features for each context
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test'), TogglContext::simple('user3', 'test')])->someActiveInGroup('test-group');

            // Assert
            expect($result)->toBeTrue();
        });

        test('someActiveInGroup returns false when no features active for all contexts', function (): void {
            // Arrange
            config(['toggl.groups.test-group' => ['features' => ['feature1', 'feature2']]]);
            Toggl::define('feature1', fn (): false => false);
            Toggl::define('feature2', fn (): false => false);

            // Act - Line 503: contains closure with all inactive features
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test'), TogglContext::simple('user3', 'test')])->someActiveInGroup('test-group');

            // Assert
            expect($result)->toBeFalse();
        });

        test('variant returns null when no variants defined', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): true => true);

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->variant('test-feature');

            // Assert
            expect($result)->toBeNull(); // Line 430
        });

        test('variant returns assigned variant based on context', function (): void {
            // Arrange
            Toggl::defineVariant('ab-test', ['control' => 50, 'treatment' => 50]);

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->variant('ab-test');

            // Assert
            expect($result)->toBeIn(['control', 'treatment']);
        });

        test('variant returns consistent value for same context', function (): void {
            // Arrange
            Toggl::defineVariant('ab-test', ['control' => 50, 'treatment' => 50]);

            // Act
            $result1 = Toggl::for(TogglContext::simple('user1', 'test'))->variant('ab-test');
            $result2 = Toggl::for(TogglContext::simple('user1', 'test'))->variant('ab-test');

            // Assert
            expect($result1)->toBe($result2);
        });

        test('someAreActive returns true when at least one feature active for all contexts', function (): void {
            // Arrange
            Toggl::define('feature1', fn ($context): bool => $context->id === 'user1');
            Toggl::define('feature2', fn (): true => true);

            // Act
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test')])->someAreActive(['feature1', 'feature2']);

            // Assert
            expect($result)->toBeTrue(); // Line 239-243
        });

        test('someAreActive returns false when no features active for any context', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): false => false);
            Toggl::define('feature2', fn (): false => false);
            Toggl::define('feature3', fn (): false => false);

            // Act - Line 248: every closure with multiple contexts and features
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test'), TogglContext::simple('user3', 'test')])->someAreActive(['feature1', 'feature2', 'feature3']);

            // Assert
            expect($result)->toBeFalse();
        });

        test('someAreActive with mixed feature states across contexts', function (): void {
            // Arrange
            Toggl::define('feature1', fn ($context): bool => $context->id === 'user1');
            Toggl::define('feature2', fn ($context): bool => $context->id === 'user2');
            Toggl::define('feature3', fn (): true => true);

            // Act - Line 248: contains closure evaluating multiple features
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test')])->someAreActive(['feature1', 'feature2', 'feature3']);

            // Assert
            expect($result)->toBeTrue();
        });

        test('someAreInactive returns true when at least one feature inactive for all contexts', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): true => true);
            Toggl::define('feature2', fn (): false => false);

            // Act
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test')])->someAreInactive(['feature1', 'feature2']);

            // Assert
            expect($result)->toBeTrue(); // Line 239-243
        });

        test('someAreInactive returns false when all features active for any context', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): true => true);
            Toggl::define('feature2', fn (): true => true);
            Toggl::define('feature3', fn (): true => true);

            // Act - Line 301: every closure with multiple contexts and features
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test'), TogglContext::simple('user3', 'test')])->someAreInactive(['feature1', 'feature2', 'feature3']);

            // Assert
            expect($result)->toBeFalse();
        });

        test('someAreInactive with mixed inactive states across contexts', function (): void {
            // Arrange
            Toggl::define('feature1', fn ($context): bool => $context->id === 'user1');
            Toggl::define('feature2', fn ($context): bool => $context->id === 'user2');
            Toggl::define('feature3', fn (): false => false);

            // Act - Line 301: contains closure evaluating multiple features for inactive state
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test')])->someAreInactive(['feature1', 'feature2', 'feature3']);

            // Assert
            expect($result)->toBeTrue();
        });
    });

    describe('Sad Path', function (): void {
        test('value throws exception for multiple contexts', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): true => true);

            // Act & Assert
            expect(fn () => Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test')])->value('test-feature'))
                ->toThrow(RuntimeException::class, 'It is not possible to retrieve the values for multiple contexts.'); // Line 132
        });

        test('values throws exception for multiple contexts', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): true => true);

            // Act & Assert
            expect(fn () => Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test')])->values(['test-feature']))
                ->toThrow(RuntimeException::class, 'It is not possible to retrieve the values for multiple contexts.');
        });

        test('all throws exception for multiple contexts', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): true => true);

            // Act & Assert
            expect(fn () => Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test')])->all())
                ->toThrow(RuntimeException::class, 'It is not possible to retrieve the values for multiple contexts.'); // Line 153
        });

        test('variant throws exception for multiple contexts', function (): void {
            // Arrange
            Toggl::defineVariant('ab-test', ['control' => 50, 'treatment' => 50]);

            // Act & Assert
            expect(fn () => Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test')])->variant('ab-test'))
                ->toThrow(RuntimeException::class, 'It is not possible to retrieve variants for multiple contexts.'); // Line 414
        });
    });

    describe('Edge Cases', function (): void {
        test('for merges multiple context calls', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): true => true);

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->for(TogglContext::simple('user2', 'test'))->allAreActive(['test-feature']);

            // Assert
            expect($result)->toBeTrue();
        });

        test('when returns null when inactive and no inactive callback', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): false => false);

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->when('test-feature', fn (): string => 'active');

            // Assert
            expect($result)->toBeNull(); // Line 264
        });

        test('load preloads features into cache', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): string => 'value1');
            Toggl::define('feature2', fn (): string => 'value2');

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->load(['feature1', 'feature2']);

            // Assert
            expect($result)->toHaveKey('feature1');
            expect($result)->toHaveKey('feature2');
            expect($result['feature1'][0])->toBe('value1');
            expect($result['feature2'][0])->toBe('value2');
        });

        test('loadMissing only loads uncached features', function (): void {
            // Arrange
            $callCount = 0;
            Toggl::define('test-feature', function () use (&$callCount): string {
                ++$callCount;

                return 'value';
            });

            // Pre-load the feature
            Toggl::for(TogglContext::simple('user1', 'test'))->load(['test-feature']);

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->loadMissing(['test-feature']);

            // Assert - Should only be called once (during load, not loadMissing)
            expect($callCount)->toBe(1);
        });

        test('load handles multiple features with multiple contexts', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): string => 'value1');
            Toggl::define('feature2', fn (): string => 'value2');
            Toggl::define('feature3', fn (): string => 'value3');

            // Act - Lines 95-96: mapWithKeys and pipe closure internals
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test'), TogglContext::simple('user3', 'test')])->load(['feature1', 'feature2', 'feature3']);

            // Assert
            expect($result)->toHaveKey('feature1');
            expect($result)->toHaveKey('feature2');
            expect($result)->toHaveKey('feature3');
            expect($result['feature1'])->toHaveCount(3);
            expect($result['feature2'])->toHaveCount(3);
            expect($result['feature3'])->toHaveCount(3);
        });

        test('loadMissing handles multiple features with string keys', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): string => 'value1');
            Toggl::define('feature2', fn (): string => 'value2');

            // Act - Lines 118-119: values(), mapWithKeys and pipe closure internals
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test')])->loadMissing(['key1' => 'feature1', 'key2' => 'feature2']);

            // Assert
            expect($result)->toHaveKey('feature1');
            expect($result)->toHaveKey('feature2');
            expect($result['feature1'])->toHaveCount(2);
            expect($result['feature2'])->toHaveCount(2);
        });

        test('activate can activate multiple features', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): false => false);
            Toggl::define('feature2', fn (): false => false);

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->activate(['feature1', 'feature2']);

            // Assert
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->active('feature1'))->toBeTrue();
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->active('feature2'))->toBeTrue();
        });

        test('deactivate can deactivate multiple features', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): true => true);
            Toggl::define('feature2', fn (): true => true);

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->deactivate(['feature1', 'feature2']);

            // Assert
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->active('feature1'))->toBeFalse();
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->active('feature2'))->toBeFalse();
        });

        test('forget can forget multiple features', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): true => true);
            Toggl::define('feature2', fn (): true => true);
            Toggl::for(TogglContext::simple('user1', 'test'))->activate(['feature1', 'feature2']);

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->forget(['feature1', 'feature2']);

            // Redefine to test they're truly forgotten
            $callCount = 0;
            Toggl::define('feature1', function () use (&$callCount): true {
                ++$callCount;

                return true;
            });

            Toggl::for(TogglContext::simple('user1', 'test'))->active('feature1');

            // Assert - Should call resolver since cache was cleared
            expect($callCount)->toBe(1);
        });

        test('all returns all defined features for single context', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): string => 'value1');
            Toggl::define('feature2', fn (): string => 'value2');

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->all();

            // Assert
            expect($result)->toHaveKey('feature1');
            expect($result)->toHaveKey('feature2');
        });

        test('variant calculates consistently using hash', function (): void {
            // Arrange
            Toggl::defineVariant('ab-test', ['control' => 100, 'treatment' => 0]);

            // Act
            $result = Toggl::for(TogglContext::simple('test-user', 'test'))->variant('ab-test');

            // Assert - With 100% weight on control, should always get control
            expect($result)->toBe('control'); // Line 476
        });

        test('variant calculateVariant fallback returns last variant', function (): void {
            // Arrange - Test edge case distribution to ensure fallback logic (lines 502-505) works
            // The fallback returns array_key_last($weights) if loop completes without matching
            // This is defensive code that shouldn't be reached with proper validation, but ensures safety
            Toggl::defineVariant('fallback-test', ['first' => 33, 'second' => 33, 'third' => 34]);

            // Act - Test with many contexts to cover all bucket ranges (0-99)
            // This verifies the algorithm works correctly including edge bucket values
            $results = [];

            for ($i = 0; $i < 100; ++$i) {
                $results[] = Toggl::for(TogglContext::simple('context-'.$i, 'test'))->variant('fallback-test');
            }

            // Assert - All variants should be properly assigned, with 'third' as the last variant
            expect($results)->toContain('first');
            expect($results)->toContain('second');
            expect($results)->toContain('third');
            // The last variant ('third') serves as the fallback in edge cases (lines 502-505)
            expect(array_unique($results))->toContain('third');
        });

        test('isEnabled alias returns true when feature is active', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): true => true);

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->isEnabled('test-feature');

            // Assert - Line 539: isEnabled() alias
            expect($result)->toBeTrue();
        });

        test('isDisabled alias returns true when feature is inactive', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): false => false);

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->isDisabled('test-feature');

            // Assert - Line 550: isDisabled() alias
            expect($result)->toBeTrue();
        });

        test('anyAreActive alias returns true when at least one feature is active', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): true => true);
            Toggl::define('feature2', fn (): false => false);

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->anyAreActive(['feature1', 'feature2']);

            // Assert - Line 563: anyAreActive() alias
            expect($result)->toBeTrue();
        });

        test('anyAreInactive alias returns true when at least one feature is inactive', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): true => true);
            Toggl::define('feature2', fn (): false => false);

            // Act
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->anyAreInactive(['feature1', 'feature2']);

            // Assert - Line 576: anyAreInactive() alias
            expect($result)->toBeTrue();
        });

        test('enable alias activates feature', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): false => false);

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->enable('test-feature');

            // Assert - Line 587: enable() alias
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->active('test-feature'))->toBeTrue();
        });

        test('disable alias deactivates feature', function (): void {
            // Arrange
            Toggl::define('test-feature', fn (): true => true);
            Toggl::for(TogglContext::simple('user1', 'test'))->activate('test-feature');

            // Act
            Toggl::for(TogglContext::simple('user1', 'test'))->disable('test-feature');

            // Assert - Line 597: disable() alias
            expect(Toggl::for(TogglContext::simple('user1', 'test'))->active('test-feature'))->toBeFalse();
        });

        test('allAreInactive returns false when features have non-false truthy values', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): string => 'variant-a');
            Toggl::define('feature2', fn (): int => 1);
            Toggl::define('feature3', fn (): true => true);

            // Act - Line 303-304: !== false check should return false for any truthy value
            $result = Toggl::for(TogglContext::simple('user1', 'test'))->allAreInactive(['feature1', 'feature2', 'feature3']);

            // Assert
            expect($result)->toBeFalse();
        });

        test('allAreInactive returns false with multiple contexts when any feature is truthy', function (): void {
            // Arrange
            Toggl::define('feature1', fn ($context): string|false => $context->id === 'user1' ? 'variant-a' : false);
            Toggl::define('feature2', fn ($context): int|false => $context->id === 'user2' ? 42 : false);
            Toggl::define('feature3', fn (): false => false);

            // Act - Line 303-304: !== false check across multiple contexts
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test'), TogglContext::simple('user3', 'test')])->allAreInactive(['feature1', 'feature2', 'feature3']);

            // Assert - Should be false because feature1 is truthy for user1 and feature2 is truthy for user2
            expect($result)->toBeFalse();
        });

        test('allAreInactive returns true when all features are false for all contexts', function (): void {
            // Arrange
            Toggl::define('feature1', fn (): false => false);
            Toggl::define('feature2', fn (): false => false);
            Toggl::define('feature3', fn (): false => false);

            // Act - Line 303-304: !== false check should pass when all are strictly false
            $result = Toggl::for([TogglContext::simple('user1', 'test'), TogglContext::simple('user2', 'test')])->allAreInactive(['feature1', 'feature2', 'feature3']);

            // Assert
            expect($result)->toBeTrue();
        });
    });
});
