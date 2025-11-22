<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Concerns;

use Cline\Toggl\Support\FeatureScope;
use Cline\Toggl\Support\TogglContext;

use function class_basename;
use function mb_strtolower;

/**
 * Enables Eloquent models to convert themselves into TogglContext instances.
 *
 * Provides automatic context generation for models implementing TogglContextable by
 * extracting scope information from configurable model attributes. The default implementation
 * builds contexts using the model's primary key and class name, with optional feature scope
 * derived from hierarchical attributes like company_id, org_id, or team_id.
 *
 * Models using this trait can customize behavior by overriding getScopeAttributes() to
 * specify which attributes define the scope hierarchy, or getScopeKind() to customize
 * the scope identifier used in feature resolution.
 *
 * ```php
 * class User extends Model implements TogglContextable
 * {
 *     use HasTogglContext;
 *
 *     // Optional: customize scope attributes
 *     protected function getScopeAttributes(): array
 *     {
 *         return ['company_id', 'division_id', 'org_id', 'team_id'];
 *     }
 *
 *     // Optional: customize scope kind
 *     protected function getScopeKind(): string
 *     {
 *         return 'user';
 *     }
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @phpstan-ignore trait.unused
 */
trait HasTogglContext
{
    /**
     * Convert this model instance into a TogglContext for feature operations.
     *
     * Builds a context representation using the model's primary key and morph class,
     * automatically incorporating feature scope when scope attributes are configured.
     * Returns a simple context without scope if no scope attributes are defined or
     * all scope attribute values are null.
     *
     * Respects Laravel's enforceMorphMap by using getMorphClass() instead of static::class,
     * ensuring polymorphic relationships use configured aliases rather than full class names.
     *
     * @return TogglContext Context representation ready for feature flag evaluation and storage
     */
    public function toTogglContext(): TogglContext
    {
        $scope = $this->buildFeatureScope();

        if ($scope === null) {
            return TogglContext::simple(
                $this->getKey(),
                $this->getMorphClass(),
            );
        }

        return TogglContext::withScope(
            $this->getKey(),
            $this->getMorphClass(),
            $scope,
        );
    }

    /**
     * Construct a FeatureScope from configured model attributes.
     *
     * Iterates through attributes returned by getScopeAttributes() and builds a scope
     * constraint map for hierarchical feature resolution. Returns null when no scope
     * attributes are configured or when all configured attributes have null values,
     * allowing the context to operate without scope constraints.
     *
     * The model's primary key is automatically included in the scope constraints to
     * enable entity-specific feature targeting within the broader scope hierarchy.
     *
     * @return null|FeatureScope Feature scope with attribute constraints, or null for scopeless context
     */
    protected function buildFeatureScope(): ?FeatureScope
    {
        $attributes = $this->getScopeAttributes();

        if (empty($attributes)) {
            return null;
        }

        $constraints = [];
        $hasValues = false;

        foreach ($attributes as $attribute) {
            $value = $this->getAttribute($attribute);
            $constraints[$attribute] = $value;

            if ($value !== null) {
                $hasValues = true;
            }
        }

        // Include the model's own ID in the scope
        $primaryKey = $this->getKeyName();
        $constraints[$primaryKey] = $this->getKey();

        if (!$hasValues && $this->getKey() === null) {
            return null;
        }

        return new FeatureScope(
            $this->getScopeKind(),
            $constraints,
        );
    }

    /**
     * Specify model attributes to include in feature scope constraints.
     *
     * Override this method to define the hierarchical attribute path used for scoped
     * feature resolution. Attributes should be ordered from broadest to narrowest scope,
     * such as company_id, org_id, team_id. The returned attributes are read from the
     * model and combined with the primary key to build the complete scope constraint map.
     *
     * Empty array (default) results in scopeless contexts that don't participate in
     * hierarchical feature inheritance or scope-based feature resolution.
     *
     * @return array<string> Attribute names defining scope hierarchy, empty for no scope
     */
    protected function getScopeAttributes(): array
    {
        return [];
    }

    /**
     * Determine the scope kind identifier for feature scope operations.
     *
     * Override this method to customize the kind string used in FeatureScope instances,
     * which identifies the entity type in scope-based feature resolution. The default
     * implementation derives the kind from the model's short class name in lowercase,
     * producing values like 'user', 'team', or 'organization' automatically.
     *
     * Custom kinds enable explicit control over scope naming when the automatic derivation
     * doesn't match your domain model or when multiple models should share the same kind.
     *
     * @return string Scope kind identifier used in feature scope resolution
     */
    protected function getScopeKind(): string
    {
        return mb_strtolower(class_basename(static::class));
    }
}
