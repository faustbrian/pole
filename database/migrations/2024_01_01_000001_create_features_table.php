<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->string('strategy')->nullable()->comment('Strategy class name (boolean, time_based, percentage, etc.)');
            $table->timestamp('expires_at')->nullable()->comment('Time bomb expiration timestamp');
            $table->json('metadata')->nullable()->comment('Strategy-specific configuration (time ranges, percentage values, etc.)');
            $table->timestamps();

            $table->unique(['name', 'scope']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
