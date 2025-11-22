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

use function is_array;

/**
 * Conductor for managing feature metadata.
 *
 * Provides fluent API for attaching, updating, and removing metadata from features.
 * Supports full replacement, merging, selective key removal, and complete clearing
 * of feature metadata on a per-context basis.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class MetadataConductor
{
    /**
     * Create a new metadata conductor instance.
     *
     * @param FeatureManager            $manager  The feature manager instance for managing feature state
     *                                            across different contexts and handling metadata operations
     * @param BackedEnum|string         $feature  Feature name whose metadata will be managed. Can be a string
     *                                            identifier or backed enum value for type-safe references
     * @param null|array<string, mixed> $metadata Metadata to set, replacing existing metadata completely. When set,
     *                                            this operation takes precedence over merge/forget/clear operations
     * @param null|array<string, mixed> $merge    Metadata to merge with existing values. Preserves existing keys not
     *                                            present in merge array. Used when metadata is not set
     * @param null|array<int, string>   $forget   Keys to remove from existing metadata. Selectively deletes specified
     *                                            keys while preserving others. Used when metadata and merge are null
     * @param bool                      $clear    Clear all metadata when true. Removes all metadata for the feature.
     *                                            Used when metadata, merge, and forget are all null
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum $feature,
        private ?array $metadata = null,
        private ?array $merge = null,
        private ?array $forget = null,
        private bool $clear = false,
    ) {}

    /**
     * Set metadata (replaces existing).
     *
     * Returns a new conductor instance configured to replace all existing metadata
     * with the provided values. This operation is mutually exclusive with merge(),
     * forget(), and clear().
     *
     * @param  array<string, mixed> $metadata Metadata to set. Completely replaces existing metadata
     *                                        with these key-value pairs
     * @return self                 New conductor instance with metadata replacement configured
     */
    public function with(array $metadata): self
    {
        return new self($this->manager, $this->feature, $metadata, $this->merge, $this->forget, $this->clear);
    }

    /**
     * Merge metadata with existing.
     *
     * Returns a new conductor instance configured to merge the provided metadata
     * with existing values. Existing keys not present in the merge array are preserved.
     * Mutually exclusive with with(), forget(), and clear().
     *
     * @param  array<string, mixed> $metadata Metadata to merge. New keys are added, existing keys
     *                                        are updated, unspecified keys remain unchanged
     * @return self                 New conductor instance with metadata merge configured
     */
    public function merge(array $metadata): self
    {
        return new self($this->manager, $this->feature, $this->metadata, $metadata, $this->forget, $this->clear);
    }

    /**
     * Forget specific metadata keys.
     *
     * Returns a new conductor instance configured to remove specific keys from
     * the feature's metadata. All other keys remain unchanged. Mutually exclusive
     * with with(), merge(), and clear().
     *
     * @param  array<int, string> $keys Keys to remove from metadata. Keys not in this
     *                                  list will be preserved
     * @return self               New conductor instance with key removal configured
     */
    public function forget(array $keys): self
    {
        return new self($this->manager, $this->feature, $this->metadata, $this->merge, $keys, $this->clear);
    }

    /**
     * Clear all metadata.
     *
     * Returns a new conductor instance configured to remove all metadata from
     * the feature. This is equivalent to setting an empty array. Mutually exclusive
     * with with(), merge(), and forget().
     *
     * @return self New conductor instance with metadata clearing configured
     */
    public function clear(): self
    {
        return new self($this->manager, $this->feature, $this->metadata, $this->merge, $this->forget, true);
    }

    /**
     * Apply metadata changes for specific context (terminal method).
     *
     * Executes the configured metadata operation (with/merge/forget/clear) for the
     * specified context. The operation precedence is: with() > merge() > forget() > clear().
     *
     * @param mixed $context The context to apply changes to. Can be any context type supported
     *                       by the feature manager (user, team, organization, etc.)
     */
    public function for(mixed $context): void
    {
        $contextdDriver = $this->manager->for($context);

        if ($this->clear) {
            // Clear all metadata
            $contextdDriver->activate($this->feature, []);
        } elseif ($this->metadata !== null) {
            // Replace metadata
            $contextdDriver->activate($this->feature, $this->metadata);
        } elseif ($this->merge !== null) {
            // Merge with existing metadata
            $existing = $contextdDriver->value($this->feature);
            $existing = is_array($existing) ? $existing : [];
            $contextdDriver->activate($this->feature, [...$existing, ...$this->merge]);
        } elseif ($this->forget !== null) {
            // Remove specific keys
            $existing = $contextdDriver->value($this->feature);

            /** @var array<string, mixed> $existing */
            $existing = is_array($existing) ? $existing : [];

            foreach ($this->forget as $key) {
                unset($existing[$key]);
            }

            $contextdDriver->activate($this->feature, $existing);
        }
    }

    /**
     * Get the feature name.
     *
     * Returns the feature identifier whose metadata is being managed.
     *
     * @return BackedEnum|string The feature identifier
     */
    public function feature(): string|BackedEnum
    {
        return $this->feature;
    }

    /**
     * Get the metadata to set.
     *
     * Returns the metadata array that will completely replace existing metadata,
     * or null if with() has not been called.
     *
     * @return null|array<string, mixed> Replacement metadata, or null if not configured
     */
    public function metadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * Get the metadata to merge.
     *
     * Returns the metadata array that will be merged with existing metadata,
     * or null if merge() has not been called.
     *
     * @return null|array<string, mixed> Metadata to merge, or null if not configured
     */
    public function mergeData(): ?array
    {
        return $this->merge;
    }

    /**
     * Get the keys to forget.
     *
     * Returns the array of metadata keys that will be removed,
     * or null if forget() has not been called.
     *
     * @return null|array<int, string> Keys to remove, or null if not configured
     */
    public function forgetKeys(): ?array
    {
        return $this->forget;
    }

    /**
     * Check if clearing all metadata.
     *
     * Returns true if clear() has been called, indicating all metadata
     * should be removed from the feature.
     *
     * @return bool True if metadata should be cleared, false otherwise
     */
    public function isClear(): bool
    {
        return $this->clear;
    }
}
