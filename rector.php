<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Rector Configuration for Toggl
 *
 * This configuration file defines the Rector automated refactoring rules for the
 * Toggl package. It enables PHP 8.4 features, Laravel-specific optimizations, and
 * various code quality improvements including dead code removal, type declarations,
 * privatization, and early returns.
 *
 * The configuration skips unreachable statement removal in tests to allow for
 * intentional test patterns that may include unreachable code for demonstration
 * or edge case testing purposes.
 *
 * @see https://github.com/rectorphp/rector
 * @see https://github.com/driftingly/rector-laravel
 */

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withSkip([
        RemoveUnreachableStatementRector::class => [__DIR__.'/tests'],
        // Skip removing @var annotations on return statements that are needed for PHPStan type narrowing
        // json_decode() returns mixed, and is_array() doesn't narrow the type enough for PHPStan
        RemoveNonExistingVarAnnotationRector::class => [
            __DIR__.'/src/GroupRepositories/DatabaseGroupRepository.php',
        ],
    ])
    ->withPhpSets(php85: true)
    ->withParallel(maxNumberOfProcess: 8)
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(
        phpunit: true,
        laravel: true,
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        naming: false,
        instanceOf: true,
        earlyReturn: true,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
        symfonyConfigs: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_FACTORIES,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_LEGACY_FACTORIES_TO_CLASSES,
        // LaravelSetList::LARAVEL_STATIC_TO_INJECTION,
    ])
    ->withRootFiles();
