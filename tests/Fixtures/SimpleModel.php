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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test fixture simple model with HasTogglContext trait (no scope).
 *
 * @property int         $id   Model identifier
 * @property null|string $name Model name
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class SimpleModel extends Model implements TogglContextable
{
    use HasFactory;
    use HasTogglContext;

    public $timestamps = false;

    protected $table = 'simple_models';

    protected $fillable = ['id', 'name'];
}
