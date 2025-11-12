<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Strategies;

use Cline\Pole\Contracts\Strategy;
use RuntimeException;

use function assert;
use function crc32;
use function is_int;
use function is_numeric;
use function is_object;
use function is_string;
use function method_exists;
use function property_exists;
use function throw_if;

/**
 * Strategy for percentage-based feature rollouts using consistent hashing.
 *
 * This strategy enables features for a specified percentage of users/scopes using
 * CRC32 hashing to ensure consistent results. The same scope will always get the
 * same result, making it ideal for gradual feature rollouts and A/B testing.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class PercentageStrategy implements Strategy
{
    /**
     * Create a new percentage strategy instance.
     *
     * @param int    $percentage The percentage of scopes that should have the feature enabled (0-100)
     * @param string $seed       Optional seed for the hash function to vary distribution
     *
     * @throws RuntimeException If percentage is not between 0 and 100
     */
    public function __construct(
        private int $percentage,
        private string $seed = '',
    ) {
        throw_if($percentage < 0 || $percentage > 100, RuntimeException::class, 'Percentage must be between 0 and 100.');
    }

    /**
     * Resolve the feature value based on percentage rollout.
     *
     * Uses CRC32 hash of the scope identifier to consistently determine if this
     * scope falls within the rollout percentage. The same scope will always get
     * the same result unless the seed or percentage changes.
     *
     * @param mixed $scope The scope to evaluate (must not be null)
     *
     * @throws RuntimeException If scope is null or cannot be converted to an identifier
     *
     * @return bool True if the scope falls within the rollout percentage
     */
    public function resolve(mixed $scope): bool
    {
        throw_if($scope === null, RuntimeException::class, 'Percentage strategy requires a non-null scope for consistent hashing.');

        $identifier = $this->getScopeIdentifier($scope);
        $hash = crc32($this->seed.$identifier);

        return ($hash % 100) < $this->percentage;
    }

    /**
     * Determine if this strategy can handle null scopes.
     *
     * This strategy requires a non-null scope for consistent hashing.
     *
     * @return bool Always returns false
     */
    public function canHandleNullScope(): bool
    {
        return false;
    }

    /**
     * Extract a string identifier from the scope for hashing.
     *
     * Supports strings, numbers, and objects with getKey() method or id property.
     *
     * @param mixed $scope The scope to identify
     *
     * @throws RuntimeException If the scope type is not supported
     *
     * @return string The scope's string identifier
     */
    private function getScopeIdentifier(mixed $scope): string
    {
        if (is_string($scope)) {
            return $scope;
        }

        if (is_numeric($scope)) {
            return (string) $scope;
        }

        if (is_object($scope) && method_exists($scope, 'getKey')) {
            $key = $scope->getKey();
            assert(is_string($key) || is_int($key));

            return (string) $key;
        }

        if (is_object($scope) && property_exists($scope, 'id')) {
            $id = $scope->id;
            assert(is_string($id) || is_int($id));

            return (string) $id;
        }

        throw new RuntimeException('Unable to determine scope identifier for percentage strategy.');
    }
}
