# Installation

## Composer

```bash
composer require blackcube/yii-handler
```

For the Dialog layer (`Dialog\AbstractDialogInit`, `Dialog\AbstractDialogConfirm`):

```bash
composer require blackcube/yii-bleet blackcube/yii-bridge-model yiisoft/session
```

## Services required

The base `AbstractHandler` constructor expects the following services to be resolvable from your DI container:

| Parameter | Interface / Class |
|---|---|
| `$logger` | `Psr\Log\LoggerInterface` |
| `$config` | `Blackcube\Handler\HandlerConfig` |
| `$viewRenderer` | `Yiisoft\Yii\View\Renderer\WebViewRenderer` |
| `$responseFactory` | `Psr\Http\Message\ResponseFactoryInterface` |
| `$jsonResponseFactory` | `Yiisoft\DataResponse\ResponseFactory\JsonResponseFactory` |
| `$urlGenerator` | `Yiisoft\Router\UrlGeneratorInterface` |
| `$aliases` | `Yiisoft\Aliases\Aliases` |

`AbstractPipelineHandler` adds:

| Parameter | Interface / Class |
|---|---|
| `$currentRoute` | `Yiisoft\Router\CurrentRoute` |

`Dialog\AbstractDialogInit` and `Dialog\AbstractDialogConfirm` add:

| Parameter | Interface / Class |
|---|---|
| `$session` | `Yiisoft\Session\SessionInterface` |

All of these are standard Yii3 services. If you already run a Yii3 web application, every entry above is wired for you — except `HandlerConfig`, which is yours to define.

## Registering `HandlerConfig`

Add a single instance to your DI container so every handler receives the same configuration.

`config/common/di/handler.php`:

```php
use Blackcube\Handler\HandlerConfig;

return [
    HandlerConfig::class => [
        'class' => HandlerConfig::class,
        '__construct()' => [
            'handlerNamespacePrefix' => 'App\\Handlers\\',
            'viewsAlias'             => '@src/Views',
            'layoutAlias'            => '@src/Views/Layouts/main.php',
            'debug'                  => $params['debug'] ?? false,
        ],
    ],
];
```

The defaults match a typical `App\Handlers\…` layout with views under `@src/Views/`. Override the alias names if your project uses a different layout.

## Aliases

`HandlerConfig::viewsAlias` and `HandlerConfig::layoutAlias` are resolved through `Yiisoft\Aliases\Aliases`. Make sure both aliases exist in your alias config:

`config/common/params.php`:

```php
return [
    'yiisoft/aliases' => [
        'aliases' => [
            '@src' => '@root/src',
            // …
        ],
    ],
];
```

## Verifying the installation

Create a minimal handler that extends the base layer and route it:

```php
namespace App\Handlers;

use Blackcube\Handler\AbstractHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Health extends AbstractHandler
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        return $this->renderJson(['status' => 'ok']);
    }
}
```

Hit the route. A `200 OK` JSON response confirms the constructor injection works.
