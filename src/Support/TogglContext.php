<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Support;

use Cline\Toggl\Contracts\Serializable;
use Stringable;

use function class_basename;
use function is_scalar;
use function mb_strtolower;
use function serialize;
use function sprintf;

/**
 * Value object representing a unified feature context.
 *
 * Encapsulates all context information needed for feature flag operations:
 * - The context identifier (id) for direct lookups
 * - The context type for polymorphic storage
 * - Optional feature scope for scoped feature resolution
 *
 * This provides a single representation that can be used consistently across
 * all drivers and operations.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class TogglContext implements Serializable
{
    /**
     * The scope kind identifier (e.g., 'user', 'team').
     *
     * Derived from scope if present, otherwise from class basename.
     */
    public string $kind;

    /**
     * Create a new toggl context instance.
     *
     * @param mixed             $id     The context identifier (user ID, team ID, etc.)
     * @param string            $type   The context type for polymorphic storage (e.g., 'App\Models\User')
     * @param null|FeatureScope $scope  Optional feature scope for scoped feature resolution
     * @param mixed             $source Optional original source object (e.g., User model) for drivers needing it
     */
    public function __construct(
        public mixed $id,
        public string $type,
        public ?FeatureScope $scope = null,
        public mixed $source = null,
    ) {
        $this->kind = $this->scope instanceof FeatureScope
            ? $this->scope->kind
            : mb_strtolower(class_basename($this->type));
    }

    /**
     * Create a context without feature scope.
     *
     * Factory method for simple contexts that don't participate in scoped
     * feature resolution.
     *
     * @param  mixed  $id   The context identifier
     * @param  string $type The context type
     * @return self   A new TogglContext without scope
     */
    public static function simple(mixed $id, string $type): self
    {
        return new self($id, $type);
    }

    /**
     * Create a context with feature scope.
     *
     * Factory method for contexts that participate in scoped feature
     * resolution (e.g., users in multi-tenant organizations).
     *
     * @param  mixed        $id    The context identifier
     * @param  string       $type  The context type
     * @param  FeatureScope $scope The feature scope constraints
     * @return self         A new TogglContext with scope
     */
    public static function withScope(mixed $id, string $type, FeatureScope $scope): self
    {
        return new self($id, $type, $scope);
    }

    /**
     * Check if this context has feature scope defined.
     *
     * @return bool True if feature scope is available for scoped resolution
     */
    public function hasScope(): bool
    {
        return $this->scope instanceof FeatureScope;
    }

    /**
     * Create a new context with a different feature scope.
     *
     * Returns a new immutable instance with the specified feature scope,
     * preserving the original id and type. The kind is re-derived from the
     * new feature scope.
     *
     * @param  null|FeatureScope $scope The new feature scope (or null to remove)
     * @return self              A new TogglContext with updated scope
     */
    public function withFeatureScope(?FeatureScope $scope): self
    {
        return new self($this->id, $this->type, $scope);
    }

    /**
     * Generate a cache key for this context.
     *
     * Creates a deterministic string representation suitable for caching.
     * Includes feature scope in the key when present to ensure proper
     * cache isolation for scoped lookups.
     *
     * @return string A unique cache key for this context
     */
    public function toCacheKey(): string
    {
        $id = $this->id ?? '__null__';
        $idString = is_scalar($id) || $id instanceof Stringable ? (string) $id : serialize($id);
        $key = sprintf('%s:%s', $this->type, $idString);

        if ($this->scope instanceof FeatureScope) {
            $key .= '|'.$this->scope->toCacheKey();
        }

        return $key;
    }

    /**
     * Convert the context to an array representation.
     *
     * @return array{id: mixed, type: string, scope: null|array{kind: string, scopes: array<string, mixed>}}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'scope' => $this->scope?->toArray(),
        ];
    }

    /**
     * Serialize this context to a string for feature storage and caching.
     *
     * Produces a format compatible with existing serialization (type|id)
     * to ensure consistency across the application.
     *
     * @return string The serialized context identifier
     */
    public function serialize(): string
    {
        $id = is_scalar($this->id) ? (string) $this->id : serialize($this->id);

        return $this->type.'|'.$id;
    }
}
