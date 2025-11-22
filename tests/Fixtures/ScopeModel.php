<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Cline\Toggl\Contracts\TogglContextable;
use Cline\Toggl\Support\FeatureScope;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test fixture model with TogglContextable and custom scope.
 *
 * @property int      $id         Model identifier
 * @property null|int $company_id Company identifier
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ScopeModel extends Model implements TogglContextable
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'scope_models';

    protected $fillable = ['id', 'company_id'];

    public function toTogglContext(): TogglContext
    {
        return new TogglContext(
            id: $this->getKey(),
            type: self::class,
            scope: new FeatureScope('scope', [
                'company_id' => $this->company_id,
                'id' => $this->id,
            ]),
        );
    }
}
