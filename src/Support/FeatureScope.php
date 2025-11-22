<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Support;

use const SORT_STRING;

use function array_filter;
use function array_key_exists;
use function implode;
use function is_scalar;
use function json_encode;
use function ksort;

/**
 * Value object representing a feature scope for conditional feature resolution.
 *
 * Encapsulates the scope kind (e.g., 'user', 'team', 'tenant') and
 * scope constraints used for feature flag resolution. Null values
 * represent wildcards that match any value at that level.
 *
 * Common use cases:
 * - Organizational hierarchies: company → division → team → user
 * - Multi-tenancy: tenant → environment → region
 * - A/B testing: experiment → variant
 * - Plan-based features: plan → addon
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class FeatureScope
{
    /**
     * Create a new feature scope instance.
     *
     * @param string               $kind        The scope kind identifier (e.g., 'user', 'team', 'tenant')
     * @param array<string, mixed> $constraints Scope constraint properties. Keys are constraint names
     *                                          (e.g., 'company_id', 'tenant_id'). Null values are wildcards.
     */
    public function __construct(
        public string $kind,
        public array $constraints,
    ) {}

    /**
     * Create a scope instance from an array representation.
     *
     * Reconstructs a FeatureScope from the array format returned by toArray().
     * Used when deserializing scopes from database JSON columns.
     *
     * @param  array{kind: string, scopes: array<string, mixed>} $data Array with 'kind' and 'scopes' keys
     * @return self                                              A new FeatureScope instance
     */
    public static function fromArray(array $data): self
    {
        return new self(
            kind: $data['kind'],
            constraints: $data['scopes'],
        );
    }

    /**
     * Get only the non-null constraint properties.
     *
     * Returns a filtered array containing only constraint properties with non-null values.
     * Useful for querying features where wildcards should be excluded from WHERE clauses.
     *
     * @return array<string, mixed> Non-null constraint properties
     */
    public function definedConstraints(): array
    {
        return array_filter($this->constraints, static fn ($value): bool => $value !== null);
    }

    /**
     * Convert the scope to an array representation.
     *
     * Returns an associative array with 'kind' and 'scopes' keys, suitable for
     * JSON serialization and database storage.
     *
     * @return array{kind: string, scopes: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'scopes' => $this->constraints,
        ];
    }

    /**
     * Check if this scope matches another scope's constraints.
     *
     * Returns true if all non-null properties in the target scope match the
     * corresponding properties in this scope. Null values in target are wildcards
     * that match any value in this scope.
     *
     * Example:
     * ```php
     * $userScope = new FeatureScope('user', [
     *     'company_id' => 3,
     *     'org_id' => 5,
     *     'team_id' => 7,
     *     'user_id' => 10,
     * ]);
     *
     * $featureScope = new FeatureScope('user', [
     *     'company_id' => 3,
     *     'org_id' => 5,
     *     'team_id' => null,  // wildcard
     *     'user_id' => null,  // wildcard
     * ]);
     *
     * $userScope->matches($featureScope); // true
     * ```
     *
     * @param  self $target The target scope to match against
     * @return bool True if this scope matches all target constraints
     */
    public function matches(self $target): bool
    {
        // Must be same kind
        if ($this->kind !== $target->kind) {
            return false;
        }

        // Check each defined constraint in target
        foreach ($target->definedConstraints() as $key => $value) {
            // If key doesn't exist in this scope, no match
            if (!array_key_exists($key, $this->constraints)) {
                return false;
            }

            // If values don't match, no match
            if ($this->constraints[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate a normalized cache key for this scope.
     *
     * Creates a deterministic string representation suitable for use as a cache key.
     * Sorts constraint keys alphabetically to ensure consistent keys regardless of
     * property order.
     *
     * @return string A normalized cache key (e.g., 'user:company_id=3|org_id=5')
     */
    public function toCacheKey(): string
    {
        $sorted = $this->constraints;
        ksort($sorted, SORT_STRING);

        $parts = [];

        foreach ($sorted as $key => $value) {
            $parts[] = $key.'='.($value === null ? 'null' : (is_scalar($value) ? (string) $value : json_encode($value)));
        }

        return $this->kind.':'.implode('|', $parts);
    }
}
