<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Support;

use Cline\Toggl\Contracts\TogglContextable;
use Cline\Toggl\Database\ModelRegistry;
use Cline\Toggl\Exceptions\InvalidContextTypeException;
use Illuminate\Database\Eloquent\Model;

use function app;

/**
 * Resolves any context type to a unified TogglContext.
 *
 * Supports:
 * - TogglContextable (primary interface)
 * - Plain Eloquent models (automatic extraction)
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ContextResolver
{
    /**
     * Resolve any context to a TogglContext.
     *
     * Order of resolution:
     * 1. TogglContextable - use toTogglContext() directly
     * 2. Model - use primary key and class
     * 3. Other - assume simple identifier
     *
     * @param  mixed        $context The context to resolve (model, TogglContextable, etc.)
     * @return TogglContext Unified context representation
     */
    public static function resolve(mixed $context): TogglContext
    {
        // Already a TogglContext - return as-is
        if ($context instanceof TogglContext) {
            return $context;
        }

        // Primary interface - preserve source for drivers that need the original model
        if ($context instanceof TogglContextable) {
            $togglContext = $context->toTogglContext();

            // Check if ModelRegistry has a custom key mapping for this model
            // This allows morphKeyMap to override the default toTogglContext() id
            $id = $togglContext->id;

            if ($context instanceof Model) {
                /** @var null|int|string $contextId */
                $contextId = $togglContext->id;
                $id = self::resolveModelId($context, $contextId);
            }

            // Attach source if not already set or if ID changed due to mapping
            if ($togglContext->source === null || $id !== $togglContext->id) {
                return new TogglContext(
                    id: $id,
                    type: $togglContext->type,
                    scope: $togglContext->scope,
                    source: $context,
                );
            }

            return $togglContext;
        }

        // Plain Eloquent model - preserve model as source
        if ($context instanceof Model) {
            return new TogglContext(
                id: self::resolveModelId($context),
                type: $context->getMorphClass(),
                source: $context,
            );
        }

        // Unsupported context type - throw early
        throw InvalidContextTypeException::unsupportedType($context);
    }

    /**
     * Check if a context has scope support.
     *
     * Returns true if the context can provide scope scope through
     * TogglContextable interface.
     *
     * @param  mixed $context The context to check
     * @return bool  True if scope scope is available
     */
    public static function hasScope(mixed $context): bool
    {
        if ($context instanceof TogglContextable) {
            return $context->toTogglContext()->hasScope();
        }

        return false;
    }

    /**
     * Extract scope scope from a context.
     *
     * @param  mixed             $context The context to extract from
     * @return null|FeatureScope The scope scope or null if not available
     */
    public static function extractFeatureScope(mixed $context): ?FeatureScope
    {
        if ($context instanceof TogglContextable) {
            return $context->toTogglContext()->scope;
        }

        return null;
    }

    /**
     * Resolve the ID to use for a model context.
     *
     * Consults ModelRegistry for custom key mappings (morphKeyMap).
     * Falls back to the model's primary key if no mapping exists.
     *
     * @param  Model           $model     The model to resolve ID for
     * @param  null|int|string $defaultId Optional default ID (from toTogglContext)
     * @return int|string      The resolved ID
     */
    private static function resolveModelId(Model $model, int|string|null $defaultId = null): int|string
    {
        $registry = app(ModelRegistry::class);
        $keyName = $registry->getModelKey($model);

        // Only use custom mapping if it differs from the default key
        if ($keyName !== $model->getKeyName()) {
            /** @var int|string */
            return $model->getAttribute($keyName);
        }

        // Use provided default or fall back to primary key
        /** @var int|string */
        return $defaultId ?? $model->getKey();
    }
}
