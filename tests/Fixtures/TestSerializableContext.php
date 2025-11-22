<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Cline\Toggl\Contracts\Serializable;

/**
 * Test fixture implementing Serializable.
 *
 * Used to test custom context serialization in feature group membership operations.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class TestSerializableContext implements Serializable
{
    public function __construct(
        private string $id,
    ) {}

    public function serialize(): string
    {
        return 'test-context:'.$this->id;
    }
}
