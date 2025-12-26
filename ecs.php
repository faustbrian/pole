<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\CodingStandard\EasyCodingStandard\Factory;
use PhpCsFixer\Fixer\Phpdoc\PhpdocOrderByValueFixer;

return Factory::create(
    paths: [__DIR__.'/src', __DIR__.'/tests'],
    skip: [
        // Skip PhpdocOrderByValueFixer on Snapshot.php due to backtrack limit with long @method lines
        PhpdocOrderByValueFixer::class => [__DIR__.'/src/Facades/Snapshot.php'],
    ],
);
