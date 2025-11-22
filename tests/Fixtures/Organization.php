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
use Cline\Toggl\Support\TogglContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test fixture Organization model for feature flag testing.
 *
 * @property int    $id   Organization identifier
 * @property string $name Organization display name
 * @property string $ulid Organization ULID (when using ULID primary keys)
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class Organization extends Model implements TogglContextable
{
    use HasFactory;
    use HasTogglContext;
    use HasTogglPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['id', 'name', 'ulid'];

    public function toTogglContext(): TogglContext
    {
        return new TogglContext(
            id: $this->getKey(),
            type: self::class,
        );
    }
}
