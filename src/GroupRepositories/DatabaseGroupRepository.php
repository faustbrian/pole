<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\GroupRepositories;

use Cline\Toggl\Contracts\GroupRepository;
use Cline\Toggl\Database\FeatureGroup;
use Cline\Toggl\Exceptions\NonExistentFeatureGroupException;
use Cline\Toggl\Exceptions\UndefinedFeatureGroupException;
use Cline\Toggl\QueryBuilder;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

use const JSON_THROW_ON_ERROR;

use function array_diff;
use function array_merge;
use function array_unique;
use function array_values;
use function assert;
use function is_string;
use function json_encode;
use function now;
use function sprintf;
use function throw_unless;

/**
 * Database-backed group repository for persistent feature group storage.
 *
 * Stores feature groups in the database for persistence across requests, enabling
 * dynamic group management without code deployments. Groups are stored with
 * JSON-encoded features and metadata in the feature_groups table.
 *
 * Ideal for production applications requiring runtime group modifications through
 * admin interfaces or API endpoints.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
#[Singleton()]
final readonly class DatabaseGroupRepository implements GroupRepository
{
    /**
     * Define or update a feature group.
     *
     * Creates or updates a group atomically using updateOrCreate. Features and
     * metadata are stored as JSON for flexibility.
     *
     * @param string               $name     Unique group identifier
     * @param array<int, string>   $features Array of feature flag names to include
     * @param array<string, mixed> $metadata Optional metadata for custom business logic or documentation
     */
    public function define(string $name, array $features, array $metadata = []): void
    {
        $this->newQuery()->updateOrCreate(
            ['name' => $name],
            [
                'features' => $features,
                'metadata' => $metadata,
            ],
        );
    }

    /**
     * Retrieve all features in a specific group.
     *
     * Decodes features from JSON. Returns empty array if JSON is invalid or null.
     *
     * @param string $name Group name to retrieve
     *
     * @throws UndefinedFeatureGroupException When the group does not exist
     *
     * @return array<int, string> Array of feature flag names in the group
     */
    public function get(string $name): array
    {
        $group = $this->newQuery()
            ->where('name', $name)
            ->first();

        throw_unless(
            $group instanceof Model,
            UndefinedFeatureGroupException::forName($name),
        );

        /** @var array<int, string> */
        return $group->features ?? [];
    }

    /**
     * Retrieve all defined groups with their features.
     *
     * Returns group names mapped to feature lists, excluding metadata.
     * Features are decoded from JSON.
     *
     * @return array<string, array<int, string>> Associative array mapping group names to feature lists
     */
    public function all(): array
    {
        /** @var array<string, array<int, string>> $result */
        $result = $this->newQuery()
            ->get()
            ->mapWithKeys(function (Model $group): array {
                /** @var string $name */
                $name = $group->name ?? '';

                /** @var array<int, string> $features */
                $features = $group->features ?? [];

                return [$name => $features];
            })
            ->all();

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
        return $this->newQuery()
            ->where('name', $name)
            ->exists();
    }

    /**
     * Delete a group definition.
     *
     * Permanently removes the group and all associated data. Idempotent - does
     * not throw if the group doesn't exist.
     *
     * @param string $name Group name to delete
     */
    public function delete(string $name): void
    {
        $this->newQuery()
            ->where('name', $name)
            ->delete();
    }

    /**
     * Replace all features in an existing group.
     *
     * Completely replaces the feature list while preserving metadata. Updates
     * the updated_at timestamp. For incremental updates, use addFeatures() or
     * removeFeatures().
     *
     * @param string             $name     Group name to update
     * @param array<int, string> $features New complete list of feature flag names
     *
     * @throws NonExistentFeatureGroupException When the group does not exist
     */
    public function update(string $name, array $features): void
    {
        $affected = $this->newQuery()
            ->where('name', $name)
            ->update([
                'features' => json_encode($features, flags: JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        throw_unless(
            $affected > 0,
            NonExistentFeatureGroupException::doesNotExist($name),
        );
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
     * @throws UndefinedFeatureGroupException When the group does not exist
     */
    public function addFeatures(string $name, array $features): void
    {
        $current = $this->get($name);

        $updated = array_values(
            array_unique(
                array_merge($current, $features),
            ),
        );

        $this->update($name, $updated);
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
     * @throws UndefinedFeatureGroupException When the group does not exist
     */
    public function removeFeatures(string $name, array $features): void
    {
        $current = $this->get($name);

        $updated = array_values(
            array_diff($current, $features),
        );

        $this->update($name, $updated);
    }

    /**
     * Create a new query builder for the FeatureGroup model.
     *
     * Configures the connection based on toggl.default and toggl.stores configuration.
     *
     * @return Builder<FeatureGroup> Eloquent query builder instance
     */
    private function newQuery(): Builder
    {
        $query = QueryBuilder::featureGroup();

        $defaultStore = Config::get('toggl.default', 'database');
        assert(is_string($defaultStore));
        $connection = Config::get(sprintf('toggl.stores.%s.connection', $defaultStore));

        if ($connection !== null) {
            assert(is_string($connection));
            $query->getModel()->setConnection($connection);
        }

        return $query;
    }
}
