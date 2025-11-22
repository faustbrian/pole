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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

use function assert;
use function is_string;

/**
 * Eloquent model representing context membership in feature groups.
 *
 * Stores the assignment of polymorphic contexts (users, teams, organizations)
 * to named feature groups, enabling batch feature management and group-based
 * access control. When a context is a member of a group, all features in that
 * group become accessible to the context.
 *
 * This model enables efficient management of feature sets across multiple
 * contexts without requiring individual feature assignments.
 *
 * @property mixed                     $id           Primary key (auto-increment, UUID, or ULID)
 * @property string                    $group_name   The name of the feature group this membership grants access to
 * @property string                    $context_type Polymorphic type of the member context (e.g., 'App\Models\User')
 * @property string                    $context_id   Polymorphic ID of the member context
 * @property null|array<string, mixed> $metadata     Optional arbitrary data about the membership (reason, expiration, etc.)
 * @property Carbon                    $created_at   When this membership was created
 * @property Carbon                    $updated_at   When this membership was last modified
 * @property null|Model                $context      The polymorphic model that is a member of the group
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class FeatureGroupMembership extends Model
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
        'group_name',
        'context_type',
        'context_id',
        'metadata',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the feature group memberships table name from the Toggl configuration,
     * defaulting to 'feature_group_memberships' if not configured.
     *
     * @return string The table name for feature group membership storage
     */
    #[Override()]
    public function getTable(): string
    {
        $table = Config::get('toggl.table_names.feature_group_memberships', 'feature_group_memberships');
        assert(is_string($table));

        return $table;
    }

    /**
     * Get the polymorphic context that is a member of this group.
     *
     * Defines the relationship to the model that has been granted membership
     * in the feature group, such as a User, Team, or Organization.
     *
     * @return MorphTo<Model, $this> The polymorphic relationship to the member context
     */
    public function context(): MorphTo
    {
        return $this->morphTo('context');
    }

    /**
     * Get the attribute casting configuration.
     *
     * Defines how model attributes should be cast when retrieved from
     * or stored to the database. The metadata field is cast to an array
     * for structured storage of membership-related information.
     *
     * @return array<string, string> Array mapping attribute names to cast types
     */
    #[Override()]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
