<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl;

use Cline\Toggl\Contracts\GroupRepository;

use function array_values;

/**
 * Fluent builder for creating feature groups with incremental feature accumulation.
 *
 * Provides a chainable interface for defining groups with features and metadata,
 * similar to Warden's conductor pattern for abilities. Supports building complex
 * groups incrementally by chaining multiple with() and meta() calls before persisting.
 *
 * ```php
 * Toggl::groups()
 *     ->create('premium-tier')
 *     ->with('advanced-analytics', 'priority-support')
 *     ->with('custom-branding')
 *     ->meta(['description' => 'Premium features', 'tier' => 1])
 *     ->save();
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class GroupBuilder
{
    /**
     * Accumulated list of feature names for this group.
     *
     * @var array<int, string>
     */
    private array $features = [];

    /**
     * Metadata key-value pairs for this group.
     *
     * @var array<string, mixed>
     */
    private array $metadata = [];

    /**
     * Create a new group builder instance.
     *
     * @param GroupRepository $repository The underlying repository implementation that will persist
     *                                    the group definition when save() is called. Can be array-based
     *                                    (in-memory) or database-backed depending on configuration.
     * @param string          $name       The unique identifier for this group. Used to reference the group
     *                                    when activating, deactivating, or checking group membership.
     */
    public function __construct(
        private readonly GroupRepository $repository,
        private readonly string $name,
    ) {}

    /**
     * Add features to the group incrementally.
     *
     * Appends features to the group's feature list, allowing multiple calls
     * for incremental building. Automatically removes duplicates and re-indexes.
     *
     * ```php
     * Toggl::groups()
     *     ->create('premium-features')
     *     ->with('advanced-analytics', 'priority-support')
     *     ->with('custom-branding')
     *     ->save();
     * ```
     *
     * @param  string ...$features One or more feature flag names to add
     * @return static Fluent interface for method chaining
     */
    public function with(string ...$features): self
    {
        $this->features = array_values([...$this->features, ...$features]);

        return $this;
    }

    /**
     * Add metadata to the group.
     *
     * Merges metadata with any existing metadata, allowing incremental additions.
     * Useful for attaching descriptive information, ownership, or custom attributes.
     *
     * ```php
     * Toggl::groups()
     *     ->create('experimental')
     *     ->with('new-ui', 'beta-api')
     *     ->meta(['description' => 'Experimental features', 'owner' => 'product-team'])
     *     ->save();
     * ```
     *
     * @param  array<string, mixed> $metadata Key-value pairs to merge into group metadata
     * @return static               Fluent interface for method chaining
     */
    public function meta(array $metadata): self
    {
        $this->metadata = [...$this->metadata, ...$metadata];

        return $this;
    }

    /**
     * Persist the group definition to the repository.
     *
     * Saves all accumulated features and metadata to the underlying repository
     * (array-based or database-backed). After saving, the group is available
     * for membership operations and feature activation.
     */
    public function save(): void
    {
        $this->repository->define($this->name, $this->features, $this->metadata);
    }
}
