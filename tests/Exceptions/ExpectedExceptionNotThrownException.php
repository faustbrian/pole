<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Exceptions;

use RuntimeException;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class ExpectedExceptionNotThrownException extends RuntimeException
{
    public static function inTest(): self
    {
        return new self('Expected exception was not thrown');
    }
}
