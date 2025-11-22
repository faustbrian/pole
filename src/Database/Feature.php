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

/**
 * Eloquent model representing feature flag definitions and their states.
 *
 * Stores individual feature flag configurations including their activation state,
 * associated context (user, team, organization, etc.), optional expiration times,
 * resolution strategies, and hierarchical scope constraints for multi-level feature
 * targeting and activation rules.
 *
 * Each feature record binds a named feature to a specific polymorphic context,
 * enabling granular feature control at the user, team, or organization level.
 *
 * @property mixed                     $id           Primary key (auto-increment, UUID, or ULID)
 * @property string                    $name         Feature flag identifier (e.g., 'advanced-analytics')
 * @property string                    $context_type Polymorphic type of the owning context (e.g., 'App\Models\User')
 * @property string                    $context_id   Polymorphic ID of the owning context
 * @property string                    $value        Serialized feature value or activation state
 * @property null|string               $strategy     Optional resolution strategy name for complex activation logic
 * @property null|Carbon               $expires_at   Optional timestamp when this feature activation expires
 * @property null|array<string, mixed> $metadata     Optional arbitrary data for custom feature configuration
 * @property null|array<string, mixed> $scope        Optional hierarchical scope constraints (company_id, org_id, etc.)
 * @property Carbon                    $created_at   Record creation timestamp
 * @property Carbon                    $updated_at   Record last modification timestamp
 * @property null|Model                $context      The polymorphic model this feature belongs to
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class Feature extends Model
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
        'context_type',
        'context_id',
        'value',
        'strategy',
        'expires_at',
        'metadata',
        'scope',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the features table name from the Toggl configuration,
     * defaulting to 'features' if not configured.
     *
     * @return string The table name for feature storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('toggl.table_names.features', 'features');
    }

    /**
     * Get the polymorphic context this feature belongs to.
     *
     * Defines the relationship to the model that owns this feature flag,
     * such as a User, Team, or Organization. This allows features to be
     * scoped to specific entities in the application.
     *
     * @return MorphTo<Model, $this> The polymorphic relationship to the owning context
     */
    public function context(): MorphTo
    {
        return $this->morphTo('context');
    }

    /**
     * Get the attribute casting configuration.
     *
     * Defines how model attributes should be cast when retrieved from
     * or stored to the database. The expires_at field is cast to a Carbon
     * instance for date manipulation, while metadata and scope are cast
     * to arrays for structured data storage.
     *
     * @return array<string, string> Array mapping attribute names to cast types
     */
    #[Override()]
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'metadata' => 'array',
            'scope' => 'array',
        ];
    }
}
