# `AbstractPipelineHandler` — Layer 2

A structured handler with a four-step pipeline and an immutable `Output` DTO.

```
namespace Blackcube\Handler;

abstract class AbstractPipelineHandler extends AbstractHandler
```

## Why a pipeline?

A grown-up handler typically does four things:

1. Load data and check preconditions (entity exists, user has access).
2. React to the HTTP verb (`POST` loads form input, `GET` does not).
3. Run business logic (validate, save, compute).
4. Decide what to return (full page, partial, JSON, redirect, file).

The pipeline gives each of these its own method, with a default no-op implementation. You override only what you need.

## Pipeline diagram

```
handle($request)
   │
   ├── $this->request = $request
   │
   ├── setup(): ?Output             ─┐
   │       │                         │ load data, run access checks
   │       └─ returns Output? ───────┼─→ output($output)  (short-circuit)
   │                                 │
   ├── setupMethod(): ?Output       ─┘
   │       │                         │ verb-aware loading (POST → form load)
   │       └─ returns Output? ───────┼─→ output($output)  (short-circuit)
   │                                 │
   ├── process(): Output             │ business logic, builds the Output
   │       │                         │
   ├── prepareOutputData(Output)     │ optional hook to complete the Output
   │       │                         │
   └── output(Output): Response      └ dispatched to the right response builder
```

Any of `setup()` or `setupMethod()` returning a non-null `Output` short-circuits the pipeline. The handler jumps straight to `output()` and the remaining steps are skipped.

## Constructor

```php
public function __construct(
    LoggerInterface $logger,
    HandlerConfig $config,
    WebViewRenderer $viewRenderer,
    ResponseFactoryInterface $responseFactory,
    JsonResponseFactory $jsonResponseFactory,
    UrlGeneratorInterface $urlGenerator,
    Aliases $aliases,
    protected CurrentRoute $currentRoute,
)
```

Same as `AbstractHandler` plus `CurrentRoute`. Route arguments are resolved via `$this->currentRoute->getArgument('id')`.

## Pipeline methods

### `setup(): ?Output`

Default: `return null;`

Use it to load datas, fetch the main entity from the route, run RBAC checks. Return `null` to continue the pipeline. Return an `Output` to short-circuit (typical: `OutputType::Json` for an AJAX 404, `OutputType::Redirect` for a missing session).

### `setupMethod(): ?Output`

Default: `return null;`

Use it for verb-dependent loading. Typical usage: on `POST`, load the form from the request body and let `process()` deal with validation. On `GET`, do nothing.

```php
protected function setupMethod(): ?Output
{
    $bodyParams = $this->getBodyParams();
    if ($bodyParams !== null) {
        $this->form->load($bodyParams);
    }
    return null;
}
```

### `process(): Output` *(abstract)*

The only mandatory method. Runs the business logic and returns an `Output` describing the response.

### `prepareOutputData(Output $output): Output`

Default: `return $output;`

A hook called between `process()` and `output()`. Use it to inject parameters that every response should carry (current user, navigation state, flash messages):

```php
protected function prepareOutputData(Output $output): Output
{
    return $output->withMergedParams([
        'currentUser' => $this->currentUser(),
        'flash'       => $this->flash->all(),
    ]);
}
```

### `download(): ?string`

Default: `return null;`

Override when `process()` returns `OutputType::Download`. Return the binary content (PDF bytes, Excel string, ZIP buffer). The `output()` dispatcher will wrap it in a `downloadContent()` response.

### `output(Output $output): ResponseInterface`

The dispatcher. Maps each `OutputType` to the corresponding layer-1 method. You typically do not override this — override `process()` and `prepareOutputData()` instead.

```php
protected function output(Output $output): ResponseInterface
{
    return match ($output->getType()) {
        OutputType::Render   => $this->render($output->getView() ?? '', $output->getParams()),
        OutputType::Partial  => $this->renderPartial($output->getView() ?? '', $output->getParams()),
        OutputType::Json     => $this->renderJson($output->getParams()),
        OutputType::Redirect => $this->redirect(
            $output->getParams()['route']  ?? '',
            $output->getParams()['params'] ?? [],
        ),
        OutputType::Download => $this->downloadContent(
            $this->download() ?? '',
            $output->getParams()['filename'] ?? 'download',
            ['mimeType' => $output->getParams()['mimeType'] ?? 'application/octet-stream'],
        ),
    };
}
```

## `Output` DTO

```php
namespace Blackcube\Handler;

final class Output
{
    public function __construct(
        private readonly OutputType $type,
        private readonly array $params = [],
        private readonly ?string $view = null,
    );

    public function getType(): OutputType;
    public function getParams(): array;
    public function getView(): ?string;

    public function withType(OutputType $type): self;
    public function withParams(array $params): self;
    public function withMergedParams(array $params): self;
    public function withView(?string $view): self;
}
```

`Output` is **immutable**. Use the withers to derive new instances:

```php
$output = new Output(OutputType::Render, ['articles' => $articles], 'index');

// Add a parameter without losing the existing ones:
$output = $output->withMergedParams(['flash' => 'Saved.']);

// Replace the view:
$output = $output->withView('detail');
```

## `OutputType` enum

```php
namespace Blackcube\Handler;

enum OutputType: string
{
    case Render   = 'render';
    case Partial  = 'partial';
    case Json     = 'json';
    case Redirect = 'redirect';
    case Download = 'download';
}
```

| Type | `params` shape | `view` required |
|---|---|---|
| `Render` | view parameters (mixed) | yes |
| `Partial` | view parameters (mixed) | yes |
| `Json` | the JSON payload to encode | no |
| `Redirect` | `['route' => string, 'params' => array]` | no |
| `Download` | `['filename' => string, 'mimeType' => string]` (optional) | no — but `download()` must return the bytes |

## Complete example

A list page with search and pagination, anonymized from a production handler:

```php
<?php

declare(strict_types=1);

namespace App\Handlers\Admin\Articles;

use App\Data\ActiveQueryPaginator;
use App\Forms\ArticleSearchForm;
use App\Models\Article;
use Blackcube\Handler\AbstractPipelineHandler;
use Blackcube\Handler\Output;
use Blackcube\Handler\OutputType;

final class Index extends AbstractPipelineHandler
{
    private const PAGE_SIZE = 20;

    private ArticleSearchForm $searchForm;
    private ActiveQueryPaginator $paginator;

    protected function setup(): ?Output
    {
        $this->searchForm = new ArticleSearchForm();
        $this->searchForm->load($this->request->getQueryParams(), '');

        $bodyParams = $this->getBodyParams();
        if ($bodyParams !== null) {
            $this->searchForm->load($bodyParams);
        }

        $this->searchForm->validate();

        $query = Article::query()
            ->andWhere(['deletedAt' => null]);

        $search = trim($this->searchForm->getSearch());
        if ($search !== '') {
            $query->andWhere(['or',
                ['like', 'title', $search],
                ['like', 'slug',  $search],
            ]);
        }

        $query->orderBy(['createdAt' => SORT_DESC]);

        $this->paginator = (new ActiveQueryPaginator($query))
            ->withPageSize(self::PAGE_SIZE)
            ->withCurrentPage($this->searchForm->getPage());

        return null;
    }

    protected function process(): Output
    {
        return new Output(OutputType::Render, [
            'urlGenerator' => $this->urlGenerator,
            'paginator'    => $this->paginator,
            'searchForm'   => $this->searchForm,
        ], 'index');
    }
}
```

The handler:
- Loads the search form from query string + body in `setup()`.
- Builds the paginated query.
- Returns an `Output` for rendering — no `output()` override needed.

The view name `index` is rewritten to `//Admin/Articles/index` by layer 1's `resolveView()`, then to `@src/Views/Admin/Articles/index.php` by Yii.

## Short-circuit example

A handler that imports a file and re-renders the same page in success or error:

```php
<?php

declare(strict_types=1);

namespace App\Handlers\Admin\Imports;

use Blackcube\Handler\AbstractPipelineHandler;
use Blackcube\Handler\Output;
use Blackcube\Handler\OutputType;

final class Articles extends AbstractPipelineHandler
{
    private bool $success = false;
    private ?string $error = null;
    private ?array $results = null;

    protected function setup(): ?Output
    {
        $bodyParams = $this->getBodyParams();
        if ($bodyParams === null || !isset($bodyParams['importFile'])) {
            return null;
        }

        $filePath = (string) ($bodyParams['importFile'] ?? '');
        if ($filePath === '') {
            $this->error = 'No file selected.';
            return null;
        }

        try {
            $this->results = $this->importService->import($filePath);
            $this->success = true;
        } catch (\Throwable $e) {
            $this->error = 'Import failed: ' . $e->getMessage();
            $this->logger->error('Article import failed: ' . $e->getMessage());
        }

        return null;
    }

    protected function process(): Output
    {
        return new Output(OutputType::Render, [
            'success' => $this->success,
            'error'   => $this->error,
            'results' => $this->results,
        ], 'index');
    }
}
```

## Download example

A handler that exports articles as XLSX:

```php
final class Export extends AbstractPipelineHandler
{
    private string $xlsx = '';

    protected function process(): Output
    {
        $this->xlsx = $this->excelService->buildArticlesXlsx();

        return new Output(OutputType::Download, [
            'filename' => 'articles-' . date('Ymd') . '.xlsx',
            'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    protected function download(): ?string
    {
        return $this->xlsx;
    }
}
```

`process()` declares the type and metadata. `download()` returns the bytes. The pipeline's `output()` dispatcher wires the two together.
