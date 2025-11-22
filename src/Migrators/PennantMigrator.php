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
use Cline\Toggl\Exceptions\InvalidPennantRecordException;
use Cline\Toggl\Exceptions\PennantMigrationException;
use Cline\Toggl\Support\ContextResolver;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use stdClass;
use Throwable;

use const JSON_THROW_ON_ERROR;

use function array_key_exists;
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
     * @var array{features: int, contexts: int, errors: array<string>}
     */
    private array $statistics = [
        'features' => 0,
        'contexts' => 0,
        'errors' => [],
    ];

    /**
     * Create a new Pennant migrator instance.
     *
     * @param DatabaseManager $db         The database manager for accessing Pennant's storage
     * @param Driver          $driver     The target Toggl driver to migrate features into
     * @param string          $table      The Pennant features table name (default: 'features')
     * @param null|string     $connection The database connection name (null for default)
     */
    public function __construct(
        private readonly DatabaseManager $db,
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
        $records = $this->getConnection()
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
                throw_if(!property_exists($record, 'scope') || !is_string($record->scope), InvalidPennantRecordException::missingOrInvalidScope());

                throw_if(!property_exists($record, 'value') || !is_string($record->value), InvalidPennantRecordException::missingOrInvalidValue());
                $rawContext = $this->deserializeContext($record->scope);
                $value = json_decode($record->value, associative: true, flags: JSON_THROW_ON_ERROR);

                if ($rawContext === null) {
                    $this->driver->setForAllContexts($featureName, $value);
                } else {
                    $context = ContextResolver::resolve($rawContext);
                    $this->driver->set($featureName, $context, $value);
                }

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

            return $class::find($id);
        }

        return $serializedContext;
    }

    /**
     * Get the database connection for accessing Pennant's storage.
     *
     * @return Connection The configured database connection instance
     */
    private function getConnection(): Connection
    {
        return $this->db->connection($this->connection);
    }
}
