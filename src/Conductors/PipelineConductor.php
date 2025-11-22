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

use function is_array;

/**
 * Conductor for pipelining multiple feature operations in sequence.
 *
 * Provides a chainable, immutable API for building and executing a sequence of feature
 * operations (activate, deactivate, tap) that will be applied to a context when the
 * pipeline is executed. Each method returns a new instance maintaining immutability.
 *
 * ```php
 * Toggl::pipeline()
 *     ->activate(['premium', 'analytics'])
 *     ->tap(fn() => Log::info('Features activated'))
 *     ->deactivate('legacy-ui')
 *     ->for($user);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @phpstan-type ActivateOperation array{type: 'activate', features: array<BackedEnum|string>}
 * @phpstan-type DeactivateOperation array{type: 'deactivate', features: array<BackedEnum|string>}
 * @phpstan-type TapOperation array{type: 'tap', callback: Closure}
 * @phpstan-type Operation ActivateOperation|DeactivateOperation|TapOperation
 *
 * @api
 */
final readonly class PipelineConductor
{
    /**
     * Create a new pipeline conductor instance.
     *
     * Initializes an immutable pipeline with a feature manager and optional
     * pre-existing operations. Typically instantiated via the FeatureManager
     * rather than directly.
     *
     * @param FeatureManager        $manager    the feature manager instance that will execute
     *                                          the operations when the pipeline is applied to a context
     * @param array<int, Operation> $operations Ordered array of operations to execute sequentially.
     *                                          Each operation includes type and relevant data (features,
     *                                          callbacks, etc.). Defaults to empty array for new pipelines.
     */
    public function __construct(
        private FeatureManager $manager,
        private array $operations = [],
    ) {}

    /**
     * Add feature activation operation to the pipeline.
     *
     * Queues one or more features for activation when the pipeline is executed.
     * Returns a new pipeline instance with the operation added, maintaining immutability.
     * Accepts single feature name/enum or array of multiple features.
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $features single feature (string or BackedEnum)
     *                                                              or array of features to queue for activation
     * @return self                                       new pipeline instance with added operation
     */
    public function activate(string|BackedEnum|array $features): self
    {
        $features = is_array($features) ? $features : [$features];

        return new self($this->manager, [...$this->operations, ['type' => 'activate', 'features' => $features]]);
    }

    /**
     * Add feature deactivation operation to the pipeline.
     *
     * Queues one or more features for deactivation when the pipeline is executed.
     * Returns a new pipeline instance with the operation added, maintaining immutability.
     * Accepts single feature name/enum or array of multiple features.
     *
     * @param  array<BackedEnum|string>|BackedEnum|string $features single feature (string or BackedEnum)
     *                                                              or array of features to queue for deactivation
     * @return self                                       new pipeline instance with added operation
     */
    public function deactivate(string|BackedEnum|array $features): self
    {
        $features = is_array($features) ? $features : [$features];

        return new self($this->manager, [...$this->operations, ['type' => 'deactivate', 'features' => $features]]);
    }

    /**
     * Add a side-effect callback operation to the pipeline.
     *
     * Queues a callback that will be executed at this point in the pipeline sequence.
     * The callback receives the context as its parameter, allowing custom logic or logging
     * without breaking the pipeline chain. Useful for debugging, logging, or notifications.
     *
     * @param  Closure $callback Closure that receives the context object as its parameter.
     *                           Executed during pipeline application for side effects.
     * @return self    new pipeline instance with added tap operation
     */
    public function tap(Closure $callback): self
    {
        return new self($this->manager, [...$this->operations, ['type' => 'tap', 'callback' => $callback]]);
    }

    /**
     * Execute all queued pipeline operations for the specified context.
     *
     * Terminal method that applies all operations in sequence to the given context.
     * Processes activate operations by enabling features, deactivate operations by
     * disabling features, and tap operations by invoking callbacks with the context.
     * Order of operations is preserved as they were added to the pipeline.
     *
     * @param mixed $context the context (user, team, tenant, etc.) to apply all queued
     *                       operations to. Can be any object or value supported by
     *                       the feature manager's contextual driver.
     */
    public function for(mixed $context): void
    {
        $contextdDriver = $this->manager->for($context);

        foreach ($this->operations as $operation) {
            if ($operation['type'] === 'activate') {
                $contextdDriver->activate($operation['features']);
            } elseif ($operation['type'] === 'deactivate') {
                $contextdDriver->deactivate($operation['features']);
            } elseif ($operation['type'] === 'tap') {
                $operation['callback']($context);
            }
        }
    }

    /**
     * Retrieve all queued operations in the pipeline.
     *
     * Returns the ordered array of operations that will be executed when the
     * pipeline is applied to a context. Useful for inspection, testing, or debugging.
     *
     * @return array<int, Operation> ordered array of operation definitions, each containing
     *                               a type ('activate', 'deactivate', 'tap') and relevant
     *                               data (features array or callback closure)
     */
    public function operations(): array
    {
        return $this->operations;
    }
}
