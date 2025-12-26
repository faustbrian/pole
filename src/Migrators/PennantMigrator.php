<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Migrators;

use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Contracts\Migrator;
use Cline\Toggl\Exceptions\MissingPennantRecordScopeException;
use Cline\Toggl\Exceptions\MissingPennantRecordValueException;
use Cline\Toggl\Exceptions\PennantMigrationException;
use Cline\Toggl\Support\ContextResolver;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

use const JSON_THROW_ON_ERROR;

use function array_key_exists;
use function assert;
use function class_exists;
use function explode;
use function is_string;
use function json_decode;
use function property_exists;
use function sprintf;
use function str_contains;
use function throw_if;

/**
 * Migrator for importing feature flags from Laravel Pennant.
 *
 * This migrator reads feature flag data from Laravel Pennant's database storage
 * and imports it into the Toggl feature flag system, preserving both feature
 * definitions and context-specific values.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PennantMigrator implements Migrator
{
    /**
     * Statistics tracking the migration process.
     *
     * Tracks the number of successfully migrated features and contexts, as well
     * as any errors encountered during migration for post-migration analysis.
     *
     * @var array{features: int, contexts: int, errors: array<string>, migrations: array<array{pennant_id: mixed, pennant_scope: string, pennant_value: mixed, toggl_id: mixed, context_type: string, context_id: mixed, toggl_value: mixed, action: string}>}
     */
    private array $statistics = [
        'features' => 0,
        'contexts' => 0,
        'errors' => [],
        'migrations' => [],
    ];

    /**
     * Create a new Pennant migrator instance.
     *
     * @param Driver      $driver     The target Toggl driver to migrate features into
     * @param string      $table      The Pennant features table name (default: 'features')
     * @param null|string $connection The database connection name (null for default)
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly string $table = 'features',
        private readonly ?string $connection = null,
    ) {}

    /**
     * Execute the migration from Laravel Pennant to Toggl.
     *
     * Imports all feature flags from Pennant's database storage into Toggl,
     * preserving feature names, context-specific values, and JSON data. The
     * migration continues even if individual contexts fail, collecting errors
     * for later review while successfully migrating as much data as possible.
     *
     * @throws Throwable When a critical migration error occurs during feature fetching
     */
    public function migrate(): void
    {
        $this->statistics = [
            'features' => 0,
            'contexts' => 0,
            'errors' => [],
            'migrations' => [],
        ];

        try {
            $features = $this->fetchAllFeatures();

            foreach ($features as $featureName => $records) {
                try {
                    $this->migrateFeature($featureName, $records);
                    ++$this->statistics['features'];
                } catch (Throwable) {
                    // Error already recorded at context level, just skip feature count increment
                }
            }
        } catch (Throwable $throwable) {
            $this->statistics['errors'][] = 'Migration failed: '.$throwable->getMessage();

            throw $throwable;
        }
    }

    /**
     * Retrieve migration statistics.
     *
     * Provides a summary of the migration results including successful feature
     * and context counts, as well as any errors encountered during the process.
     *
     * @return array{features: int, contexts: int, errors: array<string>} Migration statistics
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * Fetch all features from Pennant's database storage.
     *
     * Retrieves all feature records from the Pennant features table and groups
     * them by feature name. Each feature may have multiple records representing
     * different contexts (users, teams, etc.) with their associated values.
     *
     * @return array<string, array<int, stdClass>> Feature name => array of feature records
     */
    private function fetchAllFeatures(): array
    {
        $records = DB::connection($this->connection)
            ->table($this->table)
            ->get();

        $grouped = [];

        foreach ($records as $record) {
            if (!property_exists($record, 'name')) {
                continue;
            }

            if (!is_string($record->name)) {
                continue;
            }

            if (!array_key_exists($record->name, $grouped)) {
                $grouped[$record->name] = [];
            }

            $grouped[$record->name][] = $record;
        }

        return $grouped;
    }

    /**
     * Migrate a single feature and all its context-specific values.
     *
     * Processes all database records for a feature, deserializing contexts and
     * JSON values before importing them into Toggl. Individual context failures
     * are logged but don't halt the migration. If all contexts fail, throws an
     * exception to indicate the feature couldn't be migrated.
     *
     * @param string               $featureName The feature name to migrate
     * @param array<int, stdClass> $records     The Pennant database records for this feature
     *
     * @throws PennantMigrationException When all context migrations fail for this feature
     */
    private function migrateFeature(string $featureName, array $records): void
    {
        $successCount = 0;

        foreach ($records as $record) {
            try {
                // Validate record structure
                throw_if(!property_exists($record, 'scope') || !is_string($record->scope), MissingPennantRecordScopeException::create());

                throw_if(!property_exists($record, 'value') || !is_string($record->value), MissingPennantRecordValueException::create());
                $rawContext = $this->deserializeContext($record->scope);
                $value = json_decode($record->value, associative: true, flags: JSON_THROW_ON_ERROR);

                $pennantId = property_exists($record, 'id') ? $record->id : null;

                if ($rawContext === null) {
                    $this->statistics['errors'][] = sprintf('Skipping deleted/missing model for scope: %s (feature: %s)', $record->scope, $featureName);

                    continue;
                }

                $context = ContextResolver::resolve($rawContext);
                $this->driver->set($featureName, $context, $value);
                $togglRecord = $this->retrieveTogglRecord($featureName, $context);
                $this->recordMigration($pennantId, $record->scope, $record->value, $togglRecord?->id, $context->type, $context->id, $value, 'set');

                ++$this->statistics['contexts'];
                ++$successCount;
            } catch (Throwable $e) {
                $contextDescription = property_exists($record, 'scope') && is_string($record->scope)
                    ? $record->scope
                    : 'unknown';
                $this->statistics['errors'][] = sprintf("Failed to migrate context '%s' for feature '%s': %s", $contextDescription, $featureName, $e->getMessage());
            }
        }

        throw_if($successCount === 0, PennantMigrationException::noContextsMigrated());
    }

    /**
     * Deserialize a Pennant context value to its original form.
     *
     * Laravel Pennant serializes contexts using Toggl::serializeContext(), which
     * produces different formats depending on the context type:
     * - 'null' for null contexts (global features)
     * - 'ClassName|id' for model contexts (e.g., 'App\Models\User|123')
     * - Plain strings for simple string contexts
     *
     * This method reverses that serialization, restoring the original context
     * value. For model contexts, it attempts to retrieve the model instance
     * using the find() method.
     *
     * @param  string $serializedContext The serialized context string from Pennant's database
     * @return mixed  The deserialized context value (null, model instance, or string)
     */
    private function deserializeContext(string $serializedContext): mixed
    {
        if ($serializedContext === 'null') {
            return null;
        }

        if (str_contains($serializedContext, '|')) {
            [$class, $id] = explode('|', $serializedContext, 2);

            assert(class_exists($class), 'Class must be a valid class name');

            // Dynamic Eloquent model resolution - $class is a model class-string from Pennant serialization
            if (Config::boolean('toggl.migrators.pennant.include_soft_deleted', false)) {
                return $class::withTrashed()->find($id); // @phpstan-ignore-line
            }

            return $class::find($id);
        }

        return $serializedContext;
    }

    /**
     * Record a migration entry for detailed tracking.
     *
     * @param mixed  $pennantId    Pennant row ID
     * @param string $pennantScope Pennant scope value
     * @param mixed  $pennantValue Pennant JSON value
     * @param mixed  $togglId      Toggl row ID (null for global updates)
     * @param string $contextType  Context type (morph class)
     * @param mixed  $contextId    Context ID
     * @param mixed  $togglValue   Toggl value
     * @param string $action       Action taken (set, update_all)
     */
    private function recordMigration(mixed $pennantId, string $pennantScope, mixed $pennantValue, mixed $togglId, string $contextType, mixed $contextId, mixed $togglValue, string $action): void
    {
        $this->statistics['migrations'][] = [
            'pennant_id' => $pennantId,
            'pennant_scope' => $pennantScope,
            'pennant_value' => $pennantValue,
            'toggl_id' => $togglId,
            'context_type' => $contextType,
            'context_id' => $contextId,
            'toggl_value' => $togglValue,
            'action' => $action,
        ];
    }

    /**
     * Retrieve the Toggl record for a feature and context.
     *
     * @param  string        $feature The feature name
     * @param  TogglContext  $context The context
     * @return null|stdClass The Toggl record or null
     */
    private function retrieveTogglRecord(string $feature, TogglContext $context): ?stdClass
    {
        $connection = Config::get('toggl.stores.database.connection', Config::get('database.default'));
        $tableName = Config::get('toggl.table_names.features', 'features');
        assert(is_string($tableName), 'Table name must be a string');
        assert(is_string($connection) || null === $connection, 'Connection name must be a string or null');

        $query = $connection === null
            ? DB::table($tableName)
            : DB::connection($connection)->table($tableName);

        $result = $query
            ->where('name', $feature)
            ->where('context_type', $context->type)
            ->where('context_id', $context->id)
            ->first();

        assert($result === null || $result instanceof stdClass);

        return $result;
    }
}
