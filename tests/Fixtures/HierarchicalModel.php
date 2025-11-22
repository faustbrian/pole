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
 * Test fixture hierarchical model with scoped attributes.
 *
 * @property int         $id         Model identifier
 * @property null|int    $company_id Company identifier
 * @property null|int    $org_id     Organization identifier
 * @property null|int    $team_id    Team identifier
 * @property null|string $name       Model name
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class HierarchicalModel extends Model implements TogglContextable
{
    use HasFactory;
    use HasTogglContext;

    public $timestamps = false;

    protected $table = 'scoped_models';

    protected $fillable = ['id', 'name', 'company_id', 'org_id', 'team_id'];

    protected function getScopeAttributes(): array
    {
        return ['company_id', 'org_id', 'team_id'];
    }

    protected function getScopeKind(): string
    {
        return 'member';
    }
}
