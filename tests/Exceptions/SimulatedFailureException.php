<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Exceptions;

use Exception;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class SimulatedFailureException extends Exception
{
    public static function forTest(): self
    {
        return new self('Simulated failure');
    }

    public static function withMessage(string $message): self
    {
        return new self($message);
    }
}
