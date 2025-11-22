<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Contracts;

/**
 * Contract for objects that provide custom serialization as feature contexts.
 *
 * When objects are used as feature flag contexts, they need a string representation
 * for storage keys and cache identifiers. This contract allows domain objects to
 * control their serialization rather than relying on default serialization methods.
 *
 * Implementing this contract ensures:
 * - Consistent context identifiers across requests and processes
 * - Predictable cache key generation for performance optimization
 * - Explicit control over what identifies a context in storage
 * - Compatibility with various storage backends (database, cache, etc.)
 *
 * The serialized format should be deterministic, unique, and stable across the
 * object's lifetime. Common patterns include prefixed identifiers like "user:123"
 * or "team:abc-def" to prevent collisions between different entity types.
 *
 * ```php
 * class User implements Serializable
 * {
 *     public function __construct(public int $id) {}
 *
 *     public function serialize(): string
 *     {
 *         return "user:{$this->id}";
 *     }
 * }
 *
 * $user = new User(123);
 * $driver->set('new-ui', $user, true);
 * // Stored with context key: "user:123"
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Serializable
{
    /**
     * Serialize this object into a unique context identifier string.
     *
     * Returns a string representation used as the context key in storage and
     * cache operations. This value must be deterministic (same object always
     * produces same string) and unique within the context type to prevent
     * collisions and ensure correct feature evaluation.
     *
     * Best practices:
     * - Include a type prefix to avoid collisions ("user:", "team:", etc.)
     * - Use immutable identifiers (primary keys, UUIDs) not mutable properties
     * - Keep the string reasonably short for storage efficiency
     * - Ensure the format is URL-safe if features may be exposed via APIs
     *
     * @return string Unique, deterministic context identifier for storage and caching
     */
    public function serialize(): string;
}
