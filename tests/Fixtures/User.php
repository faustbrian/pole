<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Cline\Toggl\Concerns\HasTogglContext;
use Cline\Toggl\Contracts\TogglContextable;
use Cline\Toggl\Database\Concerns\HasTogglPrimaryKey;
use Cline\Toggl\Support\FeatureScope;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Tests\Factories\UserFactory;

/**
 * Test fixture User model for feature flag testing.
 *
 * @property string   $email       User email address
 * @property int      $id          User identifier
 * @property string   $name        User display name
 * @property null|int $company_id  Company identifier for hierarchical features
 * @property null|int $division_id Division identifier for hierarchical features
 * @property null|int $org_id      Organization identifier for hierarchical features
 * @property null|int $team_id     Team identifier for hierarchical features
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class User extends Model implements TogglContextable
{
    use HasFactory;
    use HasTogglContext;
    use HasTogglPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['id', 'name', 'email', 'company_id', 'division_id', 'org_id', 'team_id'];

    public function toTogglContext(): TogglContext
    {
        return new TogglContext(
            id: $this->getKey(),
            type: $this->getMorphClass(),
            scope: new FeatureScope(
                kind: 'user',
                constraints: [
                    'company_id' => $this->company_id,
                    'division_id' => $this->division_id,
                    'org_id' => $this->org_id,
                    'team_id' => $this->team_id,
                    'user_id' => $this->id,
                ],
            ),
        );
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
