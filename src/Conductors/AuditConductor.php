<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use BackedEnum;
use Cline\Toggl\FeatureManager;

use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;
use function now;
use function property_exists;

/**
 * Conductor for auditing feature state changes with metadata tracking.
 *
 * Provides fluent API for logging feature activations and deactivations with
 * comprehensive metadata including reason, actor identity, and ISO 8601 timestamps.
 * Maintains immutable audit history stored alongside feature data, enabling
 * compliance tracking, debugging, and state change analysis.
 *
 * Audit entries are stored with the __audit__ prefix and include action type,
 * timestamp, optional reason, and optional actor identifier for full traceability
 * of feature lifecycle changes across all contexts.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class AuditConductor
{
    /**
     * Create a new audit conductor instance.
     *
     * @param FeatureManager    $manager Core feature manager instance providing access to storage and
     *                                   context resolution for executing audited feature operations
     * @param BackedEnum|string $feature Feature name to audit, accepting both string literals and
     *                                   type-safe BackedEnum instances for consistent feature identification
     * @param null|string       $action  Action type to record in audit log, either 'activate' or 'deactivate',
     *                                   remaining null until explicitly configured via activate() or deactivate()
     *                                   method calls in the fluent chain
     * @param null|string       $reason  Human-readable justification documenting why the state change occurred,
     *                                   supporting compliance requirements, post-incident analysis, and
     *                                   operational debugging of feature lifecycle decisions
     * @param mixed             $actor   Identity of entity initiating the change, accepting user models with
     *                                   getKey() method, plain objects with id property, numeric IDs, or string
     *                                   identifiers for comprehensive accountability tracking
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum $feature,
        private ?string $action = null,
        private ?string $reason = null,
        private mixed $actor = null,
    ) {}

    /**
     * Set the audit action to feature activation.
     *
     * Specifies that this audit entry will log a feature activation event,
     * creating an immutable record of when and why the feature was enabled.
     *
     * @return self New conductor instance configured for activation auditing
     */
    public function activate(): self
    {
        return new self($this->manager, $this->feature, 'activate', $this->reason, $this->actor);
    }

    /**
     * Set the audit action to feature deactivation.
     *
     * Specifies that this audit entry will log a feature deactivation event,
     * recording when and why the feature was disabled for compliance tracking.
     *
     * @return self New conductor instance configured for deactivation auditing
     */
    public function deactivate(): self
    {
        return new self($this->manager, $this->feature, 'deactivate', $this->reason, $this->actor);
    }

    /**
     * Attach a human-readable reason for the state change.
     *
     * Documents the business rationale, compliance requirement, or operational
     * need that motivated the feature state change. Useful for audit trails,
     * post-incident reviews, and understanding historical decisions.
     *
     * @param  string $reason Explanation for why the feature state is changing, stored
     *                        in the audit log for future reference and compliance tracking
     * @return self   New conductor instance with reason metadata attached
     */
    public function withReason(string $reason): self
    {
        return new self($this->manager, $this->feature, $this->action, $reason, $this->actor);
    }

    /**
     * Specify the entity performing the state change.
     *
     * Records who initiated the change for accountability and audit trails.
     * Accepts user models, numeric IDs, or string identifiers, automatically
     * extracting appropriate identifiers from objects via getKey() or id property.
     *
     * @param  mixed $actor User model, ID, or identifier of the entity making the change,
     *                      supporting Laravel models, plain objects, or scalar values
     * @return self  New conductor instance with actor metadata attached
     */
    public function withActor(mixed $actor): self
    {
        return new self($this->manager, $this->feature, $this->action, $this->reason, $actor);
    }

    /**
     * Execute audited state change for specified context.
     *
     * Terminal method performing the configured action (activation or deactivation) while
     * simultaneously creating an immutable audit log entry with ISO 8601 timestamp, action
     * type, optional reason, and optional actor identity. Audit entries are stored alongside
     * feature data using the __audit__ prefix, building chronological history for compliance,
     * debugging, and operational analysis.
     *
     * Returns silently without action if no operation type has been configured via activate()
     * or deactivate(), ensuring audit logs only contain intentional state changes.
     *
     * @param mixed $context Target context receiving the state change and audit record, such
     *                       as user models, organization entities, or any contextable object
     */
    public function for(mixed $context): void
    {
        if ($this->action === null) {
            return;
        }

        $contextedDriver = $this->manager->for($context);

        // Build audit entry
        $auditEntry = [
            'action' => $this->action,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($this->reason !== null) {
            $auditEntry['reason'] = $this->reason;
        }

        if ($this->actor !== null) {
            $auditEntry['actor'] = $this->getActorIdentifier($this->actor);
        }

        // Get existing audit history
        $featureKey = $this->feature instanceof BackedEnum ? $this->feature->value : $this->feature;
        $historyKey = '__audit__'.$featureKey;
        $history = $contextedDriver->value($historyKey);
        $history = is_array($history) ? $history : [];

        // Add new entry
        $history[] = $auditEntry;

        // Store updated history
        $contextedDriver->activate($historyKey, $history);

        // Perform the actual action
        match ($this->action) {
            'activate' => $contextedDriver->activate($this->feature),
            'deactivate' => $contextedDriver->deactivate($this->feature),
            default => null,
        };
    }

    /**
     * Retrieve the complete audit history for a specific context.
     *
     * Returns chronological array of all audit entries for this feature within
     * the given context, including timestamps, actions, reasons, and actors. Useful
     * for generating audit reports, debugging state changes, or compliance reviews.
     *
     * @param  mixed                            $context Target context to retrieve audit history from, such
     *                                                   as a user model or organization entity
     * @return array<int, array<string, mixed>> Array of audit entries ordered chronologically, each
     *                                          containing action, timestamp, and optional metadata
     */
    public function history(mixed $context): array
    {
        $contextedDriver = $this->manager->for($context);
        $featureKey = $this->feature instanceof BackedEnum ? $this->feature->value : $this->feature;
        $historyKey = '__audit__'.$featureKey;
        $history = $contextedDriver->value($historyKey);

        if (!is_array($history)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $history */
        return $history;
    }

    /**
     * Remove all audit history for a specific context.
     *
     * Permanently deletes the audit trail for this feature within the given context.
     * Use with caution as this removes compliance and debugging data. Consider
     * archiving before clearing if historical records are needed for regulatory purposes.
     *
     * @param mixed $context Target context to clear audit history from, removing all
     *                       historical state change records for this feature
     */
    public function clearHistory(mixed $context): void
    {
        $contextedDriver = $this->manager->for($context);
        $featureKey = $this->feature instanceof BackedEnum ? $this->feature->value : $this->feature;
        $historyKey = '__audit__'.$featureKey;
        $contextedDriver->deactivate($historyKey);
    }

    /**
     * Get the feature being audited.
     *
     * @return BackedEnum|string Feature name in its original format (string or BackedEnum)
     */
    public function feature(): string|BackedEnum
    {
        return $this->feature;
    }

    /**
     * Get the configured audit action type.
     *
     * @return null|string Action type ('activate' or 'deactivate'), or null if not yet set
     */
    public function action(): ?string
    {
        return $this->action;
    }

    /**
     * Get the configured change reason.
     *
     * @return null|string Human-readable reason for the change, or null if not provided
     */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * Get the configured actor entity.
     *
     * @return mixed Actor performing the change in its original format (model, ID, or string)
     */
    public function actor(): mixed
    {
        return $this->actor;
    }

    /**
     * Extract serializable identifier from actor entity for audit storage.
     *
     * Applies cascading detection strategies to derive meaningful actor identifiers:
     * first attempts Laravel model getKey() method for Eloquent compatibility, then
     * checks for id property on plain objects, falls back to __toString() for custom
     * stringifiable objects, and finally returns safe defaults for unsupported types.
     *
     * This approach ensures audit logs contain actionable identity references rather than
     * unusable object hashes, supporting post-incident analysis and accountability tracking.
     *
     * @param  mixed      $actor Actor entity to extract identifier from, accepting Eloquent models,
     *                           plain objects with id properties, or scalar string/integer values
     * @return int|string Serializable identifier suitable for long-term audit log storage
     */
    private function getActorIdentifier(mixed $actor): string|int
    {
        // If it's an object with an id property/method, use that
        if (is_object($actor)) {
            if (method_exists($actor, 'getKey')) {
                $key = $actor->getKey();

                if (is_string($key) || is_int($key)) {
                    return $key;
                }

                /** @phpstan-ignore-next-line */
                return (string) $key;
            }

            if (property_exists($actor, 'id')) {
                $id = $actor->id;

                if (is_string($id) || is_int($id)) {
                    return $id;
                }

                /** @phpstan-ignore-next-line */
                return (string) $id;
            }

            if (method_exists($actor, '__toString')) {
                /** @var string */
                return $actor->__toString();
            }

            return 'object';
        }

        // Otherwise use as-is if it's string or int
        if (is_string($actor) || is_int($actor)) {
            return $actor;
        }

        // For all other types, return a safe default
        return 'unknown';
    }
}
