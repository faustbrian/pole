<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\GroupRepositories;

use Cline\Toggl\Contracts\GroupRepository;
use Cline\Toggl\Exceptions\FeatureGroupNotFoundException;

use function array_diff;
use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function throw_unless;

/**
 * In-memory array-based group repository for testing and simple applications.
 *
 * Stores feature groups in memory for the duration of the request lifecycle.
 * All data is lost when the request completes. Ideal for unit testing, prototyping,
 * or applications where groups are statically defined in configuration files.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ArrayGroupRepository implements GroupRepository
{
    /**
     * Group definitions keyed by group name.
     *
     * @var array<string, array{features: array<int, string>, metadata: array<string, mixed>}>
     */
    private array $groups = [];

    /**
     * Define or update a feature group.
     *
     * Creates a new group or overwrites an existing one if the name already exists.
     *
     * @param string               $name     Unique group identifier
     * @param array<int, string>   $features Array of feature flag names to include
     * @param array<string, mixed> $metadata Optional metadata for custom business logic or documentation
     */
    public function define(string $name, array $features, array $metadata = []): void
    {
        $this->groups[$name] = [
            'features' => $features,
            'metadata' => $metadata,
        ];
    }

    /**
     * Retrieve all features in a specific group.
     *
     * @param string $name Group name to retrieve
     *
     * @throws FeatureGroupNotFoundException When the group does not exist
     *
     * @return array<int, string> Array of feature flag names in the group
     */
    public function get(string $name): array
    {
        throw_unless(
            array_key_exists($name, $this->groups),
            FeatureGroupNotFoundException::forName($name),
        );

        return array_values($this->groups[$name]['features']);
    }

    /**
     * Retrieve all defined groups with their features.
     *
     * Returns group names mapped to feature lists, excluding metadata.
     *
     * @return array<string, array<int, string>> Associative array mapping group names to feature lists
     */
    public function all(): array
    {
        $result = [];

        foreach ($this->groups as $name => $data) {
            $result[$name] = array_values($data['features']);
        }

        return $result;
    }

    /**
     * Check if a group is defined.
     *
     * @param  string $name Group name to check
     * @return bool   True if the group exists, false otherwise
     */
    public function exists(string $name): bool
    {
        return array_key_exists($name, $this->groups);
    }

    /**
     * Delete a group definition.
     *
     * Removes the group and all associated data. Idempotent - does not throw if
     * the group doesn't exist.
     *
     * @param string $name Group name to delete
     */
    public function delete(string $name): void
    {
        unset($this->groups[$name]);
    }

    /**
     * Replace all features in an existing group.
     *
     * Completely replaces the feature list. Metadata is preserved.
     * For incremental updates, use addFeatures() or removeFeatures().
     *
     * @param string             $name     Group name to update
     * @param array<int, string> $features New complete list of feature flag names
     *
     * @throws FeatureGroupNotFoundException When the group does not exist
     */
    public function update(string $name, array $features): void
    {
        throw_unless(
            $this->exists($name),
            FeatureGroupNotFoundException::doesNotExist($name),
        );

        $this->groups[$name]['features'] = $features;
    }

    /**
     * Add features to an existing group.
     *
     * Appends features to the group's existing feature list. Duplicates are
     * automatically removed and array keys are re-indexed.
     *
     * @param string             $name     Group name to modify
     * @param array<int, string> $features Feature flag names to add
     *
     * @throws FeatureGroupNotFoundException When the group does not exist
     */
    public function addFeatures(string $name, array $features): void
    {
        throw_unless(
            $this->exists($name),
            FeatureGroupNotFoundException::doesNotExist($name),
        );

        $this->groups[$name]['features'] = array_values(
            array_unique(
                array_merge($this->groups[$name]['features'], $features),
            ),
        );
    }

    /**
     * Remove features from an existing group.
     *
     * Removes features from the group's feature list. Array keys are re-indexed
     * after removal. Idempotent - non-existent features are silently ignored.
     *
     * @param string             $name     Group name to modify
     * @param array<int, string> $features Feature flag names to remove
     *
     * @throws FeatureGroupNotFoundException When the group does not exist
     */
    public function removeFeatures(string $name, array $features): void
    {
        throw_unless(
            $this->exists($name),
            FeatureGroupNotFoundException::doesNotExist($name),
        );

        $this->groups[$name]['features'] = array_values(
            array_diff($this->groups[$name]['features'], $features),
        );
    }
}
