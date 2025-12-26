<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Conductors\AuditConductor;
use Cline\Toggl\FeatureManager;
use Cline\Toggl\Toggl;
use Tests\Fixtures\User;

/**
 * Audit Conductor Test Suite
 *
 * Tests tracking feature state changes with audit history.
 */
describe('Audit Conductor', function (): void {
    describe('Basic Auditing', function (): void {
        test('logs activation', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::audit('premium')
                ->activate()
                ->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history)->toHaveCount(1);
            expect($history[0]['action'])->toBe('activate');
            expect($history[0])->toHaveKey('timestamp');
        });

        test('logs deactivation', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');

            // Act
            Toggl::audit('premium')
                ->deactivate()
                ->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history)->toHaveCount(1);
            expect($history[0]['action'])->toBe('deactivate');
        });

        test('logs multiple actions', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::audit('premium')->activate()->for($user);
            Toggl::audit('premium')->deactivate()->for($user);
            Toggl::audit('premium')->activate()->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history)->toHaveCount(3);
            expect($history[0]['action'])->toBe('activate');
            expect($history[1]['action'])->toBe('deactivate');
            expect($history[2]['action'])->toBe('activate');
        });

        test('actually performs activation', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::audit('premium')->activate()->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });

        test('actually performs deactivation', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::for($user)->activate('premium');

            // Act
            Toggl::audit('premium')->deactivate()->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
        });
    });

    describe('Audit with Metadata', function (): void {
        test('logs with reason', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::audit('premium')
                ->activate()
                ->withReason('Subscription upgraded')
                ->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history[0]['reason'])->toBe('Subscription upgraded');
        });

        test('logs with actor', function (): void {
            // Arrange
            $user = User::factory()->create();
            $admin = User::factory()->create();

            // Act
            Toggl::audit('premium')
                ->activate()
                ->withActor($admin)
                ->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history[0]['actor'])->toBe($admin->id);
        });

        test('logs with both reason and actor', function (): void {
            // Arrange
            $user = User::factory()->create();
            $admin = User::factory()->create();

            // Act
            Toggl::audit('premium')
                ->activate()
                ->withReason('Admin override')
                ->withActor($admin)
                ->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history[0]['reason'])->toBe('Admin override');
            expect($history[0]['actor'])->toBe($admin->id);
        });

        test('reason is optional', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::audit('premium')
                ->activate()
                ->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history[0])->not->toHaveKey('reason');
        });

        test('actor is optional', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::audit('premium')
                ->activate()
                ->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history[0])->not->toHaveKey('actor');
        });
    });

    describe('Audit History', function (): void {
        test('returns empty array when no history', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            $history = Toggl::audit('premium')->history($user);

            // Assert
            expect($history)->toBe([]);
        });

        test('maintains chronological order', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::audit('premium')
                ->activate()
                ->withReason('First')
                ->for($user);

            Toggl::audit('premium')
                ->deactivate()
                ->withReason('Second')
                ->for($user);

            Toggl::audit('premium')
                ->activate()
                ->withReason('Third')
                ->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history[0]['reason'])->toBe('First');
            expect($history[1]['reason'])->toBe('Second');
            expect($history[2]['reason'])->toBe('Third');
        });

        test('different features have separate history', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::audit('premium')->activate()->for($user);
            Toggl::audit('analytics')->activate()->for($user);

            // Assert
            $premiumHistory = Toggl::audit('premium')->history($user);
            $analyticsHistory = Toggl::audit('analytics')->history($user);

            expect($premiumHistory)->toHaveCount(1);
            expect($analyticsHistory)->toHaveCount(1);
        });

        test('clears history', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::audit('premium')->activate()->for($user);
            Toggl::audit('premium')->deactivate()->for($user);

            // Act
            Toggl::audit('premium')->clearHistory($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history)->toBe([]);
        });

        test('clearing history does not affect feature state', function (): void {
            // Arrange
            $user = User::factory()->create();
            Toggl::audit('premium')->activate()->for($user);

            // Act
            Toggl::audit('premium')->clearHistory($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeTrue();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('subscription upgrade audit trail', function (): void {
            // Arrange
            $user = User::factory()->create();
            $admin = User::factory()->create();

            // Act - Trial start
            Toggl::audit('trial')
                ->activate()
                ->withReason('New user trial')
                ->for($user);

            // Trial conversion
            Toggl::audit('trial')
                ->deactivate()
                ->withReason('Converted to paid')
                ->for($user);

            Toggl::audit('premium')
                ->activate()
                ->withReason('Trial conversion')
                ->for($user);

            // Admin upgrade
            Toggl::audit('premium')
                ->deactivate()
                ->withReason('Upgrading to enterprise')
                ->withActor($admin)
                ->for($user);

            Toggl::audit('enterprise')
                ->activate()
                ->withReason('Admin upgrade')
                ->withActor($admin)
                ->for($user);

            // Assert
            $trialHistory = Toggl::audit('trial')->history($user);
            $premiumHistory = Toggl::audit('premium')->history($user);
            $enterpriseHistory = Toggl::audit('enterprise')->history($user);

            expect($trialHistory)->toHaveCount(2);
            expect($premiumHistory)->toHaveCount(2);
            expect($enterpriseHistory)->toHaveCount(1);
            expect($enterpriseHistory[0]['actor'])->toBe($admin->id);
        });

        test('beta enrollment tracking', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::audit('beta-ui')
                ->activate()
                ->withReason('User opted into beta program')
                ->for($user);

            Toggl::audit('beta-api')
                ->activate()
                ->withReason('Beta API access granted')
                ->for($user);

            // Assert
            $betaUiHistory = Toggl::audit('beta-ui')->history($user);
            $betaApiHistory = Toggl::audit('beta-api')->history($user);

            expect($betaUiHistory[0]['reason'])->toBe('User opted into beta program');
            expect($betaApiHistory[0]['reason'])->toBe('Beta API access granted');
        });

        test('compliance audit trail', function (): void {
            // Arrange
            $user = User::factory()->create();
            $admin = User::factory()->create();

            // Act - Feature disabled for compliance
            Toggl::audit('data-export')
                ->deactivate()
                ->withReason('GDPR compliance - data retention policy')
                ->withActor($admin)
                ->for($user);

            // Assert
            $history = Toggl::audit('data-export')->history($user);
            expect($history[0]['action'])->toBe('deactivate');
            expect($history[0]['reason'])->toContain('GDPR compliance');
            expect($history[0]['actor'])->toBe($admin->id);
            expect($history[0])->toHaveKey('timestamp');
        });
    });

    describe('Edge Cases', function (): void {
        test('conductor exposes feature name', function (): void {
            // Arrange & Act
            $conductor = Toggl::audit('premium');

            // Assert
            expect($conductor->feature())->toBe('premium');
        });

        test('conductor exposes action', function (): void {
            // Arrange & Act
            $conductor = Toggl::audit('premium')->activate();

            // Assert
            expect($conductor->action())->toBe('activate');
        });

        test('conductor exposes reason', function (): void {
            // Arrange & Act
            $conductor = Toggl::audit('premium')
                ->activate()
                ->withReason('Test reason');

            // Assert
            expect($conductor->reason())->toBe('Test reason');
        });

        test('conductor exposes actor', function (): void {
            // Arrange
            $admin = User::factory()->create();

            // Act
            $conductor = Toggl::audit('premium')
                ->activate()
                ->withActor($admin);

            // Assert
            expect($conductor->actor())->toBe($admin);
        });

        test('no action does nothing', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act - No activate() or deactivate() called
            Toggl::audit('premium')->for($user);

            // Assert
            expect(Toggl::for($user)->active('premium'))->toBeFalse();
            $history = Toggl::audit('premium')->history($user);
            expect($history)->toBe([]);
        });

        test('actor with getKey method', function (): void {
            // Arrange
            $user = User::factory()->create();
            $admin = User::factory()->create();

            // Act
            Toggl::audit('premium')
                ->activate()
                ->withActor($admin)
                ->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history[0]['actor'])->toBe($admin->getKey());
        });

        test('actor as scalar value', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::audit('premium')
                ->activate()
                ->withActor('system')
                ->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history[0]['actor'])->toBe('system');
        });

        test('multiple audits for same feature append to history', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Act
            Toggl::audit('premium')->activate()->withReason('First')->for($user);
            Toggl::audit('premium')->deactivate()->withReason('Second')->for($user);
            Toggl::audit('premium')->activate()->withReason('Third')->for($user);

            // Assert
            $history = Toggl::audit('premium')->history($user);
            expect($history)->toHaveCount(3);
        });

        test('audit records actor with id property', function (): void {
            // Arrange
            $user = User::factory()->create();
            $actor = new class()
            {
                public $id = 999;
            };

            // Act
            Toggl::audit('test')
                ->withActor($actor)
                ->activate()
                ->for($user);

            // Assert
            $history = Toggl::audit('test')->history($user);
            expect($history[0]['actor'])->toBe(999);
        });

        test('audit records actor with __toString method', function (): void {
            // Arrange
            $user = User::factory()->create();
            $actor = new class() implements Stringable
            {
                public function __toString(): string
                {
                    return 'admin-user';
                }
            };

            // Act
            Toggl::audit('test')
                ->withActor($actor)
                ->activate()
                ->for($user);

            // Assert
            $history = Toggl::audit('test')->history($user);
            expect($history[0]['actor'])->toBe('admin-user');
        });

        test('for handles unknown action type gracefully', function (): void {
            // Arrange
            $user = User::factory()->create();

            // Create conductor with invalid action type using reflection
            $conductor = new AuditConductor(
                resolve(FeatureManager::class),
                'test-feature',
                'invalid-action',  // Not 'activate' or 'deactivate'
            );

            // Act - Should not throw exception, default case returns null
            $conductor->for($user);

            // Assert - History should still be recorded despite invalid action
            $history = Toggl::audit('test-feature')->history($user);
            expect($history)->toHaveCount(1);
            expect($history[0]['action'])->toBe('invalid-action');
        });

        test('actor with non-string/int getKey gets cast to string', function (): void {
            // Arrange
            $user = User::factory()->create();
            $actor = new class()
            {
                public function getKey(): float
                {
                    return 123.45;
                }
            };

            // Act
            Toggl::audit('test')
                ->withActor($actor)
                ->activate()
                ->for($user);

            // Assert
            $history = Toggl::audit('test')->history($user);
            expect($history[0]['actor'])->toBe('123.45');
        });

        test('actor with non-string/int id property gets cast to string', function (): void {
            // Arrange
            $user = User::factory()->create();
            $actor = new class()
            {
                public $id = 123.45;
            };

            // Act
            Toggl::audit('test')
                ->withActor($actor)
                ->activate()
                ->for($user);

            // Assert
            $history = Toggl::audit('test')->history($user);
            expect($history[0]['actor'])->toBe('123.45');
        });

        test('actor as object without id or __toString returns object', function (): void {
            // Arrange
            $user = User::factory()->create();
            $actor = new class() {};

            // Act
            Toggl::audit('test')
                ->withActor($actor)
                ->activate()
                ->for($user);

            // Assert
            $history = Toggl::audit('test')->history($user);
            expect($history[0]['actor'])->toBe('object');
        });

        test('actor as resource type returns unknown', function (): void {
            // Arrange
            $user = User::factory()->create();
            $handle = fopen('php://memory', 'rb');

            // Act
            Toggl::audit('test')
                ->withActor($handle)
                ->activate()
                ->for($user);
            fclose($handle);

            // Assert
            $history = Toggl::audit('test')->history($user);
            expect($history[0]['actor'])->toBe('unknown');
        });
    });
});
