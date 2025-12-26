<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test fixture plain model without any interface.
 *
 * @property int         $id   Model identifier
 * @property null|string $name Model name
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PlainModel extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'plain_models';

    protected $fillable = ['id', 'name'];
}
