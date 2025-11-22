<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Strategies;

use Cline\Toggl\Contracts\Strategy;
use Cline\Toggl\Exceptions\CannotDetermineContextIdentifierException;
use Cline\Toggl\Exceptions\InvalidPercentageException;
use Cline\Toggl\Exceptions\PercentageStrategyException;

use function abs;
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
 * This strategy enables features for a specified percentage of users/contexts using
 * CRC32 hashing to ensure consistent results. The same context will always get the
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
     * @param int    $percentage The percentage of contexts that should have the feature enabled (0-100).
     *                           For example, 25 means 25% of users will see the feature.
     * @param string $seed       Optional seed for the hash function to vary distribution across
     *                           different feature rollouts. Change this to get a different set
     *                           of users for the same percentage.
     *
     * @throws InvalidPercentageException If percentage is not between 0 and 100
     */
    public function __construct(
        private int $percentage,
        private string $seed = '',
    ) {
        throw_if($percentage < 0 || $percentage > 100, InvalidPercentageException::outOfRange());
    }

    /**
     * Resolve the feature value based on percentage rollout.
     *
     * Uses CRC32 hash of the context identifier to consistently determine if this
     * context falls within the rollout percentage. The same context will always get
     * the same result unless the seed or percentage changes, ensuring a stable
     * user experience during gradual rollouts.
     *
     * @param mixed $meta The context to evaluate (must not be null - e.g., User, ID, string)
     *
     * @throws PercentageStrategyException If context is null or cannot be converted to an identifier
     *
     * @return bool True if the context falls within the rollout percentage, false otherwise
     */
    public function resolve(mixed $context, mixed $meta = null): bool
    {
        throw_if($context === null, PercentageStrategyException::requiresNonNullContext());

        $identifier = $this->getContextIdentifier($context);

        // Hash the seed + identifier to get a deterministic value for this context
        $hash = crc32($this->seed.$identifier);

        // Map the hash to 0-99 range and check if it falls within the percentage
        return (abs($hash) % 100) < $this->percentage;
    }

    /**
     * Determine if this strategy can handle null contexts.
     *
     * This strategy requires a non-null context for consistent hashing, as there
     * is no identifier to hash when context is null.
     *
     * @return bool Always returns false
     */
    public function canHandleNullContext(): bool
    {
        return false;
    }

    /**
     * Extract a string identifier from the context for hashing.
     *
     * Supports multiple context types:
     * - Strings: used directly
     * - Numbers: converted to string
     * - Objects with getKey() method: typically Eloquent models
     * - Objects with id property: generic objects with ID
     *
     * @throws CannotDetermineContextIdentifierException If the context type is not supported or lacks an identifier
     *
     * @return string The context's string identifier for hashing
     */
    private function getContextIdentifier(mixed $context): string
    {
        if (is_string($context)) {
            return $context;
        }

        if (is_numeric($context)) {
            return (string) $context;
        }

        if (is_object($context) && method_exists($context, 'getKey')) {
            $key = $context->getKey();
            assert(is_string($key) || is_int($key));

            return (string) $key;
        }

        if (is_object($context) && property_exists($context, 'id')) {
            $id = $context->id;
            assert(is_string($id) || is_int($id));

            return (string) $id;
        }

        throw CannotDetermineContextIdentifierException::forPercentageStrategy();
    }
}
