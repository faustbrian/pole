<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Database;

use Cline\Toggl\Database\Concerns\HasTogglPrimaryKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * Eloquent model representing point-in-time captures of feature flag states.
 *
 * Stores snapshots of all feature flags for a specific context at a given moment,
 * enabling restore functionality and audit trails. Snapshots preserve the complete
 * feature configuration including activation states, values, and metadata, allowing
 * rollback to previous states or comparison between configurations.
 *
 * Each snapshot maintains provenance information about who created and potentially
 * restored it, supporting accountability and change tracking requirements.
 *
 * @property mixed                                 $id               Primary key (auto-increment, UUID, or ULID)
 * @property null|string                           $label            Optional human-readable label for the snapshot
 * @property string                                $context_type     Polymorphic type of the context being snapshotted
 * @property string                                $context_id       Polymorphic ID of the context being snapshotted
 * @property null|string                           $created_by_type  Polymorphic type of the user who created this snapshot
 * @property null|string                           $created_by_id    Polymorphic ID of the user who created this snapshot
 * @property Carbon                                $created_at       When this snapshot was captured
 * @property null|Carbon                           $restored_at      When this snapshot was restored (null if never restored)
 * @property null|string                           $restored_by_type Polymorphic type of the user who restored this snapshot
 * @property null|string                           $restored_by_id   Polymorphic ID of the user who restored this snapshot
 * @property null|array<string, mixed>             $metadata         Optional arbitrary data about the snapshot (reason, tags, etc.)
 * @property Collection<int, FeatureSnapshotEntry> $entries          The captured feature states in this snapshot
 * @property Collection<int, FeatureSnapshotEvent> $events           Audit trail of snapshot lifecycle events
 * @property null|Model                            $context          The polymorphic model this snapshot belongs to
 * @property null|Model                            $createdBy        The user who created this snapshot
 * @property null|Model                            $restoredBy       The user who restored this snapshot
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class FeatureSnapshot extends Model
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
        'label',
        'context_type',
        'context_id',
        'created_by_type',
        'created_by_id',
        'created_at',
        'restored_at',
        'restored_by_type',
        'restored_by_id',
        'metadata',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the feature snapshots table name from the Toggl configuration,
     * defaulting to 'feature_snapshots' if not configured.
     *
     * @return string The table name for snapshot storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('toggl.table_names.feature_snapshots', 'feature_snapshots');
    }

    /**
     * Get all feature states captured in this snapshot.
     *
     * Each entry represents a single feature's state at the time of snapshot creation,
     * including the feature name, value, and activation status.
     *
     * @return HasMany<FeatureSnapshotEntry, $this> Collection of captured feature states
     */
    public function entries(): HasMany
    {
        return $this->hasMany(FeatureSnapshotEntry::class, 'snapshot_id');
    }

    /**
     * Get all lifecycle events for this snapshot.
     *
     * Events track the snapshot's lifecycle including creation, restoration,
     * and deletion, providing a complete audit trail of snapshot operations.
     *
     * @return HasMany<FeatureSnapshotEvent, $this> Collection of snapshot lifecycle events
     */
    public function events(): HasMany
    {
        return $this->hasMany(FeatureSnapshotEvent::class, 'snapshot_id');
    }

    /**
     * Get the polymorphic context this snapshot belongs to.
     *
     * Defines the relationship to the model whose feature states were captured,
     * such as a User, Team, or Organization.
     *
     * @return MorphTo<Model, $this> The polymorphic relationship to the snapshotted context
     */
    public function context(): MorphTo
    {
        return $this->morphTo('context');
    }

    /**
     * Get the user who created this snapshot.
     *
     * Polymorphic relationship to the user model that initiated snapshot creation,
     * supporting accountability and audit requirements.
     *
     * @return MorphTo<Model, $this> The polymorphic relationship to the creator
     */
    public function createdBy(): MorphTo
    {
        return $this->morphTo('created_by');
    }

    /**
     * Get the user who restored this snapshot.
     *
     * Polymorphic relationship to the user model that restored this snapshot,
     * if it has been restored. Returns null if snapshot has never been restored.
     *
     * @return MorphTo<Model, $this> The polymorphic relationship to the restorer
     */
    public function restoredBy(): MorphTo
    {
        return $this->morphTo('restored_by');
    }

    /**
     * Boot the model and register event handlers.
     *
     * Registers a deleting event handler that cascades deletion to all related
     * entries and events, ensuring referential integrity when a snapshot is removed.
     */
    #[Override()]
    protected static function boot(): void
    {
        parent::boot();

        // Cascade deletion to related records
        self::deleting(function (self $snapshot): void {
            $snapshot->entries()->delete();
            $snapshot->events()->delete();
        });
    }

    /**
     * Get the attribute casting configuration.
     *
     * Defines how model attributes should be cast when retrieved from
     * or stored to the database. Timestamp fields are cast to Carbon
     * instances for date manipulation, while metadata is cast to an
     * array for structured data storage.
     *
     * @return array<string, string> Array mapping attribute names to cast types
     */
    #[Override()]
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'restored_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
