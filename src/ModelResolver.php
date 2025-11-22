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
use Illuminate\Support\Facades\Config;

/**
 * Resolves Eloquent model classes from configuration.
 *
 * This resolver allows users to swap out default Toggl models with their own
 * custom implementations, similar to how spatie/laravel-permission works.
 * All models must extend their respective Toggl base models to ensure
 * compatibility with package features.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ModelResolver
{
    /**
     * Get the configured Feature model class.
     *
     * @return class-string<Feature>
     */
    public static function feature(): string
    {
        /** @var class-string<Feature> */
        return Config::get('toggl.models.feature', Feature::class);
    }

    /**
     * Get the configured FeatureGroup model class.
     *
     * @return class-string<FeatureGroup>
     */
    public static function featureGroup(): string
    {
        /** @var class-string<FeatureGroup> */
        return Config::get('toggl.models.feature_group', FeatureGroup::class);
    }

    /**
     * Get the configured GroupMembership model class.
     *
     * @return class-string<GroupMembership>
     */
    public static function groupMembership(): string
    {
        /** @var class-string<GroupMembership> */
        return Config::get('toggl.models.group_membership', GroupMembership::class);
    }

    /**
     * Get the configured FeatureSnapshot model class.
     *
     * @return class-string<FeatureSnapshot>
     */
    public static function featureSnapshot(): string
    {
        /** @var class-string<FeatureSnapshot> */
        return Config::get('toggl.models.feature_snapshot', FeatureSnapshot::class);
    }

    /**
     * Get the configured FeatureSnapshotEntry model class.
     *
     * @return class-string<FeatureSnapshotEntry>
     */
    public static function snapshotEntry(): string
    {
        /** @var class-string<FeatureSnapshotEntry> */
        return Config::get('toggl.models.feature_snapshot_entry', FeatureSnapshotEntry::class);
    }

    /**
     * Get the configured FeatureSnapshotEvent model class.
     *
     * @return class-string<FeatureSnapshotEvent>
     */
    public static function snapshotEvent(): string
    {
        /** @var class-string<FeatureSnapshotEvent> */
        return Config::get('toggl.models.feature_snapshot_event', FeatureSnapshotEvent::class);
    }
}
