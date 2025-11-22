<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl;

use Cline\Toggl\Database\Feature;
use Cline\Toggl\Database\FeatureGroup;
use Cline\Toggl\Database\FeatureSnapshot;
use Cline\Toggl\Database\FeatureSnapshotEntry;
use Cline\Toggl\Database\FeatureSnapshotEvent;
use Cline\Toggl\Database\GroupMembership;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fluent query builder for Toggl models.
 *
 * Provides convenient static methods to access configured model query builders,
 * eliminating verbose ModelResolver calls throughout the codebase.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class QueryBuilder
{
    /**
     * Get query builder for Feature model.
     *
     * @return Builder<Feature>
     */
    public static function feature(): Builder
    {
        return ModelResolver::feature()::query();
    }

    /**
     * Get query builder for FeatureGroup model.
     *
     * @return Builder<FeatureGroup>
     */
    public static function featureGroup(): Builder
    {
        return ModelResolver::featureGroup()::query();
    }

    /**
     * Get query builder for GroupMembership model.
     *
     * @return Builder<GroupMembership>
     */
    public static function groupMembership(): Builder
    {
        return ModelResolver::groupMembership()::query();
    }

    /**
     * Get query builder for FeatureSnapshot model.
     *
     * @return Builder<FeatureSnapshot>
     */
    public static function featureSnapshot(): Builder
    {
        return ModelResolver::featureSnapshot()::query();
    }

    /**
     * Get query builder for FeatureSnapshotEntry model.
     *
     * @return Builder<FeatureSnapshotEntry>
     */
    public static function snapshotEntry(): Builder
    {
        return ModelResolver::snapshotEntry()::query();
    }

    /**
     * Get query builder for FeatureSnapshotEvent model.
     *
     * @return Builder<FeatureSnapshotEvent>
     */
    public static function snapshotEvent(): Builder
    {
        return ModelResolver::snapshotEvent()::query();
    }
}
