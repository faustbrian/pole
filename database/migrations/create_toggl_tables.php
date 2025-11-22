<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Enums\MorphType;
use Cline\Toggl\Enums\PrimaryKeyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for creating Toggl feature flag and snapshot database tables.
 *
 * This migration creates six tables for the Toggl feature flag and snapshot system:
 * - features: stores feature definitions, values, strategies, and expiration
 * - feature_groups: stores named groups of related features
 * - group_memberships: stores context assignments to feature groups
 * - feature_snapshots: stores snapshot metadata (who, when, context)
 * - feature_snapshot_entries: stores individual feature states at snapshot time
 * - feature_snapshot_events: stores audit trail of snapshot operations
 *
 * The primary key type (ID, ULID, UUID) is configured via the toggl.primary_key_type
 * configuration option to support different application requirements.
 *
 * @see config/toggl.php
 */
return new class() extends Migration
{
    /**
     * Run the migrations to create feature flag and snapshot tables.
     *
     * Creates the database schema for storing feature flags, feature groups,
     * group memberships, and snapshots with support for configurable primary key types.
     */
    public function up(): void
    {
        $primaryKeyType = PrimaryKeyType::tryFrom(config('toggl.primary_key_type', 'id')) ?? PrimaryKeyType::ID;
        $morphType = MorphType::tryFrom(config('toggl.morph_type', 'morph')) ?? MorphType::Morph;

        $connection = config('toggl.stores.database.connection') ?? config('database.default');
        $useJsonb = DB::connection($connection)->getDriverName() === 'pgsql';

        // Create features table
        Schema::create(config('toggl.table_names.features', 'features'), function (Blueprint $table) use ($primaryKeyType, $morphType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            $table->string('name');

            match ($morphType) {
                MorphType::ULID => $table->ulidMorphs('context'),
                MorphType::UUID => $table->uuidMorphs('context'),
                MorphType::Numeric => $table->numericMorphs('context'),
                MorphType::Morph => $table->morphs('context'),
            };

            $table->text('value');
            $table->string('strategy')->nullable()->comment('Strategy class name (boolean, time_based, percentage, etc.)');
            $table->timestamp('expires_at')->nullable()->comment('Time bomb expiration timestamp');
            $useJsonb
                ? $table->jsonb('metadata')->nullable()->comment('Strategy-specific configuration (time ranges, percentage values, etc.)')
                : $table->json('metadata')->nullable()->comment('Strategy-specific configuration (time ranges, percentage values, etc.)');
            $useJsonb
                ? $table->jsonb('scope')->nullable()->comment('Scoped scope constraints for multi-tenant feature resolution')
                : $table->json('scope')->nullable()->comment('Scoped scope constraints for multi-tenant feature resolution');
            $table->timestamps();

            // Index for exact context lookups (most common case)
            $table->index(['name', 'context_type', 'context_id']);
            $table->index('expires_at');

            if ($useJsonb) {
                // PostgreSQL GIN index for efficient JSON queries
                $table->index('scope', 'features_scope_gin', 'gin');
            }
        });

        // Create feature_groups table
        Schema::create(config('toggl.table_names.feature_groups', 'feature_groups'), function (Blueprint $table) use ($primaryKeyType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            $table->string('name')->unique()->comment('Unique group name');
            $useJsonb
                ? $table->jsonb('features')->comment('Array of feature names in this group')
                : $table->json('features')->comment('Array of feature names in this group');
            $useJsonb
                ? $table->jsonb('metadata')->nullable()->comment('Optional metadata for the group')
                : $table->json('metadata')->nullable()->comment('Optional metadata for the group');
            $table->timestamps();

            $table->index('name');
        });

        // Create group_memberships table
        Schema::create(config('toggl.table_names.group_memberships', 'group_memberships'), function (Blueprint $table) use ($primaryKeyType, $morphType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            $table->string('group_name')->index()->comment('Name of the feature group');

            match ($morphType) {
                MorphType::ULID => $table->ulidMorphs('context'),
                MorphType::UUID => $table->uuidMorphs('context'),
                MorphType::Numeric => $table->numericMorphs('context'),
                MorphType::Morph => $table->morphs('context'),
            };

            $useJsonb
                ? $table->jsonb('metadata')->nullable()->comment('Optional metadata for the membership')
                : $table->json('metadata')->nullable()->comment('Optional metadata for the membership');
            $table->timestamps();

            $table->unique(['group_name', 'context_type', 'context_id']);
        });

        // Create feature_snapshots table (snapshot metadata)
        Schema::create(config('toggl.table_names.feature_snapshots', 'feature_snapshots'), function (Blueprint $table) use ($primaryKeyType, $morphType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            $table->string('label')->nullable()->comment('Human-readable label for the snapshot');

            // Context that this snapshot belongs to
            match ($morphType) {
                MorphType::ULID => $table->ulidMorphs('context'),
                MorphType::UUID => $table->uuidMorphs('context'),
                MorphType::Numeric => $table->numericMorphs('context'),
                MorphType::Morph => $table->morphs('context'),
            };

            // Who created this snapshot
            match ($morphType) {
                MorphType::ULID => $table->nullableUlidMorphs('created_by'),
                MorphType::UUID => $table->nullableUuidMorphs('created_by'),
                MorphType::Numeric => $table->nullableNumericMorphs('created_by'),
                MorphType::Morph => $table->nullableMorphs('created_by'),
            };

            $table->timestamp('created_at')->useCurrent()->comment('When snapshot was created');

            // Restoration tracking
            $table->timestamp('restored_at')->nullable()->comment('When snapshot was last restored');

            // Who restored this snapshot
            match ($morphType) {
                MorphType::ULID => $table->nullableUlidMorphs('restored_by'),
                MorphType::UUID => $table->nullableUuidMorphs('restored_by'),
                MorphType::Numeric => $table->nullableNumericMorphs('restored_by'),
                MorphType::Morph => $table->nullableMorphs('restored_by'),
            };

            $useJsonb
                ? $table->jsonb('metadata')->nullable()->comment('Additional metadata for the snapshot')
                : $table->json('metadata')->nullable()->comment('Additional metadata for the snapshot');

            $table->index('created_at');
            $table->index('restored_at');
        });

        // Create feature_snapshot_entries table (feature states at snapshot time)
        Schema::create(config('toggl.table_names.feature_snapshot_entries', 'feature_snapshot_entries'), function (Blueprint $table) use ($primaryKeyType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            // Foreign key to snapshot
            $snapshotsTable = config('toggl.table_names.feature_snapshots', 'feature_snapshots');
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->foreignUlid('snapshot_id')->constrained($snapshotsTable)->cascadeOnDelete(),
                PrimaryKeyType::UUID => $table->foreignUuid('snapshot_id')->constrained($snapshotsTable)->cascadeOnDelete(),
                PrimaryKeyType::ID => $table->foreignId('snapshot_id')->constrained($snapshotsTable)->cascadeOnDelete(),
            };

            $table->string('feature_name')->comment('Name of the feature');
            $useJsonb
                ? $table->jsonb('feature_value')->nullable()->comment('Feature value at snapshot time')
                : $table->json('feature_value')->nullable()->comment('Feature value at snapshot time');
            $table->boolean('is_active')->default(true)->comment('Whether feature was active');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['snapshot_id', 'feature_name']);
            $table->index('feature_name');
        });

        // Create feature_snapshot_events table (audit trail)
        Schema::create(config('toggl.table_names.feature_snapshot_events', 'feature_snapshot_events'), function (Blueprint $table) use ($primaryKeyType, $morphType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            // Foreign key to snapshot
            $snapshotsTable = config('toggl.table_names.feature_snapshots', 'feature_snapshots');
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->foreignUlid('snapshot_id')->constrained($snapshotsTable)->cascadeOnDelete(),
                PrimaryKeyType::UUID => $table->foreignUuid('snapshot_id')->constrained($snapshotsTable)->cascadeOnDelete(),
                PrimaryKeyType::ID => $table->foreignId('snapshot_id')->constrained($snapshotsTable)->cascadeOnDelete(),
            };

            $table->enum('event_type', ['created', 'restored', 'deleted', 'partial_restore'])->comment('Type of event');

            // Who performed this event
            match ($morphType) {
                MorphType::ULID => $table->nullableUlidMorphs('performed_by'),
                MorphType::UUID => $table->nullableUuidMorphs('performed_by'),
                MorphType::Numeric => $table->nullableNumericMorphs('performed_by'),
                MorphType::Morph => $table->nullableMorphs('performed_by'),
            };

            $useJsonb
                ? $table->jsonb('metadata')->nullable()->comment('Event-specific metadata (features restored, etc.)')
                : $table->json('metadata')->nullable()->comment('Event-specific metadata (features restored, etc.)');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['snapshot_id', 'event_type']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations by dropping all feature flag tables.
     *
     * Drops tables in reverse order to avoid foreign key constraint issues.
     * This removes all feature flags, groups, membership data, and snapshots.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('toggl.table_names.feature_snapshot_events', 'feature_snapshot_events'));
        Schema::dropIfExists(config('toggl.table_names.feature_snapshot_entries', 'feature_snapshot_entries'));
        Schema::dropIfExists(config('toggl.table_names.feature_snapshots', 'feature_snapshots'));
        Schema::dropIfExists(config('toggl.table_names.group_memberships', 'group_memberships'));
        Schema::dropIfExists(config('toggl.table_names.feature_groups', 'feature_groups'));
        Schema::dropIfExists(config('toggl.table_names.features', 'features'));
    }
};
