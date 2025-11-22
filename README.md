[![GitHub Workflow Status][ico-tests]][link-tests]
[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]

------

A powerful Laravel feature flag package with advanced strategies, time bombs, feature groups, and A/B testing support. Built on top of Laravel Pennant's proven architecture with extended capabilities for enterprise applications.

## Requirements

> **Requires [PHP 8.4+](https://php.net/releases/)** and Laravel 11+

## Installation

```bash
composer require cline/toggl
```

## Quick Start

```php
use Cline\Toggl\Toggl;

// Define a simple feature
Toggl::define('new-api', fn($user) => $user->isAdmin());

// Check if active
if (Toggl::active('new-api')) {
    // New API is enabled
}

// Contextual to specific user
if (Toggl::for($user)->active('premium-dashboard')) {
    // Show premium dashboard
}
```

## Documentation

- **[Getting Started](cookbook/getting-started.md)** - Installation, configuration, and first steps
- **[Basic Usage](cookbook/basic-usage.md)** - Core feature flag operations
- **[Strategies](cookbook/strategies.md)** - Boolean, time-based, percentage, scheduled, and conditional strategies
- **[Time Bombs](cookbook/time-bombs.md)** - Auto-expiring features with warnings
- **[Feature Groups](cookbook/feature-groups.md)** - Managing related features together
- **[Dependencies](cookbook/dependencies.md)** - Feature requirements and relationships
- **[Variants](cookbook/variants.md)** - A/B testing with weighted distribution
- **[Advanced Usage](cookbook/advanced-usage.md)** - Events, commands, and advanced patterns

## Key Features

- ✅ **Pennant-Compatible API** - Drop-in replacement with familiar syntax
- ✅ **Advanced Strategies** - Boolean, time-based, percentage, scheduled, conditional
- ✅ **Time Bombs** - Auto-expire features after specific dates
- ✅ **Feature Groups** - Bundle related flags for bulk operations
- ✅ **Dependencies** - Require other features to be active
- ✅ **Variants** - A/B testing with sticky, weighted distribution
- ✅ **Contextual Features** - Per-user, team, or any context
- ✅ **Multiple Drivers** - Array (in-memory) and Database storage
- ✅ **Blade Directives** - `@feature`, `@featureany`, `@featureall`

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

## Security

If you discover any security related issues, please use the [GitHub security reporting form][link-security] rather than the issue queue.

## Credits

- [Brian Faust][link-maintainer]
- [All Contributors][link-contributors]

## License

The MIT License. Please see [License File](LICENSE.md) for more information.

[ico-tests]: https://github.com/faustbrian/toggl/actions/workflows/quality-assurance.yaml/badge.svg
[ico-version]: https://img.shields.io/packagist/v/cline/toggl.svg
[ico-license]: https://img.shields.io/badge/License-MIT-green.svg
[ico-downloads]: https://img.shields.io/packagist/dt/cline/toggl.svg

[link-tests]: https://github.com/faustbrian/toggl/actions
[link-packagist]: https://packagist.org/packages/cline/toggl
[link-downloads]: https://packagist.org/packages/cline/toggl
[link-security]: https://github.com/faustbrian/toggl/security
[link-maintainer]: https://github.com/faustbrian
[link-contributors]: ../../contributors
