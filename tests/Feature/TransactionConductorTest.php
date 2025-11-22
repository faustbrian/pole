<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Conductors\TransactionConductor;
use Cline\Toggl\FeatureManager;
use Cline\Toggl\Toggl;
use Tests\Exceptions\SimulatedFailureException;
use Tests\Fixtures\FailingDriver;
use Tests\Fixtures\User;

/**
 * Transaction Conductor Test Suite
 *
 * Tests atomic feature operations with rollback capabilities.
 */
describe('Transaction Conductor', function (): void {
    describe('Basic Transactions', function (): void {
        test('commits single activation', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::transaction()
                ->activate('premium')
                ->commit($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('commits single deactivation', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');

            // Act
            Toggl::transaction()
                ->deactivate('premium')
                ->commit($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
        });

        test('commits multiple activations', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::transaction()
                ->activate('premium')
                ->activate('analytics')
                ->activate('export')
                ->commit($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeTrue();
        });

        test('commits mixed operations', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['old-feature', 'beta']);

            // Act
            Toggl::transaction()
                ->deactivate('old-feature')
                ->activate('premium')
                ->deactivate('beta')
                ->activate('analytics')
                ->commit($user);

            // Assert
            expect(Toggl::for($user)->active('old-feature'))->toBeFalse();
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('beta'))->toBeFalse();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
        });

        test('commits array of features', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::transaction()
                ->activate(['premium', 'analytics', 'export'])
                ->commit($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeTrue();
        });
    });

    describe('Manual Rollback', function (): void {
        test('rollback restores initial state after activation', function (): void {
            // Arrange
            $user = User::factory()->create();
            $transaction = Toggl::transaction()
                ->activate('premium');

            // Act
            $transaction = $transaction->commit($user);

            expect(Toggl::for($user)->active('premium'))->toBeTrue();

            $transaction->rollback($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
        });

        test('rollback restores initial state after deactivation', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');

            $transaction = Toggl::transaction()
                ->deactivate('premium');

            // Act
            $transaction = $transaction->commit($user);

            expect(Toggl::for($user)->active('premium'))->toBeFalse();

            $transaction->rollback($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('rollback restores multiple features', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['old-feature', 'beta']);

            $transaction = Toggl::transaction()
                ->deactivate(['old-feature', 'beta'])
                ->activate(['premium', 'analytics']);

            // Act
            $transaction = $transaction->commit($user);

            expect(Toggl::for($user)->active('old-feature'))->toBeFalse();
            expect(Toggl::for($user)->active('premium'))->toBeTrue();

            $transaction->rollback($user);

            // Assert - Old features restored
            expect(Toggl::for($user)->active('old-feature'))->toBeTrue();
            expect(Toggl::for($user)->active('beta'))->toBeTrue();

            // Assert - New features removed
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
            expect(Toggl::for($user)->active('analytics'))->toBeFalse();
        });

        test('rollback does nothing if not committed', function (): void {
            // Arrange
            $user = User::factory()->create();
            $transaction = Toggl::transaction()
                ->activate('premium');

            // Act - Rollback without commit
            $transaction->rollback($user);

            // Assert - No changes made
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('subscription upgrade transaction', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['basic-plan', 'basic-support']);

            // Act - Upgrade subscription
            Toggl::transaction()
                ->deactivate(['basic-plan', 'basic-support'])
                ->activate(['premium-plan', 'priority-support', 'analytics', 'export'])
                ->commit($user);

            // Assert - Old plan removed
            expect(Toggl::for($user)->active('basic-plan'))->toBeFalse();
            expect(Toggl::for($user)->active('basic-support'))->toBeFalse();

            // Assert - New plan active
            expect(Toggl::for($user)->active('premium-plan'))->toBeTrue();
            expect(Toggl::for($user)->active('priority-support'))->toBeTrue();
            expect(Toggl::for($user)->active('analytics'))->toBeTrue();
            expect(Toggl::for($user)->active('export'))->toBeTrue();
        });

        test('feature migration with rollback capability', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('v1-api');

            $transaction = Toggl::transaction()
                ->deactivate('v1-api')
                ->activate('v2-api');

            // Act - Commit migration
            $transaction = $transaction->commit($user);

            expect(Toggl::for($user)->active('v2-api'))->toBeTrue();

            // Simulate failure - rollback to v1
            $transaction->rollback($user);

            // Assert - Back to v1
            expect(Toggl::for($user)->active('v1-api'))->toBeTrue();
            expect(Toggl::for($user)->active('v2-api'))->toBeFalse();
        });

        test('beta enrollment transaction', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate(['stable-ui', 'stable-api']);

            // Act - Enroll in beta
            Toggl::transaction()
                ->activate(['beta-ui', 'beta-api', 'debug-mode'])
                ->commit($user);

            // Assert - Beta features active, stable features still active
            expect(Toggl::for($user)->active('stable-ui'))->toBeTrue();
            expect(Toggl::for($user)->active('stable-api'))->toBeTrue();
            expect(Toggl::for($user)->active('beta-ui'))->toBeTrue();
            expect(Toggl::for($user)->active('beta-api'))->toBeTrue();
            expect(Toggl::for($user)->active('debug-mode'))->toBeTrue();
        });
    });

    describe('Failure Handling', function (): void {
        test('onFailure callback receives exception', function (): void {
            // Arrange
            $user = User::factory()->create();
            $callbackExecuted = false;
            $receivedException = null;

            // Act & Assert
            try {
                Toggl::transaction()
                    ->activate('premium')
                    ->onFailure(function ($exception) use (&$callbackExecuted, &$receivedException): void {
                        $callbackExecuted = true;
                        $receivedException = $exception;
                    })
                    ->commit($user);

                // Force an exception by accessing non-existent method
                // This is simulated - in real usage, driver errors would trigger this
                throw SimulatedFailureException::forTest();
            } catch (Exception $exception) {
                // Manually trigger the callback for testing
                $transaction = Toggl::transaction()
                    ->onFailure(function ($exception) use (&$callbackExecuted, &$receivedException): void {
                        $callbackExecuted = true;
                        $receivedException = $exception;
                    });

                $callback = $transaction->failureCallback();

                if ($callback !== null) {
                    $callback($exception, $user);
                }
            }

            // Assert
            expect($callbackExecuted)->toBeTrue();
            expect($receivedException)->toBeInstanceOf(Exception::class);
        });

        test('onFailure callback receives context', function (): void {
            // Arrange
            $user = User::factory()->create();
            $receivedContext = null;

            // Act
            try {
                throw SimulatedFailureException::withMessage('Test');
            } catch (Exception $exception) {
                $transaction = Toggl::transaction()
                    ->onFailure(function ($exception, $context) use (&$receivedContext): void {
                        $receivedContext = $context;
                    });

                $callback = $transaction->failureCallback();

                if ($callback !== null) {
                    $callback($exception, $user);
                }
            }

            // Assert
            expect($receivedContext)->toBe($user);
        });
    });

    describe('Edge Cases', function (): void {
        test('empty transaction does nothing', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::transaction()->commit($user);

            // Assert - No features active
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
        });

        test('transaction exposes operations', function (): void {
            // Arrange & Act
            $transaction = Toggl::transaction()
                ->activate('premium')
                ->deactivate('trial');

            // Assert
            $operations = $transaction->operations();
            expect($operations)->toHaveCount(2);
            expect($operations[0]['type'])->toBe('activate');
            expect($operations[1]['type'])->toBe('deactivate');
        });

        test('transaction exposes initial state after commit', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('existing');

            $transaction = Toggl::transaction()
                ->activate('premium')
                ->deactivate('existing');

            // Act
            $transaction = $transaction->commit($user);

            // Assert
            $initialState = $transaction->initialState();
            expect($initialState)->not->toBeNull();
            expect($initialState)->toBeArray();
        });

        test('activating already active feature is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');

            // Act
            Toggl::transaction()
                ->activate('premium')
                ->activate('premium')
                ->commit($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('deactivating already inactive feature is idempotent', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::transaction()
                ->deactivate('premium')
                ->deactivate('premium')
                ->commit($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
        });

        test('multiple commits on same transaction use initial state', function (): void {
            // Arrange
            $user = User::factory()->create();
            $transaction = Toggl::transaction()
                ->activate('premium');

            // Act - First commit
            $transaction = $transaction->commit($user);

            expect(Toggl::for($user)->active('premium'))->toBeTrue();

            // Manually deactivate
            Toggl::for($user)->deactivate('premium');
            expect(Toggl::for($user)->active('premium'))->toBeFalse();

            // Second commit - should capture new initial state
            $transaction = $transaction->commit($user);
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('commit handles unknown operation type gracefully', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Create transaction with unknown operation type (defensive code test)
            $transaction = new TransactionConductor(
                app(FeatureManager::class),
                [
                    ['type' => 'unknown', 'features' => ['test-feature']],
                ],
            );

            // Act - Should not throw exception, default branch returns null
            $transaction->commit($user);

            // Assert - No features should be modified
            expect(Toggl::for($user)->active('test-feature'))->toBeFalse();
        });
    });

    describe('Exception Handling and Rollback', function (): void {
        test('commit automatically rolls back on driver exception', function (): void {
            // Arrange
            $user = User::factory()->create();

            $failingDriver = new FailingDriver(
                failOnOperation: 2, // Fail on second operation
                exceptionMessage: 'Simulated driver failure during transaction',
            );

            // Configure a store with the failing driver
            config()->set('toggl.stores.failing', [
                'driver' => 'failing',
            ]);

            // Flush singleton and get fresh instance from container
            app()->forgetInstance(FeatureManager::class);
            $manager = app(FeatureManager::class);
            $manager->extend('failing', fn (): FailingDriver => $failingDriver);
            $manager->setDefaultDriver('failing');

            // Set initial state
            $failingDriver->set('existing-feature', $user, true);

            // Create transaction that will fail on second activate
            $transaction = new TransactionConductor($manager)
                ->activate('first-feature')
                ->activate('second-feature'); // This will trigger exception

            // Act & Assert
            try {
                $transaction->commit($user);
                $this->fail('Expected exception was not thrown');
            } catch (Exception $exception) {
                // Assert exception was thrown
                expect($exception->getMessage())->toBe('Simulated driver failure during transaction');

                // Assert rollback happened - features should NOT be active
                // The transaction should have rolled back to initial state
                expect($failingDriver->get('first-feature', $user))->toBeFalse();
                expect($failingDriver->get('second-feature', $user))->toBeFalse();
                expect($failingDriver->get('existing-feature', $user))->toBeTrue(); // Restored to initial state
            }
        });

        test('commit calls onFailure callback on exception', function (): void {
            // Arrange
            $user = User::factory()->create();
            $failureCalled = false;
            $capturedException = null;
            $capturedContext = null;

            $failingDriver = new FailingDriver(
                failOnOperation: 1,
                exceptionMessage: 'Driver error',
            );

            config()->set('toggl.stores.failing', [
                'driver' => 'failing',
            ]);

            app()->forgetInstance(FeatureManager::class);
            $manager = app(FeatureManager::class);
            $manager->extend('failing', fn (): FailingDriver => $failingDriver);
            $manager->setDefaultDriver('failing');

            // Create transaction with failure callback
            $transaction = new TransactionConductor($manager)
                ->activate('test-feature')
                ->onFailure(function ($exception, $context) use (&$failureCalled, &$capturedException, &$capturedContext): void {
                    $failureCalled = true;
                    $capturedException = $exception;
                    $capturedContext = $context;
                });

            // Act
            try {
                $transaction->commit($user);
                $this->fail('Expected exception was not thrown');
            } catch (Exception $exception) {
                // Assert callback was executed
                expect($failureCalled)->toBeTrue();
                expect($capturedException)->toBeInstanceOf(Exception::class);
                expect($capturedException->getMessage())->toBe('Driver error');
                expect($capturedContext)->toBe($user);

                // Assert exception was re-thrown
                expect($exception)->toBe($capturedException);
            }
        });

        test('commit preserves initial state on exception', function (): void {
            // Arrange
            $user = User::factory()->create();

            $failingDriver = new FailingDriver(
                failOnOperation: 2,
                exceptionMessage: 'Transaction failed',
            );

            config()->set('toggl.stores.failing', [
                'driver' => 'failing',
            ]);

            app()->forgetInstance(FeatureManager::class);
            $manager = app(FeatureManager::class);
            $manager->extend('failing', fn (): FailingDriver => $failingDriver);
            $manager->setDefaultDriver('failing');

            // Set initial state
            $failingDriver->set('existing-feature', $user, true);

            // Create transaction that deactivates existing and activates new
            $transaction = new TransactionConductor($manager)
                ->deactivate('existing-feature')
                ->activate('new-feature'); // Will fail here

            // Act
            try {
                $transaction->commit($user);
                $this->fail('Expected exception was not thrown');
            } catch (Exception) {
                // Assert: existing-feature should be restored to true
                expect($failingDriver->get('existing-feature', $user))->toBeTrue();

                // Assert: new-feature should not be activated
                expect($failingDriver->get('new-feature', $user))->toBeFalse();
            }
        });

        test('commit handles multiple operation failures with rollback', function (): void {
            // Arrange
            $user = User::factory()->create();

            $failingDriver = new FailingDriver(
                failOnOperation: 6, // Fail on 6th operation (after 3 initial sets + 3 transaction operations)
                exceptionMessage: 'Third operation failed',
            );

            config()->set('toggl.stores.failing', [
                'driver' => 'failing',
            ]);

            app()->forgetInstance(FeatureManager::class);
            $manager = app(FeatureManager::class);
            $manager->extend('failing', fn (): FailingDriver => $failingDriver);
            $manager->setDefaultDriver('failing');

            // Set multiple initial features (operations 1-3)
            $failingDriver->set('feature-a', $user, true);
            $failingDriver->set('feature-b', $user, false);
            $failingDriver->set('feature-c', $user, true);

            // Create complex transaction
            // Operations during commit: 4 (deactivate a), 5 (activate b), 6 (deactivate c - will fail)
            $transaction = new TransactionConductor($manager)
                ->deactivate('feature-a')  // Operation 4
                ->activate('feature-b')     // Operation 5
                ->deactivate('feature-c');  // Operation 6 - will fail

            // Act
            try {
                $transaction->commit($user);
                $this->fail('Expected exception was not thrown');
            } catch (Exception) {
                // Assert all features rolled back to initial state
                expect($failingDriver->get('feature-a', $user))->toBeTrue();
                expect($failingDriver->get('feature-b', $user))->toBeFalse();
                expect($failingDriver->get('feature-c', $user))->toBeTrue();
            }
        });
    });
});
