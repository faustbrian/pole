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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

use function assert;
use function is_string;

/**
 * Eloquent model representing individual feature states within a snapshot.
 *
 * Stores the captured state of a single feature flag at the time a snapshot
 * was created, preserving the feature's name, value, and activation status.
 * Multiple entries belong to a single snapshot, collectively representing
 * the complete feature configuration at a point in time.
 *
 * These entries enable precise restoration of feature states and provide
 * detailed historical records for audit and comparison purposes.
 *
 * @property mixed           $id            Primary key (auto-increment, UUID, or ULID)
 * @property mixed           $snapshot_id   Foreign key to the parent snapshot
 * @property string          $feature_name  The feature flag identifier at time of capture
 * @property mixed           $feature_value The serialized feature value at time of capture
 * @property bool            $is_active     Whether the feature was active at time of capture
 * @property Carbon          $created_at    When this entry was created (snapshot timestamp)
 * @property FeatureSnapshot $snapshot      The parent snapshot containing this entry
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class FeatureSnapshotEntry extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;
    use HasTogglPrimaryKey;

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'snapshot_id',
        'feature_name',
        'feature_value',
        'is_active',
        'created_at',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the snapshot entries table name from the Toggl configuration,
     * defaulting to 'feature_snapshot_entries' if not configured.
     *
     * @return string The table name for snapshot entry storage
     */
    #[Override()]
    public function getTable(): string
    {
        $tableName = Config::get('toggl.table_names.feature_snapshot_entries', 'feature_snapshot_entries');

        assert(is_string($tableName));

        return $tableName;
    }

    /**
     * Get the parent snapshot containing this entry.
     *
     * Defines the relationship to the snapshot that captured this feature state,
     * enabling navigation from individual entries to their snapshot context.
     *
     * @return BelongsTo<FeatureSnapshot, $this> The relationship to the parent snapshot
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(FeatureSnapshot::class, 'snapshot_id');
    }

    /**
     * Get the attribute casting configuration.
     *
     * Defines how model attributes should be cast when retrieved from
     * or stored to the database. The feature_value is cast to JSON for
     * complex data structures, is_active to boolean for flag states,
     * and created_at to Carbon for timestamp manipulation.
     *
     * @return array<string, string> Array mapping attribute names to cast types
     */
    #[Override()]
    protected function casts(): array
    {
        return [
            'feature_value' => 'json',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
