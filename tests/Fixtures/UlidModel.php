<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Cline\Toggl\Database\Concerns\HasTogglPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test fixture model with ULID primary key.
 *
 * @property string $id   ULID identifier
 * @property string $name Model name
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class UlidModel extends Model
{
    use HasFactory;
    use HasTogglPrimaryKey;

    public $timestamps = false;

    protected $table = 'ulid_models';

    protected $fillable = ['id', 'name'];
}
