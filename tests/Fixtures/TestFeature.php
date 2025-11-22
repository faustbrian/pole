<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

/**
 * Test fixture enum for feature flag testing.
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum TestFeature: string
{
    case Premium = 'premium';
    case Analytics = 'analytics';
    case Reporting = 'reporting';
    case Beta = 'beta-features';
}
