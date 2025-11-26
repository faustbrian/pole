<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Migrators\YLSIdeasMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Exceptions\ExpectedExceptionNotThrownException;

uses(RefreshDatabase::class);

/**
 * Set up the test database schema for YLSIdeas features table.
 *
 * Creates a YLSIdeas-compatible features table with a unique feature name column
 * and a nullable active_at timestamp that determines feature activation status.
 * The presence of an active_at timestamp indicates an active feature.
 */
beforeEach(function (): void {
    // Legacy table from YLSIdeas package - uses auto-increment ID
    Schema::create('ylsideas_features', function ($table): void {
        $table->id();
        $table->string('feature')->unique();
        $table->timestamp('active_at')->nullable();
        $table->timestamps();
    });
});

/**
 * Test suite for YLSIdeasMigrator functionality.
 *
 * Validates migration of feature flags from the YLSIdeas feature flag package's
 * database format to Toggl's driver system. Tests cover timestamp-based activation
 * states, error handling, and custom table/field name configuration. Features
 * are considered active when active_at is non-null, inactive when null.
 */
describe('YLSIdeasMigrator', function (): void {
    describe('Happy Path', function (): void {
        test('migrates active features from YLSIdeas database to Toggl driver', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-2', 'active_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-2', false);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(2);
            expect($stats['contexts'])->toBe(2);
            expect($stats['errors'])->toBeEmpty();
        });

        test('migrates all inactive features', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-2', 'active_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-3', 'active_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->times(3)->with(Mockery::type('string'), false);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(3);
            expect($stats['contexts'])->toBe(3);
            expect($stats['errors'])->toBeEmpty();
        });

        test('migrates all active features', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-2', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-3', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->times(3)->with(Mockery::type('string'), true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(3);
            expect($stats['contexts'])->toBe(3);
            expect($stats['errors'])->toBeEmpty();
        });
    });

    describe('Sad Path', function (): void {
        test('handles empty database gracefully', function (): void {
            // Arrange
            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->never();

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(0);
            expect($stats['contexts'])->toBe(0);
            expect($stats['errors'])->toBeEmpty();
        });

        test('records errors for failed feature migrations', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-2', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true)->andThrow(
                new RuntimeException('Database error'),
            );
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-2', true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(1);
            expect($stats['contexts'])->toBe(1);
            expect($stats['errors'])->toHaveCount(1);
            expect($stats['errors'][0])->toContain('feature-1');
            expect($stats['errors'][0])->toContain('Database error');
        });

        test('continues migration after encountering errors', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-2', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-3', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-2', true)->andThrow(
                new RuntimeException('Error'),
            );
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-3', true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(2);
            expect($stats['contexts'])->toBe(2);
            expect($stats['errors'])->toHaveCount(1);
        });

        test('throws exception and records error when database fetch fails', function (): void {
            DB::beginTransaction();

            try {
                // Arrange
                $driver = Mockery::mock(Driver::class);
                $driver->shouldReceive('setForAllContexts')->never();

                $migrator = new YLSIdeasMigrator(
                    $driver,
                    'nonexistent_table',
                );

                // Act & Assert
                try {
                    $migrator->migrate();

                    throw ExpectedExceptionNotThrownException::inTest();
                } catch (Throwable $throwable) {
                    expect($throwable)->toBeInstanceOf(Throwable::class);
                }

                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe(0);
                expect($stats['contexts'])->toBe(0);
                expect($stats['errors'])->toHaveCount(1);
                expect($stats['errors'][0])->toContain('Migration failed:');
            } finally {
                DB::rollBack();
            }
        });
    });

    describe('Edge Cases', function (): void {
        test('handles custom table name', function (): void {
            // Arrange
            Schema::create('custom_ylsideas_table', function ($table): void {
                $table->id();
                $table->string('feature')->unique();
                $table->timestamp('active_at')->nullable();
                $table->timestamps();
            });

            DB::table('custom_ylsideas_table')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'custom_ylsideas_table',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(1);
        });

        test('handles custom field name', function (): void {
            // Arrange
            Schema::create('custom_field_table', function ($table): void {
                $table->id();
                $table->string('feature')->unique();
                $table->timestamp('enabled_at')->nullable();
                $table->timestamps();
            });

            DB::table('custom_field_table')->insert([
                ['feature' => 'feature-1', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'custom_field_table',
                'enabled_at',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(1);
        });

        test('handles custom database connection', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(1);
        });
    });
});
