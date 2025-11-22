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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * Eloquent model representing audit events in snapshot lifecycle.
 *
 * Stores a chronological audit trail of all operations performed on feature
 * snapshots, including creation, restoration, and deletion events. Each event
 * captures who performed the action, when it occurred, and optional contextual
 * metadata about the operation.
 *
 * This model provides accountability and traceability for snapshot operations,
 * supporting compliance and debugging requirements.
 *
 * @property mixed                     $id                Primary key (auto-increment, UUID, or ULID)
 * @property mixed                     $snapshot_id       Foreign key to the parent snapshot
 * @property string                    $event_type        Type of event (created, restored, deleted)
 * @property null|string               $performed_by_type Polymorphic type of the user who performed this action
 * @property null|string               $performed_by_id   Polymorphic ID of the user who performed this action
 * @property null|array<string, mixed> $metadata          Optional contextual data about the event (reason, IP, etc.)
 * @property Carbon                    $created_at        When this event occurred
 * @property FeatureSnapshot           $snapshot          The parent snapshot this event belongs to
 * @property null|Model                $performedBy       The user who performed this action
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class FeatureSnapshotEvent extends Model
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
        'event_type',
        'performed_by_type',
        'performed_by_id',
        'metadata',
        'created_at',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the snapshot events table name from the Toggl configuration,
     * defaulting to 'feature_snapshot_events' if not configured.
     *
     * @return string The table name for snapshot event storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('toggl.table_names.feature_snapshot_events', 'feature_snapshot_events');
    }

    /**
     * Get the parent snapshot this event belongs to.
     *
     * Defines the relationship to the snapshot that this event is tracking,
     * enabling navigation from audit events to their snapshot context.
     *
     * @return BelongsTo<FeatureSnapshot, $this> The relationship to the parent snapshot
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(FeatureSnapshot::class, 'snapshot_id');
    }

    /**
     * Get the user who performed this action.
     *
     * Polymorphic relationship to the user model that initiated the snapshot
     * operation, providing accountability for audit trail entries.
     *
     * @return MorphTo<Model, $this> The polymorphic relationship to the user
     */
    public function performedBy(): MorphTo
    {
        return $this->morphTo('performed_by');
    }

    /**
     * Get the attribute casting configuration.
     *
     * Defines how model attributes should be cast when retrieved from
     * or stored to the database. The metadata field is cast to an array
     * for structured event context, while created_at is cast to Carbon
     * for timestamp manipulation.
     *
     * @return array<string, string> Array mapping attribute names to cast types
     */
    #[Override()]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
