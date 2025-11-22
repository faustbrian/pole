<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create users table with ULID/UUID support for Toggl tests
        if (!Schema::hasTable('users')) {
            Schema::create('users', function ($table): void {
                $keyType = config('toggl.primary_key_type', 'id');

                match ($keyType) {
                    'ulid' => $table->ulid('id')->primary(),
                    'uuid' => $table->uuid('id')->primary(),
                    default => $table->id(),
                };

                $table->string('name');
                $table->string('email')->unique();
                $table->integer('company_id')->nullable();
                $table->integer('division_id')->nullable();
                $table->integer('org_id')->nullable();
                $table->integer('team_id')->nullable();
            });
        }

        // Create organizations table with ULID/UUID support for Toggl tests
        if (!Schema::hasTable('organizations')) {
            Schema::create('organizations', function ($table): void {
                $keyType = config('toggl.primary_key_type', 'id');

                match ($keyType) {
                    'ulid' => $table->ulid('id')->primary(),
                    'uuid' => $table->uuid('id')->primary(),
                    default => $table->id(),
                };

                $table->string('name');
                $table->string('ulid')->nullable();
            });
        }

        // Create uuid_models table for UUID primary key testing
        if (!Schema::hasTable('uuid_models')) {
            Schema::create('uuid_models', function ($table): void {
                $table->uuid('id')->primary();
                $table->string('name');
            });
        }

        // Create ulid_models table for ULID primary key testing
        if (!Schema::hasTable('ulid_models')) {
            Schema::create('ulid_models', function ($table): void {
                $table->ulid('id')->primary();
                $table->string('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ulid_models');
        Schema::dropIfExists('uuid_models');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('users');
    }
};
