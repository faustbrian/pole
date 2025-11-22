<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Cline\Toggl\Contracts\TogglContextable;
use Cline\Toggl\Support\TogglContext;

/**
 * Test fixture TogglContextable without scope (non-Model).
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class SimpleContextable implements TogglContextable
{
    public function __construct(
        public int $id,
    ) {}

    public function toTogglContext(): TogglContext
    {
        return new TogglContext(
            id: 'simple:'.$this->id,
            type: self::class,
        );
    }
}
