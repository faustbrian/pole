<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Database;

use Cline\Toggl\Database\Concerns\HasTogglPrimaryKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

use function assert;
use function is_string;

/**
 * Eloquent model representing named collections of related feature flags.
 *
 * Stores logical groupings of features that should be managed together, enabling
 * batch operations on related features. Groups can contain arbitrary metadata for
 * custom configuration and are useful for organizing features by domain, team,
 * release cycle, or any other organizational structure.
 *
 * Feature groups simplify bulk activation/deactivation and provide a mechanism
 * for managing feature sets as cohesive units rather than individual flags.
 *
 * @property mixed                     $id         Primary key (auto-increment, UUID, or ULID)
 * @property string                    $name       Unique group identifier (e.g., 'premium-tier', 'beta-features')
 * @property array<int, string>        $features   Array of feature flag names included in this group
 * @property null|array<string, mixed> $metadata   Optional arbitrary data for group configuration or description
 * @property Carbon                    $created_at Record creation timestamp
 * @property Carbon                    $updated_at Record last modification timestamp
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class FeatureGroup extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;
    use HasTogglPrimaryKey;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'features',
        'metadata',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the feature groups table name from the Toggl configuration,
     * defaulting to 'feature_groups' if not configured.
     *
     * @return string The table name for feature group storage
     */
    #[Override()]
    public function getTable(): string
    {
        $table = Config::get('toggl.table_names.feature_groups', 'feature_groups');
        assert(is_string($table));

        return $table;
    }

    /**
     * Get the attribute casting configuration.
     *
     * Defines how model attributes should be cast when retrieved from
     * or stored to the database. Both features and metadata are cast
     * to arrays, allowing structured storage of feature names and
     * additional group configuration data.
     *
     * @return array<string, string> Array mapping attribute names to cast types
     */
    #[Override()]
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'metadata' => 'array',
        ];
    }
}
