# Changelog

All notable changes to `pollora/wp-rest` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 2026-10-29

A major release: a route that declares no permission is refused.

### Changed

- **A route without a permission is refused** (`rest_forbidden`, 401 for a visitor, 403 for a logged-in user). Until now it stayed public and called `_doing_it_wrong()`. Declare `permissionCallback: Everyone::class` on the routes that are public on purpose, or a permission such as `IsLoggedIn`
- The `pollora/wp-rest/private_by_default` filter now defaults to `true`. Return `false` to keep the routes without a permission public while a site migrates; WordPress is then told, with `WP_DEBUG`, which route to fix

### Removed

- The `pollora/wp_rest/private_by_default` filter name and the `WpRestRouteRegistrar::PRIVATE_BY_DEFAULT` constant, deprecated since 0.2.1. Use `pollora/wp-rest/private_by_default`

## [0.2.1] - 2026-10-26

### Changed

- The filter is named `pollora/wp-rest/private_by_default`, to follow `pollora/<module>/<event>`. The name `0.2.0` gave it, `pollora/wp_rest/private_by_default`, still works and reports itself as deprecated

## [0.2.0] - 2026-10-26

### Added

- `Pollora\WpRest\Permissions\Everyone`: declares a route public on purpose
- The `pollora/wp_rest/private_by_default` filter: return `true` to refuse every route that declares no permission, as the next major version will

### Deprecated

- A route without a permission: it stays public, and now calls `_doing_it_wrong()` with the route and the fix. The next major version refuses it. Declare `permissionCallback: Everyone::class` to keep a route public. The notice is skipped while a REST request is served, so a notice printed by `WP_DEBUG` never breaks a JSON response

## [0.1.0] - 2026-10-13

A first version for the framework's next release to build on; 1.0.0 comes with that release.

### Added

- First release, extracted from `pollora/framework` (`src/WpRest`) with its history. Class names are unchanged: `Pollora\WpRest\*`, and `Pollora\Support\WpGlobals`
- Runs without Laravel's application: controllers are built by the discovery's instance resolver, with their constructor dependencies, and custom `HandlesAttributes` attributes get the PSR-11 container given to `WpRestDiscovery`. An architecture test keeps the package to the attributes, discovery, PSR contracts and WordPress
