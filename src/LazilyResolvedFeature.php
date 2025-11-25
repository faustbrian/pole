<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl;

use BackedEnum;
use Carbon\CarbonInterface;
use Cline\Toggl\Concerns\NormalizesFeatureInput;
use Cline\Toggl\Drivers\Decorator;
use Illuminate\Support\Facades\Date;

use function is_array;
use function now;

/**
 * Represents a feature flag that is lazily resolved with metadata.
 *
 * This class provides a fluent interface for defining feature flags with additional
 * metadata such as expiration dates and dependencies. Features are only fully resolved
 * when their resolver is executed, allowing for deferred evaluation.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class LazilyResolvedFeature
{
    use NormalizesFeatureInput;

    /**
     * The expiration date for this feature.
     *
     * When set, the feature is considered expired after this date and should
     * be disabled or removed. Useful for time-limited features or A/B tests.
     */
    private ?CarbonInterface $expiresAt = null;

    /**
     * The features this feature depends on.
     *
     * List of prerequisite feature names that must be enabled before this
     * feature can be active. Ensures proper dependency resolution.
     *
     * @var array<string>
     */
    private array $requires = [];

    /**
     * Create a new lazily resolved feature instance.
     *
     * @param string                                  $name      Unique identifier for the feature flag
     * @param (callable(mixed $context): mixed)|mixed $resolver  The resolver callback or static value that
     *                                                           determines feature state when evaluated
     * @param null|Decorator                          $decorator Optional decorator instance that allows
     *                                                           chaining feature definitions in a fluent API
     */
    public function __construct(
        private readonly string $name,
        private mixed $resolver,
        private readonly ?Decorator $decorator = null,
    ) {}

    /**
     * Set the expiration date for this feature.
     *
     * After this date, the feature will be considered expired and should be
     * disabled or removed. Useful for time-limited features or A/B tests.
     *
     * @param  CarbonInterface $date The absolute expiration timestamp
     * @return static          Fluent interface for method chaining
     */
    public function expiresAt(CarbonInterface $date): static
    {
        $this->expiresAt = $date;

        return $this;
    }

    /**
     * Set the expiration date relative to now.
     *
     * Convenience method to set expiration using relative time values. All
     * parameters are cumulative, allowing for expressions like "30 days and 6 hours".
     *
     * @param  int    $days    Number of days from now until expiration
     * @param  int    $hours   Number of hours from now until expiration
     * @param  int    $minutes Number of minutes from now until expiration
     * @return static Fluent interface for method chaining
     */
    public function expiresAfter(int $days = 0, int $hours = 0, int $minutes = 0): static
    {
        return $this->expiresAt(
            Date::now()
                ->addDays($days)
                ->addHours($hours)
                ->addMinutes($minutes),
        );
    }

    /**
     * Set the features this feature depends on.
     *
     * Defines prerequisite features that must be enabled before this feature can be active.
     * Useful for ensuring features are rolled out in the correct order or for managing
     * feature dependencies.
     *
     * @param  array<string>|string $features Single feature name or array of feature names that
     *                                        must be enabled for this feature to work
     * @return static               Fluent interface for method chaining
     */
    public function requires(string|BackedEnum|array $features): static
    {
        $features = $this->normalizeFeatureInput($features);
        $this->requires = is_array($features) ? $features : [$features];

        return $this;
    }

    /**
     * Set the resolver and finalize the feature definition.
     *
     * This method completes the feature definition by setting the resolver and
     * registering it with the decorator if available. This is the final step in
     * the fluent feature definition chain.
     *
     * @param (callable(mixed $context): mixed)|mixed $resolverCallback The resolver callback or static
     *                                                                  value that determines feature state
     */
    public function resolver(mixed $resolverCallback): void
    {
        $this->resolver = $resolverCallback;

        // Register the completed feature definition with the decorator
        if ($this->decorator instanceof Decorator) {
            $this->decorator->define($this->name, $this);
        }
    }

    /**
     * Get the resolver for this feature.
     *
     * @return (callable(mixed $context): mixed)|mixed The resolver callback or static value
     */
    public function getResolver(): mixed
    {
        return $this->resolver;
    }

    /**
     * Get the expiration date for this feature.
     *
     * @return null|CarbonInterface The expiration date, or null if never expires
     */
    public function getExpiresAt(): ?CarbonInterface
    {
        return $this->expiresAt;
    }

    /**
     * Get the dependencies for this feature.
     *
     * @return array<string> The list of required feature names
     */
    public function getRequires(): array
    {
        return $this->requires;
    }

    /**
     * Check if this feature has expired.
     *
     * A feature is expired if an expiration date is set and that date is in the past.
     *
     * @return bool True if the feature has expired, false if it hasn't or has no expiration
     */
    public function isExpired(): bool
    {
        if (!$this->expiresAt instanceof CarbonInterface) {
            return false;
        }

        return $this->expiresAt->isPast();
    }

    /**
     * Check if this feature is expiring soon.
     *
     * Useful for alerting or scheduling feature flag cleanup. A feature is expiring
     * soon if it expires between now and the specified number of days from now.
     *
     * @param  int  $days The number of days to consider as "soon" (e.g., 7, 30)
     * @return bool True if the feature expires within the specified days, false otherwise
     */
    public function isExpiringSoon(int $days): bool
    {
        if (!$this->expiresAt instanceof CarbonInterface) {
            return false;
        }

        return $this->expiresAt->isBetween(now(), now()->addDays($days));
    }

    /**
     * Get the feature name.
     *
     * @return string The feature name
     */
    public function getName(): string
    {
        return $this->name;
    }
}
