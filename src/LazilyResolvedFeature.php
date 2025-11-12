<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Pole;

use Carbon\CarbonInterface;
use Cline\Pole\Drivers\Decorator;
use Illuminate\Support\Facades\Date;

use function is_array;

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
    /**
     * The expiration date for this feature.
     */
    private ?CarbonInterface $expiresAt = null;

    /**
     * The features this feature depends on.
     *
     * @var array<string>
     */
    private array $requires = [];

    /**
     * Create a new lazily resolved feature instance.
     *
     * @param string                                $name      The feature name
     * @param (callable(mixed $scope): mixed)|mixed $resolver  The resolver callback or static value
     * @param null|Decorator                        $decorator The decorator instance for chaining
     */
    public function __construct(
        /**
         * The feature name.
         */
        private readonly string $name,
        /**
         * The feature resolver.
         *
         * @var (callable(mixed $scope): mixed)|mixed
         */
        private mixed $resolver,
        /**
         * The decorator instance for chaining.
         */
        private readonly ?Decorator $decorator = null,
    ) {}

    /**
     * Set the expiration date for this feature.
     *
     * @param CarbonInterface $date The expiration date
     */
    public function expiresAt(CarbonInterface $date): static
    {
        $this->expiresAt = $date;

        return $this;
    }

    /**
     * Set the expiration date relative to now.
     *
     * Convenience method to set expiration using relative time values.
     *
     * @param int $days    The number of days until expiration
     * @param int $hours   The number of hours until expiration
     * @param int $minutes The number of minutes until expiration
     */
    public function expiresAfter(int $days = 0, int $hours = 0, int $minutes = 0): static
    {
        $date = Date::now();

        if ($days > 0) {
            $date->addDays($days);
        }

        if ($hours > 0) {
            $date->addHours($hours);
        }

        if ($minutes > 0) {
            $date->addMinutes($minutes);
        }

        return $this->expiresAt($date);
    }

    /**
     * Set the features this feature depends on.
     *
     * Defines prerequisite features that must be enabled before this feature can be active.
     *
     * @param array<string>|string $features Single feature name or array of feature names
     */
    public function requires(string|array $features): static
    {
        $this->requires = is_array($features) ? $features : [$features];

        return $this;
    }

    /**
     * Set the resolver and finalize the feature definition.
     *
     * This method completes the feature definition by setting the resolver and
     * registering it with the decorator if available.
     *
     * @param (callable(mixed $scope): mixed)|mixed $resolverCallback The resolver callback or static value
     */
    public function resolver(mixed $resolverCallback): void
    {
        $this->resolver = $resolverCallback;

        // Register the feature with the decorator
        if ($this->decorator instanceof Decorator) {
            $this->decorator->define($this->name, $this);
        }
    }

    /**
     * Get the resolver for this feature.
     *
     * @return (callable(mixed $scope): mixed)|mixed The resolver callback or static value
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
     * @return bool True if the feature has expired, false otherwise
     */
    public function isExpired(): bool
    {
        if (!$this->expiresAt instanceof CarbonInterface) {
            return false;
        }

        return Date::now()->isAfter($this->expiresAt);
    }

    /**
     * Check if this feature is expiring soon.
     *
     * @param  int  $days The number of days to consider as "soon"
     * @return bool True if the feature expires within the specified days, false otherwise
     */
    public function isExpiringSoon(int $days): bool
    {
        if (!$this->expiresAt instanceof CarbonInterface) {
            return false;
        }

        return Date::now()->addDays($days)->isAfter($this->expiresAt)
            && !$this->isExpired();
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
