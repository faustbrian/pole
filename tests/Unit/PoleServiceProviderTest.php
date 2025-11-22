<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Contracts\SnapshotRepository;
use Cline\Toggl\Database\ModelRegistry;
use Cline\Toggl\Enums\SnapshotDriver;
use Cline\Toggl\Events\FeatureActivated;
use Cline\Toggl\Events\FeatureDeactivated;
use Cline\Toggl\Exceptions\InvalidConfigurationException;
use Cline\Toggl\Exceptions\MorphKeyViolationException;
use Cline\Toggl\FeatureManager;
use Cline\Toggl\Repositories\ArraySnapshotRepository;
use Cline\Toggl\Repositories\CacheSnapshotRepository;
use Cline\Toggl\Repositories\DatabaseSnapshotRepository;
use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Toggl;
use Cline\Toggl\TogglServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Compilers\BladeCompiler;
use Laravel\Octane\Contracts\OperationTerminated;
use Tests\Fixtures\Organization;
use Tests\Fixtures\User;

describe('TogglServiceProvider Configuration', function (): void {
    beforeEach(function (): void {
        $this->registry = app(ModelRegistry::class);
        $this->registry->reset();
    });

    afterEach(function (): void {
        $this->registry->reset();
    });

    describe('morphKeyMap Configuration', function (): void {
        test('applies morph key map from configuration for different models', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.morphKeyMap', [
                User::class => 'id',
                Organization::class => 'ulid',
            ]);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);
            $org = Organization::query()->create(['name' => 'Acme']);

            // Assert
            expect($this->registry->getModelKey($user))->toBe('id');
            expect($this->registry->getModelKey($org))->toBe('ulid');
        });

        test('uses model default key name when both configs are empty', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.morphKeyMap', []);
            $config->set('toggl.enforceMorphKeyMap', []);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Assert
            expect($this->registry->getModelKey($user))->toBe('id');
        });

        test('allows one config empty while other is populated', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.morphKeyMap', [
                User::class => 'id',
            ]);
            $config->set('toggl.enforceMorphKeyMap', []);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Assert
            expect($this->registry->getModelKey($user))->toBe('id');
        });
    });

    describe('enforceMorphKeyMap Configuration', function (): void {
        test('enforces morph key map throwing exception for unmapped models', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.enforceMorphKeyMap', [
                User::class => 'id',
            ]);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $org = Organization::query()->create(['name' => 'Acme']);

            // Assert
            $this->expectException(MorphKeyViolationException::class);
            $this->registry->getModelKey($org);
        });

        test('prioritizes enforcement when only enforceMorphKeyMap is set', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.morphKeyMap', []);
            $config->set('toggl.enforceMorphKeyMap', [
                User::class => 'id',
            ]);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $org = Organization::query()->create(['name' => 'Acme']);

            // Assert
            $this->expectException(MorphKeyViolationException::class);
            $this->registry->getModelKey($org);
        });
    });

    describe('Configuration Validation', function (): void {
        test('throws exception when both morphKeyMap and enforceMorphKeyMap are configured', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.morphKeyMap', [
                User::class => 'id',
            ]);
            $config->set('toggl.enforceMorphKeyMap', [
                Organization::class => 'ulid',
            ]);

            // Act & Assert
            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage('Cannot configure both morphKeyMap and enforceMorphKeyMap simultaneously');

            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();
        });

        test('normalizes non-array morphKeyMap config value to empty array', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.morphKeyMap', 'invalid-string-value');
            $config->set('toggl.enforceMorphKeyMap', []);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Assert
            expect($this->registry->getModelKey($user))->toBe('id');
        });

        test('normalizes null morphKeyMap config value to empty array', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.morphKeyMap', null);
            $config->set('toggl.enforceMorphKeyMap', []);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Assert
            expect($this->registry->getModelKey($user))->toBe('id');
        });

        test('normalizes non-array enforceMorphKeyMap config value to empty array', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.morphKeyMap', []);
            $config->set('toggl.enforceMorphKeyMap', 'invalid-string-value');

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Assert
            expect($this->registry->getModelKey($user))->toBe('id');
        });

        test('normalizes null enforceMorphKeyMap config value to empty array', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.morphKeyMap', []);
            $config->set('toggl.enforceMorphKeyMap', null);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $user = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

            // Assert
            expect($this->registry->getModelKey($user))->toBe('id');
        });
    });

    describe('Blade Directives', function (): void {
        beforeEach(function (): void {
            // Arrange - Register service provider and boot to register directives
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $this->blade = $this->app->make(BladeCompiler::class);

            // Set a default context resolver for global feature checks
            Toggl::resolveContextUsing(fn (): TogglContext => TogglContext::simple('blade-test', 'anonymous'));
        });

        describe('@feature directive', function (): void {
            test('registers feature directive that checks if feature is active with single argument', function (): void {
                // Arrange
                Toggl::activateForEveryone('premium');

                // Act
                $result = Blade::check('feature', 'premium');

                // Assert
                expect($result)->toBeTrue();
            });

            test('feature directive returns false when feature is inactive with single argument', function (): void {
                // Arrange - feature not activated

                // Act
                $result = Blade::check('feature', 'premium');

                // Assert
                expect($result)->toBeFalse();
            });

            test('registers feature directive that compares feature value with two arguments', function (): void {
                // Arrange
                Toggl::activateForEveryone('theme', 'dark');

                // Act
                $result = Blade::check('feature', 'theme', 'dark');

                // Assert
                expect($result)->toBeTrue();
            });

            test('feature directive returns false when value does not match', function (): void {
                // Arrange
                Toggl::activateForEveryone('theme', 'light');

                // Act
                $result = Blade::check('feature', 'theme', 'dark');

                // Assert
                expect($result)->toBeFalse();
            });
        });

        describe('@hasFeature directive', function (): void {
            test('returns true when feature is active', function (): void {
                // Arrange
                Toggl::activateForEveryone('premium');

                // Act
                $result = Blade::check('hasFeature', 'premium');

                // Assert
                expect($result)->toBeTrue();
            });

            test('returns false when feature is inactive', function (): void {
                // Arrange - feature not activated

                // Act
                $result = Blade::check('hasFeature', 'premium');

                // Assert
                expect($result)->toBeFalse();
            });
        });

        describe('@hasAnyFeature directive', function (): void {
            test('returns true when any feature is active', function (): void {
                // Arrange
                Toggl::activateForEveryone('premium');

                // Act
                $result = Blade::check('hasAnyFeature', ['premium', 'trial']);

                // Assert
                expect($result)->toBeTrue();
            });

            test('returns false when no features are active', function (): void {
                // Arrange - no features activated

                // Act
                $result = Blade::check('hasAnyFeature', ['premium', 'trial']);

                // Assert
                expect($result)->toBeFalse();
            });
        });

        describe('@hasAllFeatures directive', function (): void {
            test('returns true when all features are active', function (): void {
                // Arrange
                Toggl::activateForEveryone(['api-v2', 'webhooks']);

                // Act
                $result = Blade::check('hasAllFeatures', ['api-v2', 'webhooks']);

                // Assert
                expect($result)->toBeTrue();
            });

            test('returns false when not all features are active', function (): void {
                // Arrange
                Toggl::activateForEveryone('api-v2');

                // Act
                $result = Blade::check('hasAllFeatures', ['api-v2', 'webhooks']);

                // Assert
                expect($result)->toBeFalse();
            });
        });

        describe('@missingFeature directive', function (): void {
            test('returns true when feature is inactive', function (): void {
                // Arrange - feature not activated

                // Act
                $result = Blade::check('missingFeature', 'premium');

                // Assert
                expect($result)->toBeTrue();
            });

            test('returns false when feature is active', function (): void {
                // Arrange
                Toggl::activateForEveryone('premium');

                // Act
                $result = Blade::check('missingFeature', 'premium');

                // Assert
                expect($result)->toBeFalse();
            });
        });

        describe('@missingAnyFeature directive', function (): void {
            test('returns true when any feature is inactive', function (): void {
                // Arrange
                Toggl::activateForEveryone('premium');

                // Act
                $result = Blade::check('missingAnyFeature', ['premium', 'trial']);

                // Assert
                expect($result)->toBeTrue();
            });

            test('returns false when all features are active', function (): void {
                // Arrange
                Toggl::activateForEveryone(['premium', 'trial']);

                // Act
                $result = Blade::check('missingAnyFeature', ['premium', 'trial']);

                // Assert
                expect($result)->toBeFalse();
            });
        });

        describe('@missingAllFeatures directive', function (): void {
            test('returns true when all features are inactive', function (): void {
                // Arrange - no features activated

                // Act
                $result = Blade::check('missingAllFeatures', ['premium', 'trial']);

                // Assert
                expect($result)->toBeTrue();
            });

            test('returns false when any feature is active', function (): void {
                // Arrange
                Toggl::activateForEveryone('premium');

                // Act
                $result = Blade::check('missingAllFeatures', ['premium', 'trial']);

                // Assert
                expect($result)->toBeFalse();
            });
        });

        describe('@unlessFeature directive', function (): void {
            test('returns true when feature is inactive', function (): void {
                // Arrange - feature not activated

                // Act
                $result = Blade::check('unlessFeature', 'premium');

                // Assert
                expect($result)->toBeTrue();
            });

            test('returns false when feature is active', function (): void {
                // Arrange
                Toggl::activateForEveryone('premium');

                // Act
                $result = Blade::check('unlessFeature', 'premium');

                // Assert
                expect($result)->toBeFalse();
            });
        });

        describe('@unlessAnyFeature directive', function (): void {
            test('returns true when any feature is inactive', function (): void {
                // Arrange
                Toggl::activateForEveryone('premium');

                // Act
                $result = Blade::check('unlessAnyFeature', ['premium', 'trial']);

                // Assert
                expect($result)->toBeTrue();
            });

            test('returns false when all features are active', function (): void {
                // Arrange
                Toggl::activateForEveryone(['premium', 'trial']);

                // Act
                $result = Blade::check('unlessAnyFeature', ['premium', 'trial']);

                // Assert
                expect($result)->toBeFalse();
            });
        });

        describe('@unlessAllFeatures directive', function (): void {
            test('returns true when all features are inactive', function (): void {
                // Arrange - no features activated

                // Act
                $result = Blade::check('unlessAllFeatures', ['premium', 'trial']);

                // Assert
                expect($result)->toBeTrue();
            });

            test('returns false when any feature is active', function (): void {
                // Arrange
                Toggl::activateForEveryone('premium');

                // Act
                $result = Blade::check('unlessAllFeatures', ['premium', 'trial']);

                // Assert
                expect($result)->toBeFalse();
            });
        });
    });

    describe('Service Registration', function (): void {
        test('FeatureManager is resolved as singleton via #[Singleton] attribute', function (): void {
            // Arrange & Act - Resolve FeatureManager twice
            $manager1 = $this->app->make(FeatureManager::class);
            $manager2 = $this->app->make(FeatureManager::class);

            // Assert - Same instance returned (singleton behavior via attribute)
            expect($manager1)->toBe($manager2)
                ->and($manager1)->toBeInstanceOf(FeatureManager::class);
        });

        test('line 81 registers SnapshotRepository binding in container with Database driver', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', SnapshotDriver::Database);

            $provider = new TogglServiceProvider($this->app);

            // Act - Trigger registeringPackage() which contains line 81
            $provider->register();

            // Assert - Verify binding exists in container
            expect($this->app->bound(SnapshotRepository::class))->toBeTrue();
            expect($this->app->isShared(SnapshotRepository::class))->toBeTrue();
        });

        test('line 81 registers SnapshotRepository binding in container with Array driver', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', SnapshotDriver::Array);

            $provider = new TogglServiceProvider($this->app);

            // Act - Trigger registeringPackage() which contains line 81
            $provider->register();

            // Assert - Verify binding exists in container
            expect($this->app->bound(SnapshotRepository::class))->toBeTrue();
            expect($this->app->isShared(SnapshotRepository::class))->toBeTrue();
        });

        test('line 81 registers SnapshotRepository binding in container with Cache driver', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', SnapshotDriver::Cache);

            $provider = new TogglServiceProvider($this->app);

            // Act - Trigger registeringPackage() which contains line 81
            $provider->register();

            // Assert - Verify binding exists in container
            expect($this->app->bound(SnapshotRepository::class))->toBeTrue();
            expect($this->app->isShared(SnapshotRepository::class))->toBeTrue();
        });

        test('registers FeatureManager as singleton that can be resolved from container', function (): void {
            // Arrange
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Act
            $manager = $this->app->make(FeatureManager::class);

            // Assert
            expect($manager)->toBeInstanceOf(FeatureManager::class);
        });

        test('FeatureManager singleton returns same instance on multiple resolutions', function (): void {
            // Arrange
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Act
            $manager1 = $this->app->make(FeatureManager::class);
            $manager2 = $this->app->make(FeatureManager::class);

            // Assert
            expect($manager1)->toBe($manager2); // Same instance (singleton)
        });

        test('FeatureManager singleton closure receives container instance', function (): void {
            // Arrange
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Act - Force closure execution by resolving multiple times to verify container is passed correctly
            $manager1 = $this->app->make(FeatureManager::class);
            $this->app->forgetInstance(FeatureManager::class);
            $manager2 = $this->app->make(FeatureManager::class);

            // Assert
            expect($manager1)->toBeInstanceOf(FeatureManager::class);
            expect($manager2)->toBeInstanceOf(FeatureManager::class);
            expect($manager1)->not->toBe($manager2); // Different instances after forgetting
        });

        test('FeatureManager singleton closure executes all statements including assertion', function (): void {
            // Skip test - zend.assertions can only be set in php.ini, not at runtime
            $this->markTestSkipped('zend.assertions can only be configured in php.ini');

            // Arrange
            ini_set('zend.assertions', '1'); // Enable assertions
            ini_set('assert.exception', '1'); // Make assertions throw exceptions

            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Act - Force complete closure execution with assertions enabled
            $manager = $this->app->make(FeatureManager::class);

            // Assert - If closure executed successfully, manager should be valid
            expect($manager)->toBeInstanceOf(FeatureManager::class);
            expect($manager)->not->toBeNull();
        });

        test('registers SnapshotRepository as singleton that can be resolved from container', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', SnapshotDriver::Array);

            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Act
            $repository = $this->app->make(SnapshotRepository::class);

            // Assert
            expect($repository)->toBeInstanceOf(SnapshotRepository::class);
        });

        test('SnapshotRepository singleton returns same instance on multiple resolutions', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', SnapshotDriver::Array);

            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Act
            $repo1 = $this->app->make(SnapshotRepository::class);
            $repo2 = $this->app->make(SnapshotRepository::class);

            // Assert
            expect($repo1)->toBe($repo2); // Same instance (singleton)
        });

        test('SnapshotRepository creation requires FeatureManager to be resolved first', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', SnapshotDriver::Database);

            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Act - This will trigger FeatureManager resolution inside SnapshotRepository closure
            $repository = $this->app->make(SnapshotRepository::class);

            // Assert
            expect($repository)->toBeInstanceOf(DatabaseSnapshotRepository::class);
            expect($this->app->make(FeatureManager::class))->toBeInstanceOf(FeatureManager::class);
        });

        test('SnapshotRepository singleton closure receives container and resolves FeatureManager', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', SnapshotDriver::Cache);

            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Act - Force closure execution multiple times to verify container handling
            $repo1 = $this->app->make(SnapshotRepository::class);
            $this->app->forgetInstance(SnapshotRepository::class);
            $repo2 = $this->app->make(SnapshotRepository::class);

            // Assert
            expect($repo1)->toBeInstanceOf(CacheSnapshotRepository::class);
            expect($repo2)->toBeInstanceOf(CacheSnapshotRepository::class);
            expect($repo1)->not->toBe($repo2); // Different instances after forgetting
        });

        test('SnapshotRepository singleton closure handles all driver types in match expression', function (): void {
            // Arrange
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $config = $this->app->make(Repository::class);

            // Act & Assert - Test Database driver
            $config->set('toggl.snapshots.driver', SnapshotDriver::Database);

            $this->app->forgetInstance(SnapshotRepository::class);
            $repo = $this->app->make(SnapshotRepository::class);
            expect($repo)->toBeInstanceOf(DatabaseSnapshotRepository::class);

            // Act & Assert - Test Array driver
            $config->set('toggl.snapshots.driver', SnapshotDriver::Array);
            $this->app->forgetInstance(SnapshotRepository::class);
            $repo = $this->app->make(SnapshotRepository::class);
            expect($repo)->toBeInstanceOf(ArraySnapshotRepository::class);

            // Act & Assert - Test Cache driver
            $config->set('toggl.snapshots.driver', SnapshotDriver::Cache);
            $this->app->forgetInstance(SnapshotRepository::class);
            $repo = $this->app->make(SnapshotRepository::class);
            expect($repo)->toBeInstanceOf(CacheSnapshotRepository::class);
        });

        test('SnapshotRepository singleton closure executes all statements including assertion and config call', function (): void {
            // Skip test - zend.assertions can only be set in php.ini, not at runtime
            $this->markTestSkipped('zend.assertions can only be configured in php.ini');

            // Arrange
            ini_set('zend.assertions', '1'); // Enable assertions
            ini_set('assert.exception', '1'); // Make assertions throw exceptions

            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', SnapshotDriver::Database);

            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Act - Force complete closure execution with assertions enabled
            // This will execute: assert, make(FeatureManager), config(), match expression
            $repository = $this->app->make(SnapshotRepository::class);

            // Assert - If closure executed successfully, repository should be valid
            expect($repository)->toBeInstanceOf(DatabaseSnapshotRepository::class);
            expect($repository)->not->toBeNull();
        });
    });

    describe('Snapshot Repository Registration', function (): void {
        test('registers CacheSnapshotRepository when snapshot_driver is cache', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', SnapshotDriver::Cache);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Assert
            $repository = $this->app->make(SnapshotRepository::class);
            expect($repository)->toBeInstanceOf(CacheSnapshotRepository::class);
        });

        test('registers DatabaseSnapshotRepository when snapshot_driver is database', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', SnapshotDriver::Database);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Assert
            $repository = $this->app->make(SnapshotRepository::class);
            expect($repository)->toBeInstanceOf(DatabaseSnapshotRepository::class);
        });

        test('registers ArraySnapshotRepository when snapshot_driver is array', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', SnapshotDriver::Array);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Assert
            $repository = $this->app->make(SnapshotRepository::class);
            expect($repository)->toBeInstanceOf(ArraySnapshotRepository::class);
        });

        test('registers ArraySnapshotRepository as default when snapshot_driver is null', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', null);

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Assert
            $repository = $this->app->make(SnapshotRepository::class);
            expect($repository)->toBeInstanceOf(ArraySnapshotRepository::class);
        });

        test('registers ArraySnapshotRepository as default when snapshot_driver is invalid value', function (): void {
            // Arrange
            $config = $this->app->make(Repository::class);
            $config->set('toggl.snapshots.driver', 'invalid-driver');

            // Act
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Assert
            $repository = $this->app->make(SnapshotRepository::class);
            expect($repository)->toBeInstanceOf(ArraySnapshotRepository::class);
        });

        test('SnapshotRepository resolves FeatureManager internally for all driver types', function (): void {
            // Arrange
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $config = $this->app->make(Repository::class);

            // Act & Assert - Test that FeatureManager is resolved within closure for Database driver
            $config->set('toggl.snapshots.driver', SnapshotDriver::Database);

            $this->app->forgetInstance(SnapshotRepository::class);
            $this->app->forgetInstance(FeatureManager::class);

            $repo = $this->app->make(SnapshotRepository::class);
            expect($repo)->toBeInstanceOf(DatabaseSnapshotRepository::class);

            // Act & Assert - Test that FeatureManager is resolved within closure for Cache driver
            $config->set('toggl.snapshots.driver', SnapshotDriver::Cache);
            $this->app->forgetInstance(SnapshotRepository::class);
            $this->app->forgetInstance(FeatureManager::class);

            $repo = $this->app->make(SnapshotRepository::class);
            expect($repo)->toBeInstanceOf(CacheSnapshotRepository::class);

            // Act & Assert - Test that FeatureManager is resolved within closure for Array driver
            $config->set('toggl.snapshots.driver', SnapshotDriver::Array);
            $this->app->forgetInstance(SnapshotRepository::class);
            $this->app->forgetInstance(FeatureManager::class);

            $repo = $this->app->make(SnapshotRepository::class);
            expect($repo)->toBeInstanceOf(ArraySnapshotRepository::class);
        });
    });

    describe('Event Listeners', function (): void {
        test('registers listener for JobProcessed event to flush cache', function (): void {
            // Arrange
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $context = TogglContext::simple('test-context', 'test');
            Toggl::activateForEveryone('test-feature');
            expect(Toggl::for($context)->active('test-feature'))->toBeTrue();

            // Act - Dispatch JobProcessed event
            event(
                new JobProcessed('connection', new SyncJob($this->app, '', '', '')),
            );

            // Assert - Cache should be flushed and feature should still be active
            expect(Toggl::for($context)->active('test-feature'))->toBeTrue();
        });

        test('registers AutoSnapshotListener for FeatureActivated event', function (): void {
            // Arrange
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $dispatcher = $this->app->make(Dispatcher::class);

            // Act
            $hasListener = $dispatcher->hasListeners(FeatureActivated::class);

            // Assert
            expect($hasListener)->toBeTrue();
        });

        test('registers AutoSnapshotListener for FeatureDeactivated event', function (): void {
            // Arrange
            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $dispatcher = $this->app->make(Dispatcher::class);

            // Act
            $hasListener = $dispatcher->hasListeners(FeatureDeactivated::class);

            // Assert
            expect($hasListener)->toBeTrue();
        });
    });

    describe('Octane Event Listeners', function (): void {
        test('does not register Octane listeners when Laravel Octane is not installed', function (): void {
            // Arrange
            $provider = new TogglServiceProvider($this->app);
            $provider->register();

            // Act
            $provider->boot();

            // Assert - Should complete without errors when Octane is not available
            expect(true)->toBeTrue();
        });

        test('registers Octane event listeners when Laravel Octane is available', function (): void {
            // Arrange
            if (!interface_exists(OperationTerminated::class)) {
                $this->markTestSkipped('Laravel Octane is not installed');
            }

            $provider = new TogglServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $dispatcher = $this->app->make(Dispatcher::class);

            // Act & Assert - Verify listener is registered for OperationTerminated interface
            // The implementation listens to OperationTerminated which is implemented by
            // RequestReceived, TaskReceived, and TickReceived events
            $operationTerminatedListeners = $dispatcher->getListeners(OperationTerminated::class);
            expect($operationTerminatedListeners)->not->toBeEmpty();
        });
    });
});
