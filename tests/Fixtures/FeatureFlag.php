<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

/**
 * @author Brian Faust <brian@cline.sh>
 */
enum FeatureFlag: string
{
    case NewDashboard = 'new-dashboard';
    case BetaFeatures = 'beta-features';
    case ApiV2 = 'api-v2';
}
