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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

use function property_exists;
use function sprintf;

/**
 * Migrator for importing feature flags from YLSIdeas Feature Flags.
 *
 * This migrator reads feature flag data from YLSIdeas Feature Flags' database storage
 * and imports it into the Toggl feature flag system. YLSIdeas stores features as simple
 * on/off toggles with timestamps, which are converted to boolean values.
 *
 * @phpstan-type YLSIdeasRecord stdClass&object{feature: string}
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class YLSIdeasMigrator implements Migrator
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
     * Create a new YLSIdeas migrator instance.
     *
     * @param Driver      $driver     The target Toggl driver to migrate features into
     * @param string      $table      The YLSIdeas features table name (default: 'features')
     * @param string      $field      The field name for active status (default: 'active_at')
     * @param null|string $connection The database connection name (null for default)
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly string $table = 'features',
        private readonly string $field = 'active_at',
        private readonly ?string $connection = null,
    ) {}

    /**
     * Execute the migration from YLSIdeas Feature Flags to Toggl.
     *
     * Imports all feature flags from YLSIdeas' database storage into Toggl as
     * global (null context) boolean toggles. YLSIdeas features are simple on/off
     * switches, so they migrate as true/false values based on the activation
     * timestamp field. Individual feature failures are logged but don't halt
     * the overall migration process.
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

            /** @var stdClass $feature */
            foreach ($features as $feature) {
                try {
                    /** @var object{feature: string}&stdClass $feature */
                    $this->migrateFeature($feature);
                    ++$this->statistics['features'];
                    ++$this->statistics['contexts'];
                } catch (Throwable $e) {
                    $featureName = property_exists($feature, 'feature') ? $feature->feature : 'unknown';
                    $this->statistics['errors'][] = sprintf("Failed to migrate feature '%s': %s", $featureName, $e->getMessage());
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
     * Fetch all features from YLSIdeas' database storage.
     *
     * Retrieves all feature records from the configured YLSIdeas features table.
     * Each record represents a single global feature toggle with a name and
     * optional activation timestamp.
     *
     * @return Collection<int, stdClass> Collection of feature database records
     */
    private function fetchAllFeatures(): Collection
    {
        return DB::connection($this->connection)
            ->table($this->table)
            ->get();
    }

    /**
     * Migrate a single feature from YLSIdeas to Toggl.
     *
     * YLSIdeas stores features as global toggles with a nullable timestamp field.
     * If the field is non-null, the feature is active; otherwise it's inactive.
     * The feature is migrated to Toggl as a global feature (all contexts) with a boolean value.
     *
     * @param stdClass $feature The YLSIdeas feature record with 'feature' name and activation field
     *
     * @phpstan-param object{feature: string}&stdClass $feature
     */
    private function migrateFeature(stdClass $feature): void
    {
        $isActive = !empty($feature->{$this->field});

        /** @var string $featureName */
        $featureName = $feature->feature;

        $this->driver->setForAllContexts($featureName, $isActive);
    }
}
