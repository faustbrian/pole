<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use BackedEnum;
use Cline\Toggl\Exceptions\MissingDependentFeatureException;
use Cline\Toggl\Exceptions\MissingPrerequisitesException;
use Cline\Toggl\FeatureManager;

use function implode;
use function is_array;

/**
 * Conductor for feature dependency management.
 *
 * Ensures prerequisite features are active before activating dependent features.
 * Supports both require()->before() and activate()->requires() patterns.
 * Validates all prerequisites before activation and provides detailed error messages
 * when dependencies are not met.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class DependencyConductor
{
    /**
     * Create a new dependency conductor instance.
     *
     * @param FeatureManager                             $manager       Feature manager instance for managing feature state
     *                                                                  across contexts and handling activation operations
     * @param array<BackedEnum|string>|BackedEnum|string $prerequisites Required feature(s) that must be active before dependent
     *                                                                  feature activation. Supports single feature or array of
     *                                                                  multiple prerequisite features (all must be active)
     * @param null|BackedEnum|string                     $dependent     Dependent feature requiring the prerequisites. When null,
     *                                                                  must be configured via before() before calling for()
     */
    public function __construct(
        private FeatureManager $manager,
        private string|BackedEnum|array $prerequisites,
        private string|BackedEnum|null $dependent = null,
    ) {}

    /**
     * Specify the feature that depends on prerequisites.
     *
     * Returns a new conductor instance with the dependent feature configured.
     * This establishes the dependency relationship between prerequisites and the feature.
     *
     * @param  BackedEnum|string $feature Dependent feature that requires the prerequisites to be active
     * @return self              New conductor instance with dependent feature configured
     */
    public function before(string|BackedEnum $feature): self
    {
        return new self($this->manager, $this->prerequisites, $feature);
    }

    /**
     * Execute dependency check and activate dependent feature (terminal method).
     *
     * Validates all prerequisites are active for the given context before activating
     * the dependent feature. Short-circuits with detailed exception if any prerequisite
     * is missing, listing which specific prerequisites are not met.
     *
     * @param mixed $context Context to validate prerequisites and activate feature for. Supports
     *                       any context type recognized by the feature manager (user, team, etc.)
     *
     * @throws MissingDependentFeatureException If dependent feature not specified via before()
     * @throws MissingPrerequisitesException    If any prerequisites are not active for the context
     */
    public function for(mixed $context): void
    {
        if ($this->dependent === null) {
            throw MissingDependentFeatureException::notSpecified();
        }

        /** @var array<BackedEnum|string> $prerequisites */
        $prerequisites = is_array($this->prerequisites) ? $this->prerequisites : [$this->prerequisites];

        /** @var array<string> $missingPrerequisites */
        $missingPrerequisites = [];

        // Check all prerequisites
        foreach ($prerequisites as $prerequisite) {
            if (!$this->manager->for($context)->active($prerequisite)) {
                $missingPrerequisites[] = $prerequisite instanceof BackedEnum ? $prerequisite->value : $prerequisite;
            }
        }

        // Throw if any prerequisites missing
        if ($missingPrerequisites !== []) {
            $missing = implode(', ', $missingPrerequisites);
            $dependentName = $this->dependent instanceof BackedEnum ? $this->dependent->value : $this->dependent;

            throw MissingPrerequisitesException::forFeature((string) $dependentName, $missing);
        }

        // All prerequisites met, activate dependent feature
        $this->manager->for($context)->activate($this->dependent);
    }

    /**
     * Get the prerequisite features.
     *
     * Returns the feature(s) that must be active before the dependent feature
     * can be activated. May be a single feature or an array of multiple features.
     *
     * @return array<BackedEnum|string>|BackedEnum|string The prerequisite feature(s)
     */
    public function prerequisites(): string|BackedEnum|array
    {
        return $this->prerequisites;
    }

    /**
     * Get the dependent feature.
     *
     * Returns the feature that depends on the prerequisites, or null if not yet
     * configured via the before() method.
     *
     * @return null|BackedEnum|string The dependent feature, or null if not configured
     */
    public function dependent(): string|BackedEnum|null
    {
        return $this->dependent;
    }
}
