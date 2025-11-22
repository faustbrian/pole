<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\PhpCsFixer\Preset\Standard;
use Cline\PhpCsFixer\ConfigurationFactory;

$config = ConfigurationFactory::createFromPreset(
    new Standard(),
);

// Disable phpdoc_order_by_value due to PCRE backtrack limit issues with large PHPDocs
$config->setRules(array_merge($config->getRules(), [
    'phpdoc_order_by_value' => false,
]));

/** @var PhpCsFixer\Finder $finder */
$finder = $config->getFinder();
$finder->in([
    __DIR__.'/config',
    __DIR__.'/database',
    __DIR__.'/src',
    __DIR__.'/tests'
]);

return $config;
