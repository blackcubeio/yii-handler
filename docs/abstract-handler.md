# `AbstractHandler` — Layer 1

The base PSR-15 handler. Provides view rendering, JSON responses, redirects, file downloads, AJAX detection and namespace-based view resolution.

```
namespace Blackcube\Handler;

abstract class AbstractHandler implements RequestHandlerInterface
```

## Constructor

```php
public function __construct(
    protected LoggerInterface $logger,
    protected HandlerConfig $config,
    protected WebViewRenderer $viewRenderer,
    protected ResponseFactoryInterface $responseFactory,
    protected JsonResponseFactory $jsonResponseFactory,
    protected UrlGeneratorInterface $urlGenerator,
    protected Aliases $aliases,
)
```

All seven dependencies are resolved by your DI container — see [installation.md](installation.md).

## Properties

| Property | Type | Notes |
|---|---|---|
| `$debug` | `bool` | Initialized from `$config->debug` |
| `$request` | `ServerRequestInterface` | **Not** populated by the base class. Subclasses must assign it in their `handle()` method (layer 2 does this for you). |

## Methods

### `render(string $view, array $parameters = []): ResponseInterface`

Render a view with the configured layout. The view path is set to `$config->viewsAlias` (resolved through `Aliases`), the layout to `$config->layoutAlias`. Returns a `200 OK` response.

The view name is passed through `resolveView()` first — see below.

### `renderPartial(string $view, array $parameters = []): ResponseInterface`

Same as `render()` but without a layout. Useful for AJAX fragments or content slots.

### `renderJson(array $data): ResponseInterface`

Return a JSON response built by `JsonResponseFactory`. No view, no layout.

### `redirect(string $routeName, array $parameters = [], int $status = 302): ResponseInterface`

Generate a URL with `UrlGenerator` and return a redirect response. Default status is `302 Found`.

### `downloadContent(string $content, string $filename, array $options = []): ResponseInterface`

Send a string body as a file download. `options['mimeType']` defaults to `application/octet-stream`. Sets `Content-Type` and `Content-Disposition: attachment`.

### `downloadFile(string $filepath, ?string $filename = null, array $options = []): ResponseInterface`

Same as `downloadContent()` but reads the file from disk. The filename defaults to `basename($filepath)`. The MIME type is auto-detected if not provided.

### `getBodyParams(): ?array`

Return `$this->request->getParsedBody()` for non-`GET` requests, `null` for `GET`. Use this everywhere you need the POST body — it gives you a single null check instead of having to remember the verb.

### `isAjax(): bool`

`true` if the request carries `X-Requested-With: XMLHttpRequest`.

### `isAjaxify(): bool`

`true` if the request carries `X-Requested-For: Ajaxify`. Used by the Bleet `Ajaxify` AJAX zones — you can render a partial when this header is set.

## View resolution

### The `//path` convention

Yii's native view resolution treats a path starting with `//` as **basePath-relative**. With `viewsAlias = '@src/Views'`, the view name `//Admin/Articles/index` resolves to `@src/Views/Admin/Articles/index.php`.

`AbstractHandler::resolveView()` rewrites simple view names into this convention based on the handler's class name.

### How `resolveView()` works

```
Handler class:           App\Handlers\Admin\Articles\Detail
Config prefix:           App\Handlers\
Stripped:                Admin\Articles\Detail
Pop the class name:      Admin\Articles
Joined with /:           Admin/Articles
Prefix with //:          //Admin/Articles
Append the view name:    //Admin/Articles/detail
```

So in `App\Handlers\Admin\Articles\Detail::handle()`:

```php
return $this->render('detail');
// → renders @src/Views/Admin/Articles/detail.php
```

### Bypassing the convention

A view name starting with `/` is used **as-is**. This lets you render a shared view from anywhere:

```php
return $this->render('//Commons/_confirm-content', [...]);
// → renders @src/Views/Commons/_confirm-content.php (regardless of the handler class)
```

### Edge cases

- A handler at the root of the prefix (`App\Handlers\NotFound`) resolves `not-found` to `//not-found` (no parent folder).
- A handler outside the prefix (the class does not start with `handlerNamespacePrefix`) keeps its full name as the relative path. Avoid this — keep your handlers under the configured prefix.

## Override points

The following methods are designed to be overridden in subclasses or in your own application base handler:

### `getLayout(): string`

Return the layout file path. The default reads `$config->layoutAlias` and resolves it via `Aliases`. Override per handler if you need a non-default layout (e.g. an admin layout for `App\Handlers\Admin\…`):

```php
protected function getLayout(): string
{
    return $this->aliases->get('@src/Views/Layouts/admin.php');
}
```

### `getViewPath(): string`

Return the absolute path used as the view base. The default resolves `$config->viewsAlias`. Override if a handler family lives under a different folder.

### `resolveView(string $view): string`

Return the final view path passed to `WebViewRenderer`. Override only if the namespace convention does not work for your project.

## Complete example

```php
<?php

declare(strict_types=1);

namespace App\Handlers\Admin\Articles;

use App\Models\Article;
use Blackcube\Handler\AbstractHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Index extends AbstractHandler
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        $articles = Article::query()
            ->andWhere(['deletedAt' => null])
            ->orderBy(['createdAt' => SORT_DESC])
            ->all();

        if ($this->isAjaxify()) {
            return $this->renderPartial('index-fragment', ['articles' => $articles]);
        }

        return $this->render('index', ['articles' => $articles]);
    }
}
```

This handler:
- Loads articles directly in `handle()` — no pipeline ceremony.
- Renders a partial fragment if the request comes from a Bleet `Ajaxify` zone, otherwise the full page.
- Both view names (`index-fragment`, `index`) resolve to `@src/Views/Admin/Articles/…` automatically.

If your handler grows beyond a single load + render, switch to `AbstractPipelineHandler` — see [pipeline-handler.md](pipeline-handler.md).
