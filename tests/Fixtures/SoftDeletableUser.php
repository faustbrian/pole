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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * User model with SoftDeletes for testing soft delete functionality.
 *
 * @property string $email User email address
 * @property int    $id    User identifier
 * @property string $name  User display name
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class SoftDeletableUser extends Model implements TogglContextable
{
    use HasFactory;
    use HasTogglContext;
    use HasTogglPrimaryKey;
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'users';

    protected $fillable = ['id', 'name', 'email'];

    public function toTogglContext(): TogglContext
    {
        return new TogglContext(
            id: $this->getKey(),
            type: $this->getMorphClass(),
            scope: new FeatureScope(
                kind: 'user',
                constraints: [],
            ),
        );
    }
}
