<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use BackedEnum;
use Cline\Toggl\Exceptions\InvalidStrategyDataException;
use Cline\Toggl\FeatureManager;
use Cline\Toggl\Support\ContextResolver;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Support\Facades\Date;

use const PHP_INT_MAX;

use function abs;
use function array_key_exists;
use function crc32;
use function is_array;
use function is_int;
use function is_string;
use function throw_unless;

/**
 * Conductor for advanced strategy-based feature activation patterns.
 *
 * Provides a unified fluent interface for implementing sophisticated feature rollout
 * strategies including percentage-based rollouts, time-based activation windows, and
 * A/B/n variant distributions. Combines multiple activation strategies into a single
 * flexible API for complex release management scenarios.
 *
 * ```php
 * // Percentage rollout
 * Toggl::strategy('new-ui')->percentage(25)->for($user);
 *
 * // Time-based activation
 * Toggl::strategy('summer-sale')->from('2024-06-01')->until('2024-08-31')->activate();
 *
 * // A/B/n testing with variant distribution
 * Toggl::strategy('checkout-flow')->variants([
 *     'control' => 40,
 *     'variant-a' => 30,
 *     'variant-b' => 30
 * ])->for($user);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class StrategyConductor
{
    /**
     * Create a new strategy conductor instance.
     *
     * Initializes an immutable strategy conductor with configurable strategy type and data.
     * Typically instantiated via FeatureManager's strategy() method.
     *
     * @param FeatureManager    $manager      the feature manager instance for executing strategies
     * @param BackedEnum|string $feature      the feature identifier to apply strategy to
     * @param null|string       $strategyType The strategy type: 'percentage', 'time', or 'variants'.
     *                                        Null until a strategy method is called.
     * @param mixed             $strategyData Configuration data specific to the strategy type.
     *                                        Structure varies based on strategyType.
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum $feature,
        private ?string $strategyType = null,
        private mixed $strategyData = null,
    ) {}

    /**
     * Configure percentage-based rollout strategy.
     *
     * Uses consistent hashing to assign contexts to rollout buckets deterministically.
     *
     * @param  int  $percentage percentage of contexts to include (0-100)
     * @return self new conductor with percentage strategy configured
     */
    public function percentage(int $percentage): self
    {
        return new self($this->manager, $this->feature, 'percentage', $percentage);
    }

    /**
     * Set start date for time-based activation strategy.
     *
     * @param  string $date start date in parseable format (e.g., 'Y-m-d')
     * @return self   new conductor with 'from' date configured
     */
    public function from(string $date): self
    {
        $data = is_array($this->strategyData) ? $this->strategyData : [];
        $data['from'] = $date;

        return new self($this->manager, $this->feature, 'time', $data);
    }

    /**
     * Set end date for time-based activation strategy.
     *
     * @param  string $date end date in parseable format (e.g., 'Y-m-d')
     * @return self   new conductor with 'until' date configured
     */
    public function until(string $date): self
    {
        $data = is_array($this->strategyData) ? $this->strategyData : [];
        $data['until'] = $date;

        return new self($this->manager, $this->feature, 'time', $data);
    }

    /**
     * Configure variant distribution for A/B/n testing.
     *
     * @param  array<string, int> $variants variant name to weight mapping. Weights should
     *                                      sum to 100 for proper distribution.
     * @return self               new conductor with variants strategy configured
     */
    public function variants(array $variants): self
    {
        return new self($this->manager, $this->feature, 'variants', $variants);
    }

    /**
     * Execute the configured strategy for the specified context.
     *
     * Terminal method that applies the strategy (percentage, time, or variants) to determine
     * and set feature activation for the context.
     *
     * @param mixed $context the context to evaluate and apply strategy to
     */
    public function for(mixed $context): void
    {
        // Validate context early - throws if invalid
        $togglContext = ContextResolver::resolve($context);

        if ($this->strategyType === 'percentage') {
            $this->applyPercentageStrategy($togglContext);
        } elseif ($this->strategyType === 'time') {
            $this->applyTimeStrategy($togglContext);
        } elseif ($this->strategyType === 'variants') {
            $this->applyVariantsStrategy($togglContext);
        }
    }

    /**
     * Execute time-based strategy globally without context.
     *
     * Terminal method specifically for global time-based activation. Activates the feature
     * globally if current time falls within configured time window.
     */
    public function activate(): void
    {
        if ($this->strategyType === 'time') {
            $this->applyGlobalTimeStrategy();
        }
    }

    /**
     * Get the feature being managed.
     */
    public function feature(): string|BackedEnum
    {
        return $this->feature;
    }

    /**
     * Get the strategy type.
     */
    public function strategyType(): ?string
    {
        return $this->strategyType;
    }

    /**
     * Get the strategy data.
     */
    public function strategyData(): mixed
    {
        return $this->strategyData;
    }

    /**
     * Apply percentage-based rollout.
     *
     * @param TogglContext $context the context to apply percentage rollout to
     */
    private function applyPercentageStrategy(TogglContext $context): void
    {
        throw_unless(is_int($this->strategyData), InvalidStrategyDataException::mustBeInteger());

        $percentage = $this->strategyData;
        $contextId = $context->serialize();

        // Use CRC32 hash for consistent assignment
        $featureString = $this->featureToString($this->feature);
        $hash = crc32($featureString.$contextId);
        $bucket = abs($hash % 100);

        if ($bucket < $percentage) {
            $this->manager->for($context)->activate($this->feature);
        }
    }

    /**
     * Apply time-based strategy for context.
     *
     * @param TogglContext $context the context to apply time-based strategy to
     */
    private function applyTimeStrategy(TogglContext $context): void
    {
        throw_unless(is_array($this->strategyData), InvalidStrategyDataException::mustBeArray('time'));

        $data = $this->strategyData;
        $now = Date::now()->getTimestamp();

        $from = array_key_exists('from', $data) && is_string($data['from'])
            ? Date::parse($data['from'])->getTimestamp()
            : 0;
        $until = array_key_exists('until', $data) && is_string($data['until'])
            ? Date::parse($data['until'])->getTimestamp()
            : PHP_INT_MAX;

        if ($now >= $from && $now <= $until) {
            $this->manager->for($context)->activate($this->feature);
        }
    }

    /**
     * Apply global time-based strategy.
     */
    private function applyGlobalTimeStrategy(): void
    {
        throw_unless(is_array($this->strategyData), InvalidStrategyDataException::mustBeArray('time'));

        $data = $this->strategyData;
        $now = Date::now()->getTimestamp();

        $from = array_key_exists('from', $data) && is_string($data['from'])
            ? Date::parse($data['from'])->getTimestamp()
            : 0;
        $until = array_key_exists('until', $data) && is_string($data['until'])
            ? Date::parse($data['until'])->getTimestamp()
            : PHP_INT_MAX;

        if ($now >= $from && $now <= $until) {
            $this->manager->activate($this->feature);
        }
    }

    /**
     * Apply variant distribution strategy.
     *
     * @param TogglContext $context the context to apply variant distribution to
     */
    private function applyVariantsStrategy(TogglContext $context): void
    {
        throw_unless(is_array($this->strategyData), InvalidStrategyDataException::mustBeArray('variants'));

        /** @var array<string, int> $variants */
        $variants = $this->strategyData;
        $featureString = $this->featureToString($this->feature);
        $this->manager->defineVariant($featureString, $variants);
        $this->manager->variant($featureString)->for($context);
    }

    /**
     * Convert BackedEnum|string to string.
     *
     * @param  BackedEnum|string $feature feature to convert
     * @return string            string representation of the feature
     */
    private function featureToString(BackedEnum|string $feature): string
    {
        return $feature instanceof BackedEnum ? (string) $feature->value : $feature;
    }
}
