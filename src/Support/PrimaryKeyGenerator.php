<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Support;

use Cline\Toggl\Enums\PrimaryKeyType;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Generates primary key values based on configuration.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PrimaryKeyGenerator
{
    public static function generate(): PrimaryKeyValue
    {
        /** @var int|string $configValue */
        $configValue = Config::get('toggl.primary_key_type', 'id');
        $primaryKeyType = PrimaryKeyType::tryFrom($configValue) ?? PrimaryKeyType::ID;

        $value = match ($primaryKeyType) {
            PrimaryKeyType::ULID => Str::lower((string) Str::ulid()),
            PrimaryKeyType::UUID => Str::lower((string) Str::uuid()),
            PrimaryKeyType::ID => null,
        };

        return new PrimaryKeyValue($primaryKeyType, $value);
    }
}
