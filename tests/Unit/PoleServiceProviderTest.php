<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Pole\Feature;
use Illuminate\Support\Facades\Blade;

describe('PoleServiceProvider', function (): void {
    describe('Blade Directives', function (): void {
        test('@feature directive with single argument checks if feature is active', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): true => true);

            // Act
            $result = Blade::check('feature', 'test-feature');

            // Assert
            expect($result)->toBeTrue(); // Line 70
        });

        test('@feature directive with two arguments checks feature value equality', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): string => 'expected-value');

            // Act
            $resultMatch = Blade::check('feature', 'test-feature', 'expected-value');
            $resultNoMatch = Blade::check('feature', 'test-feature', 'other-value');

            // Assert
            expect($resultMatch)->toBeTrue(); // Line 67
            expect($resultNoMatch)->toBeFalse();
        });

        test('@featureany directive checks if any features are active', function (): void {
            // Arrange
            Feature::define('feature1', fn (): false => false);
            Feature::define('feature2', fn (): true => true);
            Feature::define('feature3', fn (): false => false);

            // Act
            $result = Blade::check('featureany', ['feature1', 'feature2', 'feature3']);

            // Assert
            expect($result)->toBeTrue(); // Line 75
        });

        test('@featureany directive returns false when no features are active', function (): void {
            // Arrange
            Feature::define('feature1', fn (): false => false);
            Feature::define('feature2', fn (): false => false);

            // Act
            $result = Blade::check('featureany', ['feature1', 'feature2']);

            // Assert
            expect($result)->toBeFalse();
        });

        test('@featureall directive checks if all features are active', function (): void {
            // Arrange
            Feature::define('feature1', fn (): true => true);
            Feature::define('feature2', fn (): true => true);
            Feature::define('feature3', fn (): true => true);

            // Act
            $result = Blade::check('featureall', ['feature1', 'feature2', 'feature3']);

            // Assert
            expect($result)->toBeTrue(); // Line 80
        });

        test('@featureall directive returns false when some features are inactive', function (): void {
            // Arrange
            Feature::define('feature1', fn (): true => true);
            Feature::define('feature2', fn (): false => false);
            Feature::define('feature3', fn (): true => true);

            // Act
            $result = Blade::check('featureall', ['feature1', 'feature2', 'feature3']);

            // Assert
            expect($result)->toBeFalse();
        });
    });

    describe('Edge Cases', function (): void {
        test('@feature directive handles false feature value', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): false => false);

            // Act
            $result = Blade::check('feature', 'test-feature');

            // Assert
            expect($result)->toBeFalse();
        });

        test('@feature directive handles non-boolean feature values', function (): void {
            // Arrange
            Feature::define('test-feature', fn (): string => 'string-value');

            // Act
            $resultActive = Blade::check('feature', 'test-feature'); // Checks truthiness
            $resultValue = Blade::check('feature', 'test-feature', 'string-value');

            // Assert
            expect($resultActive)->toBeTrue(); // Truthy string
            expect($resultValue)->toBeTrue(); // Exact match
        });

        test('@featureany directive with empty array returns false', function (): void {
            // Act
            $result = Blade::check('featureany', []);

            // Assert
            expect($result)->toBeFalse();
        });

        test('@featureall directive with empty array returns true', function (): void {
            // Act - All of nothing is true (vacuous truth)
            $result = Blade::check('featureall', []);

            // Assert
            expect($result)->toBeTrue();
        });

        test('@feature directive with single argument uses Feature facade', function (): void {
            // Arrange
            Feature::define('integration-test', fn (): true => true);

            // Act - Compile a blade template with the directive
            $compiled = Blade::compileString("@feature('integration-test') Active @endfeature");

            // Assert - Should contain the check
            expect($compiled)->toContain('integration-test');
        });
    });
});
