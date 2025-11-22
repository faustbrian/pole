<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Exceptions\MissingPrerequisitesException;

/**
 * MissingPrerequisitesException Test Suite
 *
 * Tests the MissingPrerequisitesException class's static factory method:
 * - forFeature: Creates exception for missing prerequisites
 *
 * Verifies that:
 * - Exception is properly instantiated
 * - Exception extends RuntimeException
 * - Error messages vary based on display_feature_in_exception config
 * - Detailed message includes feature name and missing prerequisites when config is true
 * - Generic message is used when config is false
 * - Config default value is false
 */
describe('MissingPrerequisitesException', function (): void {
    describe('forFeature', function (): void {
        describe('Happy Path', function (): void {
            test('creates exception with generic message when config is false', function (): void {
                // Arrange
                config()->set('toggl.display_feature_in_exception', false);
                $dependentName = 'premium-feature';
                $missing = 'base-feature, another-feature';

                // Act
                $exception = MissingPrerequisitesException::forFeature($dependentName, $missing);

                // Assert
                expect($exception)->toBeInstanceOf(MissingPrerequisitesException::class);
                expect($exception)->toBeInstanceOf(RuntimeException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot activate feature: missing prerequisites');
            });

            test('creates exception with detailed message when config is true', function (): void {
                // Arrange
                config()->set('toggl.display_feature_in_exception', true);
                $dependentName = 'premium-feature';
                $missing = 'base-feature, another-feature';

                // Act
                $exception = MissingPrerequisitesException::forFeature($dependentName, $missing);

                // Assert
                expect($exception)->toBeInstanceOf(MissingPrerequisitesException::class);
                expect($exception)->toBeInstanceOf(RuntimeException::class);
                expect($exception->getMessage())
                    ->toBe("Cannot activate 'premium-feature': missing prerequisites [base-feature, another-feature]");
            });

            test('creates exception with default behavior when config is not set', function (): void {
                // Arrange
                config()->set('toggl.display_feature_in_exception');
                $dependentName = 'feature-x';
                $missing = 'feature-y';

                // Act
                $exception = MissingPrerequisitesException::forFeature($dependentName, $missing);

                // Assert
                expect($exception)->toBeInstanceOf(MissingPrerequisitesException::class);
                expect($exception->getMessage())
                    ->toBe('Cannot activate feature: missing prerequisites');
            });

            test('detailed message includes single missing prerequisite', function (): void {
                // Arrange
                config()->set('toggl.display_feature_in_exception', true);
                $dependentName = 'advanced-analytics';
                $missing = 'basic-analytics';

                // Act
                $exception = MissingPrerequisitesException::forFeature($dependentName, $missing);

                // Assert
                expect($exception->getMessage())
                    ->toBe("Cannot activate 'advanced-analytics': missing prerequisites [basic-analytics]");
            });

            test('detailed message includes multiple missing prerequisites', function (): void {
                // Arrange
                config()->set('toggl.display_feature_in_exception', true);
                $dependentName = 'enterprise-suite';
                $missing = 'feature-a, feature-b, feature-c';

                // Act
                $exception = MissingPrerequisitesException::forFeature($dependentName, $missing);

                // Assert
                expect($exception->getMessage())
                    ->toBe("Cannot activate 'enterprise-suite': missing prerequisites [feature-a, feature-b, feature-c]");
            });
        });

        describe('Edge Cases', function (): void {
            test('handles empty feature name with config enabled', function (): void {
                // Arrange
                config()->set('toggl.display_feature_in_exception', true);
                $dependentName = '';
                $missing = 'required-feature';

                // Act
                $exception = MissingPrerequisitesException::forFeature($dependentName, $missing);

                // Assert
                expect($exception->getMessage())
                    ->toBe("Cannot activate '': missing prerequisites [required-feature]");
            });

            test('handles empty missing prerequisites list with config enabled', function (): void {
                // Arrange
                config()->set('toggl.display_feature_in_exception', true);
                $dependentName = 'my-feature';
                $missing = '';

                // Act
                $exception = MissingPrerequisitesException::forFeature($dependentName, $missing);

                // Assert
                expect($exception->getMessage())
                    ->toBe("Cannot activate 'my-feature': missing prerequisites []");
            });

            test('handles feature names with special characters', function (): void {
                // Arrange
                config()->set('toggl.display_feature_in_exception', true);
                $dependentName = 'feature-with-dashes_and_underscores.and.dots';
                $missing = 'prereq-1, prereq_2, prereq.3';

                // Act
                $exception = MissingPrerequisitesException::forFeature($dependentName, $missing);

                // Assert
                expect($exception->getMessage())
                    ->toBe("Cannot activate 'feature-with-dashes_and_underscores.and.dots': missing prerequisites [prereq-1, prereq_2, prereq.3]");
            });

            test('handles feature names with spaces', function (): void {
                // Arrange
                config()->set('toggl.display_feature_in_exception', true);
                $dependentName = 'feature with spaces';
                $missing = 'another feature';

                // Act
                $exception = MissingPrerequisitesException::forFeature($dependentName, $missing);

                // Assert
                expect($exception->getMessage())
                    ->toBe("Cannot activate 'feature with spaces': missing prerequisites [another feature]");
            });

            test('handles unicode characters in feature names', function (): void {
                // Arrange
                config()->set('toggl.display_feature_in_exception', true);
                $dependentName = '功能-feature-función';
                $missing = 'prerequisite-要求';

                // Act
                $exception = MissingPrerequisitesException::forFeature($dependentName, $missing);

                // Assert
                expect($exception->getMessage())
                    ->toBe("Cannot activate '功能-feature-función': missing prerequisites [prerequisite-要求]");
            });

            test('generic message remains same regardless of input', function (): void {
                // Arrange
                config()->set('toggl.display_feature_in_exception', false);

                // Act
                $exception1 = MissingPrerequisitesException::forFeature('feature1', 'prereq1');
                $exception2 = MissingPrerequisitesException::forFeature('completely-different', 'other-prereqs');
                $exception3 = MissingPrerequisitesException::forFeature('', '');

                // Assert
                expect($exception1->getMessage())->toBe($exception2->getMessage());
                expect($exception2->getMessage())->toBe($exception3->getMessage());
                expect($exception1->getMessage())
                    ->toBe('Cannot activate feature: missing prerequisites');
            });
        });
    });

    describe('Inheritance', function (): void {
        test('exception extends RuntimeException', function (): void {
            // Arrange
            config()->set('toggl.display_feature_in_exception', false);

            // Act
            $exception = MissingPrerequisitesException::forFeature('test', 'prereq');

            // Assert
            expect($exception)->toBeInstanceOf(RuntimeException::class);
        });

        test('exception is instance of MissingPrerequisitesException', function (): void {
            // Arrange
            config()->set('toggl.display_feature_in_exception', true);

            // Act
            $exception = MissingPrerequisitesException::forFeature('test', 'prereq');

            // Assert
            expect($exception)->toBeInstanceOf(MissingPrerequisitesException::class);
        });

        test('both config branches return MissingPrerequisitesException instances', function (): void {
            // Arrange & Act
            config()->set('toggl.display_feature_in_exception', true);
            $detailedException = MissingPrerequisitesException::forFeature('feature', 'prereq');

            config()->set('toggl.display_feature_in_exception', false);
            $genericException = MissingPrerequisitesException::forFeature('feature', 'prereq');

            // Assert
            expect($detailedException)->toBeInstanceOf(MissingPrerequisitesException::class);
            expect($genericException)->toBeInstanceOf(MissingPrerequisitesException::class);
            expect($detailedException)->toBeInstanceOf(RuntimeException::class);
            expect($genericException)->toBeInstanceOf(RuntimeException::class);
        });
    });

    describe('Message Format', function (): void {
        test('generic message has expected format', function (): void {
            // Arrange
            config()->set('toggl.display_feature_in_exception', false);
            $expectedMessage = 'Cannot activate feature: missing prerequisites';

            // Act
            $exception = MissingPrerequisitesException::forFeature('any-feature', 'any-prereq');

            // Assert
            expect($exception->getMessage())->toBe($expectedMessage);
        });

        test('detailed message has expected format with placeholders filled', function (): void {
            // Arrange
            config()->set('toggl.display_feature_in_exception', true);
            $dependentName = 'test-feature';
            $missing = 'missing-1, missing-2';

            // Act
            $exception = MissingPrerequisitesException::forFeature($dependentName, $missing);

            // Assert
            expect($exception->getMessage())->toContain("Cannot activate 'test-feature'");
            expect($exception->getMessage())->toContain('missing prerequisites [missing-1, missing-2]');
        });

        test('messages differ between config true and false', function (): void {
            // Arrange
            $dependentName = 'feature-name';
            $missing = 'prereq-name';

            // Act
            config()->set('toggl.display_feature_in_exception', true);
            $detailedException = MissingPrerequisitesException::forFeature($dependentName, $missing);

            config()->set('toggl.display_feature_in_exception', false);
            $genericException = MissingPrerequisitesException::forFeature($dependentName, $missing);

            // Assert
            expect($detailedException->getMessage())->not->toBe($genericException->getMessage());
            expect($detailedException->getMessage())->toContain($dependentName);
            expect($detailedException->getMessage())->toContain($missing);
            expect($genericException->getMessage())->not->toContain($dependentName);
            expect($genericException->getMessage())->not->toContain($missing);
        });

        test('detailed message format uses sprintf correctly', function (): void {
            // Arrange
            config()->set('toggl.display_feature_in_exception', true);

            // Act
            $exception = MissingPrerequisitesException::forFeature('my-feature', 'prereq-a, prereq-b');

            // Assert
            expect($exception->getMessage())
                ->toBe("Cannot activate 'my-feature': missing prerequisites [prereq-a, prereq-b]");
        });
    });

    describe('Config Behavior', function (): void {
        test('respects config value changes during runtime', function (): void {
            // Arrange & Act
            config()->set('toggl.display_feature_in_exception', false);
            $exception1 = MissingPrerequisitesException::forFeature('feat', 'prereq');

            config()->set('toggl.display_feature_in_exception', true);
            $exception2 = MissingPrerequisitesException::forFeature('feat', 'prereq');

            config()->set('toggl.display_feature_in_exception', false);
            $exception3 = MissingPrerequisitesException::forFeature('feat', 'prereq');

            // Assert
            expect($exception1->getMessage())->toBe('Cannot activate feature: missing prerequisites');
            expect($exception2->getMessage())->toBe("Cannot activate 'feat': missing prerequisites [prereq]");
            expect($exception3->getMessage())->toBe('Cannot activate feature: missing prerequisites');
        });

        test('treats null config as false', function (): void {
            // Arrange
            config()->set('toggl.display_feature_in_exception');

            // Act
            $exception = MissingPrerequisitesException::forFeature('feature', 'prereq');

            // Assert
            expect($exception->getMessage())
                ->toBe('Cannot activate feature: missing prerequisites');
        });

        test('treats zero as false', function (): void {
            // Arrange
            config()->set('toggl.display_feature_in_exception', 0);

            // Act
            $exception = MissingPrerequisitesException::forFeature('feature', 'prereq');

            // Assert
            expect($exception->getMessage())
                ->toBe('Cannot activate feature: missing prerequisites');
        });

        test('treats non-zero as true', function (): void {
            // Arrange
            config()->set('toggl.display_feature_in_exception', 1);

            // Act
            $exception = MissingPrerequisitesException::forFeature('my-feature', 'my-prereq');

            // Assert
            expect($exception->getMessage())
                ->toBe("Cannot activate 'my-feature': missing prerequisites [my-prereq]");
        });

        test('treats string "true" as truthy', function (): void {
            // Arrange
            config()->set('toggl.display_feature_in_exception', 'true');

            // Act
            $exception = MissingPrerequisitesException::forFeature('feature', 'prereq');

            // Assert
            expect($exception->getMessage())
                ->toBe("Cannot activate 'feature': missing prerequisites [prereq]");
        });

        test('treats empty string as false', function (): void {
            // Arrange
            config()->set('toggl.display_feature_in_exception', '');

            // Act
            $exception = MissingPrerequisitesException::forFeature('feature', 'prereq');

            // Assert
            expect($exception->getMessage())
                ->toBe('Cannot activate feature: missing prerequisites');
        });
    });
});
