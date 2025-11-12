<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole\Drivers;

use Cline\Pole\Contracts\CanListStoredFeatures;
use Cline\Pole\Contracts\Driver;
use Cline\Pole\Events\UnknownFeatureResolved;
use Cline\Pole\Feature;
use DateTimeInterface;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use stdClass;

use const JSON_OBJECT_AS_ARRAY;
use const JSON_THROW_ON_ERROR;

use function array_key_exists;
use function array_keys;
use function array_map;
use function assert;
use function is_callable;
use function is_string;
use function json_decode;
use function json_encode;
use function property_exists;
use function sprintf;
use function with;

/**
 * Database-backed feature flag driver with persistence.
 *
 * Stores feature flags in a database table, providing persistence across requests
 * and enabling centralized feature management. Supports expiration dates and
 * optimized bulk operations. Handles race conditions with retry logic.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class DatabaseDriver implements CanListStoredFeatures, Driver
{
    /**
     * The name of the "created at" column.
     */
    public const string CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     */
    public const string UPDATED_AT = 'updated_at';

    /**
     * The current retry depth for retrieving values from the database.
     *
     * Used to prevent infinite retry loops when handling unique constraint violations.
     */
    private int $retryDepth = 0;

    /**
     * The sentinel value for unknown features.
     *
     * Used to distinguish between features that resolve to false/null
     * and features that haven't been defined at all.
     */
    private readonly stdClass $unknownFeatureValue;

    /**
     * Create a new driver instance.
     *
     * @param DatabaseManager                                $db                    The database manager
     * @param Dispatcher                                     $events                The event dispatcher
     * @param Repository                                     $config                The config repository
     * @param string                                         $name                  The driver name
     * @param array<string, (callable(mixed $scope): mixed)> $featureStateResolvers The feature resolvers
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly Repository $config,
        private readonly string $name,
        /**
         * The feature state resolvers.
         *
         * @var array<string, (callable(mixed): mixed)>
         */
        private array $featureStateResolvers,
    ) {
        $this->unknownFeatureValue = new stdClass();
    }

    /**
     * Define an initial feature flag state resolver.
     *
     * @param  string                                $feature  The feature name
     * @param  (callable(mixed $scope): mixed)|mixed $resolver The resolver callback or static value
     * @return mixed                                 Always returns null for this driver
     */
    public function define(string $feature, mixed $resolver = null): mixed
    {
        $this->featureStateResolvers[$feature] = is_callable($resolver)
            ? $resolver
            : fn (): mixed => $resolver;

        return null;
    }

    /**
     * Retrieve the names of all defined features.
     *
     * @return array<string> The list of defined feature names
     */
    public function defined(): array
    {
        return array_keys($this->featureStateResolvers);
    }

    /**
     * Retrieve the names of all stored features.
     *
     * Returns features that have been persisted to the database.
     *
     * @return array<string> The list of stored feature names
     */
    public function stored(): array
    {
        /** @var array<string> */
        return $this->newQuery()
            ->select('name')
            ->distinct()
            ->get()
            ->pluck('name')
            ->all();
    }

    /**
     * Get multiple feature flag values.
     *
     * Optimized to fetch all requested features in a single database query,
     * then resolves any missing values and inserts them in bulk.
     *
     * @param  array<string, array<int, mixed>> $features Map of feature names to their scopes
     * @return array<string, array<int, mixed>> Map of feature names to their resolved values
     */
    public function getAll(array $features): array
    {
        $query = $this->newQuery();

        $resolved = Collection::make($features)
            ->map(fn ($scopes, $feature) => Collection::make($scopes)
                ->each(function ($scope) use ($query, $feature): void {
                    $query->orWhere(function ($q) use ($feature, $scope): void {
                        /** @var Builder $q */
                        $q->where('name', $feature)->where('scope', Feature::serializeScope($scope));
                    });
                }));

        $records = $query->get();

        /** @var Collection<int, array{name: string, scope: mixed, value: mixed}> */
        $inserts = new Collection();

        $results = $resolved->map(fn ($scopes, $feature) => $scopes->map(function ($scope) use ($feature, $records, $inserts) {
            $filtered = $records->where('name', $feature)->where('scope', Feature::serializeScope($scope));

            if ($filtered->isNotEmpty()) {
                $first = $filtered->first();
                assert(property_exists($first, 'value') && is_string($first->value));

                return json_decode($first->value, flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);
            }

            $value = $this->resolveValue($feature, $scope);

            if ($value === $this->unknownFeatureValue) {
                return false;
            }

            $inserts[] = [
                'name' => $feature,
                'scope' => $scope,
                'value' => $value,
            ];

            return $value;
        })->all())->all();

        if ($inserts->isNotEmpty()) {
            try {
                $this->insertMany($inserts->all());
            } catch (UniqueConstraintViolationException $e) {
                if ($this->retryDepth === 2) {
                    throw new RuntimeException('Unable to insert feature values into the database.', $e->getCode(), previous: $e);
                }

                ++$this->retryDepth;

                return $this->getAll($features);
            } finally {
                $this->retryDepth = 0;
            }
        }

        return $results;
    }

    /**
     * Retrieve a feature flag's value.
     *
     * Checks the database first, then resolves using the feature's resolver if needed.
     * Automatically handles expired features by deleting them and returning false.
     *
     * @param string $feature The feature name
     * @param mixed  $scope   The scope to check
     *
     * @throws RuntimeException If unable to insert after retries
     *
     * @return mixed The feature's value for the given scope
     */
    public function get(string $feature, mixed $scope): mixed
    {
        if (($record = $this->retrieve($feature, $scope)) !== null) {
            // Check if feature has expired
            if (property_exists($record, 'expires_at') && $record->expires_at !== null) {
                $expiresAt = $record->expires_at;
                assert(is_string($expiresAt) || $expiresAt instanceof DateTimeInterface);

                if (Date::parse($expiresAt)->isPast()) {
                    $this->delete($feature, $scope);

                    return false;
                }
            }

            assert(property_exists($record, 'value'));
            assert(is_string($record->value));

            return json_decode($record->value, flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);
        }

        // Resolve and persist the value
        return with($this->resolveValue($feature, $scope), function ($value) use ($feature, $scope) {
            if ($value === $this->unknownFeatureValue) {
                return false;
            }

            try {
                $this->insert($feature, $scope, $value);
            } catch (UniqueConstraintViolationException $uniqueConstraintViolationException) {
                // Handle race condition where another process inserted the same feature
                if ($this->retryDepth === 1) {
                    throw new RuntimeException('Unable to insert feature value into the database.', $uniqueConstraintViolationException->getCode(), previous: $uniqueConstraintViolationException);
                }

                ++$this->retryDepth;

                return $this->get($feature, $scope);
            } finally {
                $this->retryDepth = 0;
            }

            return $value;
        });
    }

    /**
     * Set a feature flag's value.
     *
     * Uses upsert to insert or update the feature value atomically.
     *
     * @param string $feature The feature name
     * @param mixed  $scope   The scope to update
     * @param mixed  $value   The new value to set
     */
    public function set(string $feature, mixed $scope, mixed $value): void
    {
        $this->newQuery()->upsert([
            'name' => $feature,
            'scope' => Feature::serializeScope($scope),
            'value' => json_encode($value, flags: JSON_THROW_ON_ERROR),
            self::CREATED_AT => $now = Date::now(),
            self::UPDATED_AT => $now,
        ], uniqueBy: ['name', 'scope'], update: ['value', self::UPDATED_AT]);
    }

    /**
     * Set a feature flag's value for all scopes.
     *
     * Updates all existing database records for the given feature.
     *
     * @param string $feature The feature name
     * @param mixed  $value   The new value to set
     */
    public function setForAllScopes(string $feature, mixed $value): void
    {
        $this->newQuery()
            ->where('name', $feature)
            ->update([
                'value' => json_encode($value, flags: JSON_THROW_ON_ERROR),
                self::UPDATED_AT => Date::now(),
            ]);
    }

    /**
     * Delete a feature flag's value.
     *
     * @param string $feature The feature name
     * @param mixed  $scope   The scope to delete
     */
    public function delete(string $feature, mixed $scope): void
    {
        $this->newQuery()
            ->where('name', $feature)
            ->where('scope', Feature::serializeScope($scope))
            ->delete();
    }

    /**
     * Purge the given feature from storage.
     *
     * @param null|array<string> $features The feature names to purge, or null to purge all
     */
    public function purge(?array $features): void
    {
        if ($features === null) {
            $this->newQuery()->delete();
        } else {
            $this->newQuery()
                ->whereIn('name', $features)
                ->delete();
        }
    }

    /**
     * Retrieve the value for the given feature and scope from storage.
     *
     * @param  string      $feature The feature name
     * @param  mixed       $scope   The scope to retrieve
     * @return null|object The database record, or null if not found
     */
    private function retrieve(string $feature, mixed $scope): ?object
    {
        return $this->newQuery()
            ->where('name', $feature)
            ->where('scope', Feature::serializeScope($scope))
            ->first();
    }

    /**
     * Determine the initial value for a given feature and scope.
     *
     * Calls the feature's resolver or dispatches an UnknownFeatureResolved event.
     *
     * @param  string $feature The feature name
     * @param  mixed  $scope   The scope to evaluate
     * @return mixed  The resolved value or the unknown feature sentinel
     */
    private function resolveValue(string $feature, mixed $scope): mixed
    {
        if (!array_key_exists($feature, $this->featureStateResolvers)) {
            $this->events->dispatch(
                new UnknownFeatureResolved($feature, $scope),
            );

            return $this->unknownFeatureValue;
        }

        return $this->featureStateResolvers[$feature]($scope);
    }

    /**
     * Insert the value for the given feature and scope into storage.
     *
     * @param  string $feature The feature name
     * @param  mixed  $scope   The scope to insert
     * @param  mixed  $value   The value to insert
     * @return bool   True if the insert succeeded
     */
    private function insert(string $feature, mixed $scope, mixed $value): bool
    {
        return $this->insertMany([[
            'name' => $feature,
            'scope' => $scope,
            'value' => $value,
        ]]);
    }

    /**
     * Insert the given feature values into storage.
     *
     * Bulk insert operation for multiple feature values.
     *
     * @param  array<int, array{name: string, scope: mixed, value: mixed}> $inserts The values to insert
     * @return bool                                                        True if the insert succeeded
     */
    private function insertMany(array $inserts): bool
    {
        $now = Date::now();

        return $this->newQuery()->insert(array_map(fn (array $insert): array => [
            'name' => $insert['name'],
            'scope' => Feature::serializeScope($insert['scope']),
            'value' => json_encode($insert['value'], flags: JSON_THROW_ON_ERROR),
            self::CREATED_AT => $now,
            self::UPDATED_AT => $now,
        ], $inserts));
    }

    /**
     * Create a new table query.
     *
     * @return Builder The query builder instance
     */
    private function newQuery()
    {
        $table = $this->config->get(sprintf('pole.stores.%s.table', $this->name));
        assert(is_string($table) || $table === null);

        return $this->connection()->table($table ?? 'features');
    }

    /**
     * The database connection.
     *
     * @return Connection The database connection instance
     */
    private function connection(): Connection
    {
        $connection = $this->config->get(sprintf('pole.stores.%s.connection', $this->name));
        assert(is_string($connection) || $connection === null);

        return $this->db->connection($connection);
    }
}
