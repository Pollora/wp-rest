<p align="center">
  <a href="https://pollora.dev">
    <img src="https://raw.githubusercontent.com/Pollora/.github/main/brand/banners/wp-rest.png" width="100%" alt="Pollora WP REST: WordPress REST API routes declared with PHP attributes">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/pollora/wp-rest"><img src="https://img.shields.io/packagist/v/pollora/wp-rest" alt="Latest version"></a>
  <a href="https://packagist.org/packages/pollora/wp-rest"><img src="https://img.shields.io/packagist/dt/pollora/wp-rest" alt="Total downloads"></a>
  <a href="https://github.com/Pollora/wp-rest/actions/workflows/tests.yml"><img src="https://github.com/Pollora/wp-rest/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/wp-rest" alt="License"></a>
</p>

WordPress REST API routes written as a controller class: `#[WpRestRoute]` on the class gives the namespace and route, `#[Method]` on a method gives its HTTP verbs, and a permission class decides who may call it. No `register_rest_route()` call, no `rest_api_init` callback to write.

The controller is built with its constructor dependencies, its methods receive the route parameters they name and the `WP_REST_Request` when they ask for it.

> Part of [Pollora](https://pollora.dev), the Laravel framework for WordPress, where it is installed and wired for you: see [REST API](https://pollora.dev/advanced/rest-api/). It runs without Laravel too, in a WordPress plugin, a Bedrock site or any PHP project.

## Installation

```bash
composer require pollora/wp-rest
```

Requires PHP 8.3+. It builds on [`pollora/attributes`](https://github.com/Pollora/attributes) and [`pollora/discovery`](https://github.com/Pollora/discovery).

## Declaring routes

```php
use Pollora\Attributes\WpRestRoute;
use Pollora\Attributes\WpRestRoute\Method;
use Pollora\WpRest\Permissions\Can;
use Pollora\WpRest\Permissions\IsLoggedIn;

#[WpRestRoute('app/v1', 'documents/(?P<documentId>\\d+)', permissionCallback: IsLoggedIn::class)]
final class DocumentApi
{
    public function __construct(private readonly DocumentRepository $documents) {}

    #[Method('GET')]
    public function show(int $documentId): array
    {
        return $this->documents->find($documentId);
    }

    #[Method('DELETE', permissionCallback: new Can('delete_post', parameter: 'documentId'))]
    public function destroy(WP_REST_Request $request, int $documentId): array
    {
        return ['deleted' => $this->documents->delete($documentId)];
    }
}
```

- `GET wp-json/app/v1/documents/18` calls `show(18)`. A parameter typed `WP_REST_Request` receives the request; the others are read from the route by name.
- `#[Method]` accepts `GET`, `POST`, `PUT`, `DELETE` and `PATCH`, alone or as a list; with any other verb the route is not registered, and the logger says why.
- A method's permission replaces the route's. Without either, the route is public today, and WordPress is told it is doing it wrong (shown with `WP_DEBUG`, on the pages that load the routes, never inside a REST response). Say so with `Everyone::class` when a route is public on purpose: the next major version refuses routes without a permission.

### Permissions

| Class | Allows |
|---|---|
| `Everyone` | anyone, logged in or not: a route that is public on purpose |
| `IsLoggedIn` | any logged-in user |
| `IsAdmin` | users who can `manage_options` |
| `new Can('edit_posts')` | users with a capability; `parameter:` passes a request parameter for a meta capability such as `edit_post` |

A permission class of your own implements `Pollora\Attributes\WpRestRoute\Permission`: `allow(WP_REST_Request $request): bool|WP_Error`.

To refuse routes without a permission today, as the next major version will, opt in with a filter:

```php
add_filter('pollora/wp-rest/private_by_default', '__return_true');
```

## Wiring it outside Pollora

Point the discovery engine at your code and add `WpRestDiscovery`; routes are registered on `rest_api_init`.

```php
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Pollora\WpRest\Infrastructure\Services\WpRestDiscovery;

(new DiscoveryEngine)
    ->addLocation(new DiscoveryLocation('MyPlugin\\', __DIR__.'/src'))
    ->addDiscovery('wp_rest_routes', new WpRestDiscovery)
    ->run();
```

Controllers are built by the engine's instance resolver: by reflection by default (constructor parameters typed with a class are built in turn), by your container if you give the engine a `ContainerInstanceResolver` (PSR-11). `WpRestDiscovery` takes a PSR-3 logger, which records the routes it could not register.

## Testing

```bash
composer test   # unit tests, PHPStan, Pint
```

## License

MIT. See [LICENSE](LICENSE).
