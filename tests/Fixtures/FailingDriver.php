<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Cline\Toggl\Contracts\CanListStoredFeatures;
use Cline\Toggl\Contracts\Driver;
use Tests\Exceptions\SimulatedFailureException;

use function array_keys;

/**
 * Test fixture driver that throws exceptions on activate/deactivate.
 *
 * Used to test transaction rollback and error handling behavior.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class FailingDriver implements CanListStoredFeatures, Driver
{
    private array $features = [];

    private int $operationCount = 0;

    public function __construct(
        private readonly int $failOnOperation = 1,
        private readonly string $exceptionMessage = 'Simulated driver failure',
    ) {}

    public function define(string $feature, mixed $resolver = null): mixed
    {
        $this->features[$feature] = $resolver;

        return null;
    }

    public function defined(): array
    {
        return array_keys($this->features);
    }

    public function getAll(array $features): array
    {
        $result = [];

        foreach ($features as $feature => $contexts) {
            foreach ($contexts as $context) {
                $result[$feature][] = $this->get($feature, $context);
            }
        }

        return $result;
    }

    public function get(string $feature, mixed $context): mixed
    {
        return $this->features[$feature] ?? false;
    }

    public function set(string $feature, mixed $context, mixed $value): void
    {
        ++$this->operationCount;

        if ($this->operationCount === $this->failOnOperation) {
            throw SimulatedFailureException::withMessage($this->exceptionMessage);
        }

        $this->features[$feature] = $value;
    }

    public function setForAllContexts(string $feature, mixed $value): void
    {
        $this->features[$feature] = $value;
    }

    public function delete(string $feature, mixed $context): void
    {
        unset($this->features[$feature]);
    }

    public function purge(?array $features): void
    {
        if ($features === null) {
            $this->features = [];
        } else {
            foreach ($features as $feature) {
                unset($this->features[$feature]);
            }
        }
    }

    public function stored(): array
    {
        return array_keys($this->features);
    }

    public function resetOperationCount(): void
    {
        $this->operationCount = 0;
    }
}
