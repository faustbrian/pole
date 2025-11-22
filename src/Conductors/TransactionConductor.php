<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use BackedEnum;
use Cline\Toggl\FeatureManager;
use Closure;
use Exception;

use function array_key_exists;
use function assert;
use function is_array;
use function is_string;

/**
 * Conductor for atomic feature transactions with rollback.
 *
 * Provides transactional semantics for feature flag operations, ensuring that
 * batches of feature activations/deactivations either complete successfully as
 * a unit or rollback to their original state on failure. This prevents partial
 * state changes that could leave features in inconsistent configurations.
 *
 * Transactions capture the initial state before applying operations, enabling
 * automatic rollback to restore features to their pre-transaction values if any
 * operation fails. This is critical for coordinated feature rollouts where
 * related flags must activate together or not at all.
 *
 * ```php
 * Toggl::transaction()
 *     ->activate(['feature-a', 'feature-b'])
 *     ->deactivate('old-feature')
 *     ->onFailure(fn($e, $context) => Log::error("Rollout failed: {$e->getMessage()}"))
 *     ->commit($user);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @phpstan-type Operation array{type: string, features: array<BackedEnum|string>}
 */
final readonly class TransactionConductor
{
    /**
     * Create a new transaction conductor instance.
     *
     * @param FeatureManager           $manager      The feature manager instance used to execute operations
     *                                               and interact with the underlying driver
     * @param array<Operation>         $operations   Queue of pending operations to execute on commit. Each
     *                                               operation specifies a type (activate/deactivate) and the
     *                                               features to apply that operation to
     * @param null|Closure             $onFailure    Optional callback invoked when transaction fails before rollback.
     *                                               Receives the exception and context as arguments for logging or
     *                                               custom error handling
     * @param null|array<string, bool> $initialState Captured initial feature states keyed by feature name. Used
     *                                               during rollback to restore features to their pre-transaction
     *                                               values. Null until first commit() captures state
     */
    public function __construct(
        private FeatureManager $manager,
        private array $operations = [],
        private ?Closure $onFailure = null,
        private ?array $initialState = null,
    ) {}

    /**
     * Add activation operation to transaction.
     *
     * Queues one or more features for activation when the transaction commits.
     * Operations are applied in the order they are added to the transaction.
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $features Feature identifier(s) to activate
     * @return self                                       New conductor instance with the operation added to the queue
     */
    public function activate(string|BackedEnum|array $features): self
    {
        $features = is_array($features) ? $features : [$features];

        return new self($this->manager, [...$this->operations, ['type' => 'activate', 'features' => $features]], $this->onFailure, $this->initialState);
    }

    /**
     * Add deactivation operation to transaction.
     *
     * Queues one or more features for deactivation when the transaction commits.
     * Operations are applied in the order they are added to the transaction.
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $features Feature identifier(s) to deactivate
     * @return self                                       New conductor instance with the operation added to the queue
     */
    public function deactivate(string|BackedEnum|array $features): self
    {
        $features = is_array($features) ? $features : [$features];

        return new self($this->manager, [...$this->operations, ['type' => 'deactivate', 'features' => $features]], $this->onFailure, $this->initialState);
    }

    /**
     * Set failure callback.
     *
     * Registers a callback to be invoked if any operation in the transaction
     * fails. The callback receives the exception and context, allowing custom
     * error handling, logging, or notification before the automatic rollback.
     *
     * @param  Closure $callback Callback invoked on failure: function(Exception $e, mixed $context): void
     * @return self    New conductor instance with the failure callback registered
     */
    public function onFailure(Closure $callback): self
    {
        return new self($this->manager, $this->operations, $callback, $this->initialState);
    }

    /**
     * Commit transaction for specific context.
     *
     * Terminal operation that executes all queued operations for the given context.
     * Before executing operations, captures the current state of all affected
     * features to enable rollback on failure. If any operation throws an exception,
     * automatically rolls back to the initial state and re-throws the exception.
     *
     * Returns a new conductor instance with the captured initial state, which is
     * necessary for manual rollback() calls if needed later.
     *
     * @param mixed $context The context to execute operations for (user, team, etc.)
     *
     * @throws Exception Re-throws any exception from failed operations after rollback completes
     *
     * @return self New conductor instance with initial state captured for potential rollback
     */
    public function commit(mixed $context): self
    {
        $contextdDriver = $this->manager->for($context);

        // Capture initial state
        $initialState = [];

        foreach ($this->operations as $operation) {
            /** @var array<BackedEnum|string> $features */
            $features = $operation['features'];

            foreach ($features as $feature) {
                $featureKey = $feature instanceof BackedEnum ? $feature->value : $feature;
                assert(is_string($featureKey), 'Feature key must be a string');

                if (!array_key_exists($featureKey, $initialState)) {
                    $initialState[$featureKey] = $contextdDriver->active($feature);
                }
            }
        }

        // Create new instance with initial state
        $transaction = new self($this->manager, $this->operations, $this->onFailure, $initialState);

        try {
            // Execute operations
            foreach ($this->operations as $operation) {
                /** @var string $type */
                $type = $operation['type'];

                /** @var array<BackedEnum|string> $features */
                $features = $operation['features'];

                match ($type) {
                    'activate' => $contextdDriver->activate($features),
                    'deactivate' => $contextdDriver->deactivate($features),
                    default => null,
                };
            }
        } catch (Exception $exception) {
            // Rollback on failure
            $transaction->rollback($context);

            // Execute failure callback if set
            if ($this->onFailure instanceof Closure) {
                ($this->onFailure)($exception, $context);
            }

            throw $exception;
        }

        return $transaction;
    }

    /**
     * Rollback transaction for specific context.
     *
     * Restores all features to their initial state as captured before the
     * transaction committed. This undoes all operations that were applied
     * during the commit, effectively reverting to the pre-transaction state.
     *
     * No-op if called before commit() has captured initial state. Automatic
     * rollback occurs on commit() failure, but this method enables manual
     * rollback for testing or custom error handling scenarios.
     *
     * @param mixed $context The context to rollback features for
     */
    public function rollback(mixed $context): void
    {
        if ($this->initialState === null) {
            return;
        }

        $contextdDriver = $this->manager->for($context);

        foreach ($this->initialState as $feature => $wasActive) {
            if ($wasActive) {
                $contextdDriver->activate($feature);
            } else {
                $contextdDriver->deactivate($feature);
            }
        }
    }

    /**
     * Get the operations in the transaction.
     *
     * @return array<Operation> Array of pending operations to execute on commit
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * Get the initial state.
     *
     * Returns the captured feature states before transaction execution. This
     * is used internally during rollback to restore features to their original
     * values. Null until commit() captures the state.
     *
     * @return null|array<string, bool> Map of feature names to their active states before commit,
     *                                  or null if commit() has not been called yet
     */
    public function initialState(): ?array
    {
        return $this->initialState;
    }

    /**
     * Get the failure callback.
     *
     * @return null|Closure The callback to invoke on transaction failure, or null if not set
     */
    public function failureCallback(): ?Closure
    {
        return $this->onFailure;
    }
}
