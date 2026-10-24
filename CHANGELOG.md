# Changelog

All notable changes to `pollora/wp-rest` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Pollora\WpRest\Permissions\Everyone`: declares a route public on purpose
- The `pollora/wp_rest/private_by_default` filter: return `true` to refuse every route that declares no permission, as the next major version will

### Deprecated

- A route without a permission: it stays public, and now calls `_doing_it_wrong()` with the route and the fix. The next major version refuses it. Declare `permissionCallback: Everyone::class` to keep a route public

## [0.1.0] - 2026-10-13

A first version for the framework's next release to build on; 1.0.0 comes with that release.

### Added

- First release, extracted from `pollora/framework` (`src/WpRest`) with its history. Class names are unchanged: `Pollora\WpRest\*`, and `Pollora\Support\WpGlobals`
- Runs without Laravel's application: controllers are built by the discovery's instance resolver, with their constructor dependencies, and custom `HandlesAttributes` attributes get the PSR-11 container given to `WpRestDiscovery`. An architecture test keeps the package to the attributes, discovery, PSR contracts and WordPress
