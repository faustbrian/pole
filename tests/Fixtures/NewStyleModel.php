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
 * Test fixture model with new TogglContextable interface.
 *
 * @property int      $id         Model identifier
 * @property null|int $company_id Company identifier
 * @property null|int $org_id     Organization identifier
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class NewStyleModel extends Model implements TogglContextable
{
    use HasFactory;
    use HasTogglContext;

    public $timestamps = false;

    protected $table = 'new_style_models';

    protected $fillable = ['id', 'company_id', 'org_id'];

    protected function getScopeAttributes(): array
    {
        return ['company_id', 'org_id'];
    }
}
