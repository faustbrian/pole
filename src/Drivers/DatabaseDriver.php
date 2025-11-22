<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Drivers;

use Cline\Toggl\Contracts\CanListStoredFeatures;
use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Database\Feature;
use Cline\Toggl\Events\UnknownFeatureResolved;
use Cline\Toggl\Exceptions\InvalidContextException;
use Cline\Toggl\QueryBuilder;
use Cline\Toggl\Support\FeatureScope;
use Cline\Toggl\Support\PrimaryKeyGenerator;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

use const JSON_OBJECT_AS_ARRAY;
use const JSON_THROW_ON_ERROR;

use function array_combine;
use function array_key_exists;
use function array_keys;
use function array_map;
use function assert;
use function config;
use function is_callable;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

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
     *
     * Used when manually setting timestamps during bulk inserts and updates.
     */
    public const string CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     *
     * Used when manually setting timestamps during bulk inserts and updates.
     */
    public const string UPDATED_AT = 'updated_at';

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
     * @param Dispatcher                                              $events                Laravel event dispatcher instance used to fire UnknownFeatureResolved
     *                                                                                       events when undefined features are accessed, enabling monitoring
     *                                                                                       and logging of feature flag usage patterns
     * @param string                                                  $name                  The driver name used to retrieve connection and table configuration
     *                                                                                       from the toggl.stores config array for this specific driver instance,
     *                                                                                       enabling multiple database-backed feature stores
     * @param array<string, (callable(TogglContext $context): mixed)> $featureStateResolvers Map of feature names to their resolver callbacks or static values.
     *                                                                                       Resolvers accept a context parameter and return the feature's value for that context.
     *                                                                                       Static values are automatically wrapped in closures during definition,
     *                                                                                       providing a consistent callable interface for all feature resolution
     */
    public function __construct(
        private readonly Dispatcher $events,
        private readonly string $name,
        private array $featureStateResolvers,
    ) {
        $this->unknownFeatureValue = new stdClass();
    }

    /**
     * Define an initial feature flag state resolver.
     *
     * @param  string                                         $feature  The feature name
     * @param  (callable(TogglContext $context): mixed)|mixed $resolver The resolver callback or static value
     * @return mixed                                          Always returns null
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
     * @param  array<string, array<int, TogglContext>> $features Map of feature names to their contexts
     * @return array<string, array<int, mixed>>        Map of feature names to their resolved values
     */
    public function getAll(array $features): array
    {
        $query = $this->newQuery();

        // Build query with OR conditions for each feature+context pair
        $resolved = [];

        foreach ($features as $feature => $contexts) {
            $contextCollection = [];

            foreach ($contexts as $context) {
                $contextCollection[] = $context;

                $query->orWhere(function ($q) use ($feature, $context): void {
                    /** @var Builder<Feature> $q */
                    $this->whereContextMatches($q->where('name', $feature), $context);
                });
            }

            $resolved[$feature] = $contextCollection;
        }

        $records = $query->get();

        /** @var array<int, array{name: string, context: TogglContext, value: mixed}> */
        $inserts = [];

        $results = [];

        foreach ($resolved as $feature => $contexts) {
            $results[$feature] = [];

            foreach ($contexts as $context) {
                [$contextType, $contextId] = $this->extractContextMorph($context);
                $filtered = $records->where('name', $feature)->where('context_type', $contextType)->where('context_id', $contextId);

                if ($filtered->isNotEmpty()) {
                    /** @var Feature $first */
                    $first = $filtered->first();

                    $results[$feature][] = json_decode($first->value, flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);
                } else {
                    $value = $this->resolveValue($feature, $context);

                    if ($value === $this->unknownFeatureValue) {
                        $results[$feature][] = false;
                    } else {
                        $inserts[] = [
                            'name' => $feature,
                            'context' => $context,
                            'value' => $value,
                        ];

                        $results[$feature][] = $value;
                    }
                }
            }
        }

        if ($inserts !== []) {
            try {
                // Use savepoint so PostgreSQL can recover from constraint violation
                DB::transaction(fn (): bool => $this->insertMany($inserts));
            } catch (UniqueConstraintViolationException) {
                // Retry once - race condition where another process inserted between check and insert
                return $this->getAll($features);
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
     * @param string       $feature The feature name
     * @param TogglContext $context The context to check
     *
     * @throws RuntimeException If unable to insert after retries
     *
     * @return mixed The feature's value for the given context
     */
    public function get(string $feature, TogglContext $context): mixed
    {
        if (($record = $this->retrieve($feature, $context)) instanceof Feature) {
            // Check if feature has expired
            if ($record->expires_at !== null && Date::now()->greaterThan($record->expires_at)) {
                $this->delete($feature, $context);

                return false;
            }

            return json_decode($record->value, flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);
        }

        // Resolve and persist the value
        $value = $this->resolveValue($feature, $context);

        if ($value === $this->unknownFeatureValue) {
            return false;
        }

        try {
            // Use savepoint so PostgreSQL can recover from constraint violation
            DB::transaction(fn (): bool => $this->insert($feature, $context, $value));
        } catch (UniqueConstraintViolationException) {
            // Retry once - race condition where another process inserted between check and insert
            return $this->get($feature, $context);
        }

        return $value;
    }

    /**
     * Set a feature flag's value.
     *
     * Uses updateOrInsert to insert or update the feature value atomically.
     * If the context has a feature scope, stores the feature with scoped constraints.
     * Scoped features are stored separately and can have different values for different scopes.
     *
     * @param string       $feature The feature name
     * @param TogglContext $context The context to update (may include scope)
     * @param mixed        $value   The new value to set
     */
    public function set(string $feature, TogglContext $context, mixed $value): void
    {
        [$contextType, $contextId] = $this->extractContextMorph($context);
        $scope = $this->extractFeatureScope($context);

        if ($scope instanceof FeatureScope) {
            $now = Date::now();

            // Delete any existing scoped feature with same name, context, and kind
            $this->newQuery()
                ->where('name', $feature)
                ->where('context_type', $contextType)
                ->where('context_id', $contextId)
                ->whereNotNull('scope')
                ->where('scope->kind', $scope->kind)
                ->delete();

            // Insert new scoped feature
            $featureModel = $this->newQuery()->getModel();
            $featureModel->fill([
                'name' => $feature,
                'context_type' => $contextType,
                'context_id' => $contextId,
                'value' => json_encode($value, flags: JSON_THROW_ON_ERROR),
                'scope' => $scope->toArray(),
            ]);
            $featureModel->setAttribute(self::CREATED_AT, $now);
            $featureModel->setAttribute(self::UPDATED_AT, $now);
            $featureModel->save();

            return;
        }

        $this->newQuery()->updateOrCreate(
            [
                'name' => $feature,
                'context_type' => $contextType,
                'context_id' => $contextId,
            ],
            [
                'value' => json_encode($value, flags: JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * Set a feature flag's value for all contexts.
     *
     * Updates all existing database records for the given feature, setting them
     * to the same value regardless of their context. Useful for global feature
     * activation or deactivation across all users/entities.
     *
     * @param string $feature The feature name
     * @param mixed  $value   The new value to set
     */
    public function setForAllContexts(string $feature, mixed $value): void
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
     * If the context has a feature scope, deletes the scoped feature
     * record matching the context and scope constraints. For non-scoped
     * contexts, deletes the feature record for that specific context.
     *
     * @param string       $feature The feature name
     * @param TogglContext $context The context to delete (may include scope)
     */
    public function delete(string $feature, TogglContext $context): void
    {
        $scope = $this->extractFeatureScope($context);

        if ($scope instanceof FeatureScope) {
            [$contextType, $contextId] = $this->extractContextMorph($context);

            $query = $this->newQuery()
                ->where('name', $feature)
                ->where('context_type', $contextType)
                ->where('context_id', $contextId)
                ->whereNotNull('scope')
                ->where('scope->kind', $scope->kind);

            foreach ($scope->definedConstraints() as $key => $value) {
                $query->whereJsonContains('scope->scopes->'.$key, $value);
            }

            $query->delete();

            return;
        }

        $this->whereContextMatches(
            $this->newQuery()->where('name', $feature),
            $context,
        )->delete();
    }

    /**
     * Purge the given features from storage.
     *
     * Removes all database records for the specified features. If no features are
     * specified, deletes all feature records from the database.
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
     * Retrieve a feature flag's value with scoped scope matching.
     *
     * Searches for feature records that match either the exact context or the provided
     * scope constraints. Scoped features use flexible matching where null scope values
     * act as wildcards. Prioritizes exact context matches over scoped matches.
     *
     * @param  string       $feature The feature name
     * @param  TogglContext $context The context to retrieve
     * @param  FeatureScope $scope   The scope scope to match
     * @return null|Feature The database record, or null if not found
     */
    public function retrieveWithScope(string $feature, TogglContext $context, FeatureScope $scope): ?Feature
    {
        $query = $this->newQuery()->where('name', $feature);
        [$contextType, $contextId] = $this->extractContextMorph($context);

        /** @var null|Feature */
        return $query->where(function ($q) use ($contextType, $contextId, $scope): void {
            // Exact context match (non-scoped records only)
            $q->where(function ($exactMatch) use ($contextType, $contextId): void {
                $exactMatch->where('context_type', $contextType)
                    ->where('context_id', $contextId)
                    ->whereNull('scope');
            })
            // OR scope scope match
                ->orWhere(function ($scopeMatch) use ($scope): void {
                    $scopeMatch->whereNotNull('scope')
                        ->where('scope->kind', $scope->kind);

                    foreach ($scope->definedConstraints() as $key => $value) {
                        $scopeMatch->where(function ($scopeQuery) use ($key, $value): void {
                            $scopeQuery->whereNull('scope->scopes->'.$key)
                                ->orWhere('scope->scopes->'.$key, $value);
                        });
                    }
                });
        })
        // Prioritize exact matches over scope matches
            ->orderByRaw('CASE WHEN context_id IS NOT NULL THEN 1 ELSE 2 END')
            ->first();
    }

    /**
     * Retrieve the value for the given feature and context from storage.
     *
     * @param  string       $feature The feature name
     * @param  TogglContext $context The context to retrieve
     * @return null|Feature The database record, or null if not found
     */
    private function retrieve(string $feature, TogglContext $context): ?Feature
    {
        $query = $this->newQuery()->where('name', $feature);

        // Use exact context matching - direct database lookup
        return $this->whereContextMatches($query, $context)->first();
    }

    /**
     * Determine the initial value for a given feature and context.
     *
     * Calls the feature's resolver or dispatches an UnknownFeatureResolved event.
     *
     * @param  string       $feature The feature name
     * @param  TogglContext $context The context to evaluate
     * @return mixed        The resolved value or the unknown feature sentinel
     */
    private function resolveValue(string $feature, TogglContext $context): mixed
    {
        if (!array_key_exists($feature, $this->featureStateResolvers)) {
            if (Config::get('toggl.events.enabled', true)) {
                $this->events->dispatch(
                    new UnknownFeatureResolved($feature, $context),
                );
            }

            return $this->unknownFeatureValue;
        }

        return $this->featureStateResolvers[$feature]($context);
    }

    /**
     * Insert the value for the given feature and context into storage.
     *
     * @param  string       $feature The feature name
     * @param  TogglContext $context The context to insert
     * @param  mixed        $value   The value to insert
     * @return bool         True if the insert succeeded
     */
    private function insert(string $feature, TogglContext $context, mixed $value): bool
    {
        return $this->insertMany([[
            'name' => $feature,
            'context' => $context,
            'value' => $value,
        ]]);
    }

    /**
     * Insert the given feature values into storage.
     *
     * Bulk insert operation for multiple feature values.
     * Pre-generates IDs for ULID/UUID configurations to enable single bulk insert.
     *
     * @param  array<int, array{name: string, context: TogglContext, value: mixed}> $inserts The values to insert
     * @return bool                                                                 True if the insert succeeded
     */
    private function insertMany(array $inserts): bool
    {
        $now = Date::now();
        $primaryKey = PrimaryKeyGenerator::generate();

        $records = array_map(function (array $insert) use ($now, $primaryKey): array {
            $record = [
                'name' => $insert['name'],
                ...array_combine(['context_type', 'context_id'], $this->extractContextMorph($insert['context'])),
                'value' => json_encode($insert['value'], flags: JSON_THROW_ON_ERROR),
                self::CREATED_AT => $now,
                self::UPDATED_AT => $now,
            ];

            // Add pre-generated ID for non-auto-incrementing primary keys
            if (!$primaryKey->isAutoIncrementing()) {
                $record['id'] = PrimaryKeyGenerator::generate()->value;
            }

            return $record;
        }, $inserts);

        return $this->newQuery()->insert($records);
    }

    /**
     * Create a new query builder for the Feature model.
     *
     * @return Builder<Feature> The Eloquent query builder instance
     */
    private function newQuery(): Builder
    {
        $connection = Config::get(sprintf('toggl.stores.%s.connection', $this->name));
        assert(is_string($connection) || $connection === null);

        $query = QueryBuilder::feature();

        if ($connection !== null) {
            $query->getModel()->setConnection($connection);
        }

        return $query;
    }

    /**
     * Extract context type and ID from TogglContext.
     *
     * @param TogglContext $context The context
     *
     * @throws InvalidContextException If context ID is null
     *
     * @return array{string, int|string} [context_type, context_id]
     */
    private function extractContextMorph(TogglContext $context): array
    {
        if ($context->id === null) {
            throw InvalidContextException::mustBeEloquentModelWithId();
        }

        assert(is_int($context->id) || is_string($context->id));

        return [$context->type, $context->id];
    }

    /**
     * Extract scope scope from context if present.
     *
     * @param  TogglContext      $context The context
     * @return null|FeatureScope The scope scope or null
     */
    private function extractFeatureScope(TogglContext $context): ?FeatureScope
    {
        return $context->scope;
    }

    /**
     * Add where clauses to match context type and ID.
     *
     * @param  Builder<Feature> $query   The query builder
     * @param  TogglContext     $context The context
     * @return Builder<Feature> The query builder with context constraints
     */
    private function whereContextMatches(Builder $query, TogglContext $context): Builder
    {
        [$contextType, $contextId] = $this->extractContextMorph($context);

        return $query->where('context_type', $contextType)->where('context_id', $contextId);
    }
}
