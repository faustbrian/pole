<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Conductors;

use Cline\Toggl\FeatureManager;

use function is_array;

/**
 * Fluent conductor for group-first activation pattern.
 *
 * Enables the pattern: Toggl::activateGroup('premium')->for($user)
 * Activates all features in a group for specified context(s). Groups allow batch
 * activation of related features with a single operation.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class GroupActivationConductor
{
    /**
     * Create a new group activation conductor instance.
     *
     * @param FeatureManager $manager   Feature manager instance for managing feature state across
     *                                  contexts and coordinating group activation operations
     * @param string         $groupName Feature group identifier specifying which collection of features
     *                                  to activate. Group definitions are configured in the feature system
     */
    public function __construct(
        private FeatureManager $manager,
        private string $groupName,
    ) {}

    /**
     * Activate all features in the group for the given context(s) (terminal method).
     *
     * Executes the group activation operation. When an array of contexts is provided,
     * activates the feature group for each context independently. All features defined
     * within the group will be activated with their configured default values.
     *
     * @param mixed $context Single context or array of contexts. Supports any context type
     *                       recognized by the feature manager (user, team, organization, etc.)
     */
    public function for(mixed $context): void
    {
        $contexts = is_array($context) ? $context : [$context];

        foreach ($contexts as $s) {
            $this->manager->for($s)->activateGroup($this->groupName);
        }
    }
}
