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
use DateTime;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;

use function array_key_exists;
use function array_values;
use function is_array;
use function is_string;
use function uniqid;

/**
 * Conductor for time-based feature scheduling and automated activation management.
 *
 * Enables features to be activated and deactivated automatically based on temporal rules
 * such as specific times, time windows, or date ranges. Supports both immediate evaluation
 * and persistent schedule storage for cron-based automation. Ideal for time-limited
 * promotions, beta testing periods, or scheduled feature releases.
 *
 * ```php
 * // Activate feature during specific time window
 * Toggl::schedule('black-friday-sale')
 *     ->between('2024-11-29 00:00', '2024-11-30 23:59')
 *     ->withValue(['discount' => 50])
 *     ->for($user);
 *
 * // Save schedule for later evaluation by cron job
 * $scheduleId = Toggl::schedule('seasonal-theme')
 *     ->activateAt('2024-12-01')
 *     ->deactivateAt('2025-01-01')
 *     ->save($organization);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class ScheduleConductor
{
    /**
     * Create a new schedule conductor instance.
     *
     * Initializes an immutable schedule conductor with temporal configuration for feature
     * activation. Typically instantiated via FeatureManager's schedule() method rather
     * than directly constructed.
     *
     * @param FeatureManager         $manager   the feature manager instance used to activate and
     *                                          deactivate features based on schedule evaluation
     * @param BackedEnum|string      $feature   The feature identifier to schedule. Can be a string
     *                                          name or BackedEnum for type-safe feature references.
     * @param null|DateTimeInterface $startTime Optional datetime when the feature should activate.
     *                                          Null means no start time constraint (immediately active).
     * @param null|DateTimeInterface $endTime   Optional datetime when the feature should deactivate.
     *                                          Null means no end time constraint (remains active).
     * @param mixed                  $value     The value to set when activating the feature. Defaults
     *                                          to boolean true for simple on/off features.
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum $feature,
        private ?DateTimeInterface $startTime = null,
        private ?DateTimeInterface $endTime = null,
        private mixed $value = true,
    ) {}

    /**
     * Set when the feature should become active.
     *
     * Configures the schedule to activate the feature at a specific datetime. Accepts
     * either DateTimeInterface instances or parseable datetime strings.
     *
     * @param  DateTimeInterface|string $datetime DateTime object or string (e.g., '2024-12-01 00:00:00')
     *                                            indicating when to activate the feature.
     * @return self                     new conductor instance with updated start time
     */
    public function activateAt(DateTimeInterface|string $datetime): self
    {
        $startTime = is_string($datetime) ? new DateTime($datetime) : $datetime;

        return new self($this->manager, $this->feature, $startTime, $this->endTime, $this->value);
    }

    /**
     * Set when the feature should become inactive.
     *
     * @param  DateTimeInterface|string $datetime when to deactivate the feature
     * @return self                     new conductor instance with updated end time
     */
    public function deactivateAt(DateTimeInterface|string $datetime): self
    {
        $endTime = is_string($datetime) ? new DateTime($datetime) : $datetime;

        return new self($this->manager, $this->feature, $this->startTime, $endTime, $this->value);
    }

    /**
     * Configure feature to be active only within a specific time window.
     *
     * @param  DateTimeInterface|string $start window start datetime
     * @param  DateTimeInterface|string $end   window end datetime
     * @return self                     new conductor with both start and end times configured
     */
    public function between(DateTimeInterface|string $start, DateTimeInterface|string $end): self
    {
        $startTime = is_string($start) ? new DateTime($start) : $start;
        $endTime = is_string($end) ? new DateTime($end) : $end;

        return new self($this->manager, $this->feature, $startTime, $endTime, $this->value);
    }

    /**
     * Set the value to use when activating the feature.
     *
     * @param  mixed $value custom value for the feature (config, array, object, etc.)
     * @return self  new conductor instance with updated value
     */
    public function withValue(mixed $value): self
    {
        return new self($this->manager, $this->feature, $this->startTime, $this->endTime, $value);
    }

    /**
     * Evaluate schedule and apply feature activation state to the context.
     *
     * Terminal method that checks if the current time falls within the configured schedule
     * and activates or deactivates the feature accordingly. Changes take effect immediately.
     *
     * @param  mixed $context the context to apply the schedule to
     * @return bool  true if feature is currently active after evaluation, false otherwise
     */
    public function for(mixed $context): bool
    {
        $contextdDriver = $this->manager->for($context);
        $now = Date::now();

        // Determine if feature should be active based on schedule
        $shouldBeActive = $this->shouldBeActive($now);

        // Get current state
        $isCurrentlyActive = $contextdDriver->active($this->feature);

        // Apply state changes
        if ($shouldBeActive && !$isCurrentlyActive) {
            // Activate if within schedule and not active
            $contextdDriver->activate($this->feature, $this->value);
        } elseif (!$shouldBeActive && $isCurrentlyActive) {
            // Deactivate if outside schedule and currently active
            $contextdDriver->deactivate($this->feature);
        }

        return $shouldBeActive;
    }

    /**
     * Store schedule metadata for later evaluation.
     *
     * Saves schedule configuration so external schedulers can
     * evaluate and apply schedules.
     *
     * @param  mixed  $context the context to save schedule for
     * @return string schedule ID
     */
    public function save(mixed $context): string
    {
        $contextdDriver = $this->manager->for($context);

        // Generate unique schedule ID
        $scheduleId = uniqid('schedule_', true);

        // Build schedule data
        $scheduleData = [
            'id' => $scheduleId,
            'feature' => $this->featureToString($this->feature),
            'start_time' => $this->startTime?->format('c'),
            'end_time' => $this->endTime?->format('c'),
            'value' => $this->value,
            'created_at' => Date::now()->format('c'),
        ];

        // Store schedule
        $schedulesKey = '__schedules__';
        $schedules = $contextdDriver->value($schedulesKey);
        $schedules = is_array($schedules) ? $schedules : [];
        $schedules[$scheduleId] = $scheduleData;

        $contextdDriver->activate($schedulesKey, $schedules);

        return $scheduleId;
    }

    /**
     * List all saved schedules.
     *
     * @param  mixed       $context the context to list schedules for
     * @return list<mixed> array of all saved schedules for the context
     */
    public function listSchedules(mixed $context): array
    {
        $contextdDriver = $this->manager->for($context);
        $schedulesKey = '__schedules__';
        $schedules = $contextdDriver->value($schedulesKey);

        return is_array($schedules) ? array_values($schedules) : [];
    }

    /**
     * Delete a saved schedule.
     *
     * @param string $scheduleId schedule ID to delete
     * @param mixed  $context    the context to delete from
     */
    public function deleteSchedule(string $scheduleId, mixed $context): void
    {
        $contextdDriver = $this->manager->for($context);
        $schedulesKey = '__schedules__';
        $schedules = $contextdDriver->value($schedulesKey);

        if (!is_array($schedules)) {
            return;
        }

        unset($schedules[$scheduleId]);

        if ($schedules === []) {
            $contextdDriver->deactivate($schedulesKey);
        } else {
            $contextdDriver->activate($schedulesKey, $schedules);
        }
    }

    /**
     * Apply all saved schedules for context.
     *
     * Evaluates all stored schedules and applies appropriate state.
     * Useful for scheduled jobs or cron tasks.
     *
     * @param  mixed $context the context to apply schedules for
     * @return int   number of features updated
     */
    public function applyAll(mixed $context): int
    {
        $schedules = $this->listSchedules($context);
        $updated = 0;

        foreach ($schedules as $schedule) {
            // Type guard: ensure schedule has required structure
            if (!is_array($schedule)) {
                continue;
            }

            // Reconstruct conductor from saved data
            $feature = $schedule['feature'] ?? null;

            if (!is_string($feature)) {
                continue;
            }

            $startTime = null;
            $endTime = null;

            // Type guard for start_time
            if (array_key_exists('start_time', $schedule) && is_string($schedule['start_time'])) {
                $startTime = new DateTime($schedule['start_time']);
            }

            // Type guard for end_time
            if (array_key_exists('end_time', $schedule) && is_string($schedule['end_time'])) {
                $endTime = new DateTime($schedule['end_time']);
            }

            $value = $schedule['value'] ?? true;

            $conductor = new self($this->manager, $feature, $startTime, $endTime, $value);

            // Check if state changed
            $contextdDriver = $this->manager->for($context);
            $wasActive = $contextdDriver->active($feature);
            $isActive = $conductor->for($context);

            if ($wasActive !== $isActive) {
                ++$updated;
            }
        }

        return $updated;
    }

    /**
     * Get the feature name.
     */
    public function feature(): string|BackedEnum
    {
        return $this->feature;
    }

    /**
     * Get the start time.
     */
    public function startTime(): ?DateTimeInterface
    {
        return $this->startTime;
    }

    /**
     * Get the end time.
     */
    public function endTime(): ?DateTimeInterface
    {
        return $this->endTime;
    }

    /**
     * Get the value.
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * Check if feature should be active at given time.
     *
     * @param  DateTimeInterface $now current time
     * @return bool              true if feature should be active, false otherwise
     */
    private function shouldBeActive(DateTimeInterface $now): bool
    {
        // If no schedule specified, default to active
        if (!$this->startTime instanceof DateTimeInterface && !$this->endTime instanceof DateTimeInterface) {
            return true;
        }

        // Check start time
        if ($this->startTime instanceof DateTimeInterface && $now < $this->startTime) {
            return false;
        }

        // Check end time
        return !($this->endTime instanceof DateTimeInterface && $now >= $this->endTime);
    }

    /**
     * Convert feature name to string.
     *
     * @param  BackedEnum|string $feature feature name or enum to convert
     * @return string            string representation of the feature
     */
    private function featureToString(BackedEnum|string $feature): string
    {
        if ($feature instanceof BackedEnum) {
            return (string) $feature->value;
        }

        return $feature;
    }
}
