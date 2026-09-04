# Dialog handlers — Layer 3

`Dialog\AbstractDialogInit` and `Dialog\AbstractDialogConfirm` are two specialized pipeline handlers that implement the AJAX dialog flow used throughout the Blackcube admin: submit a form → open a confirmation modal → confirm.

```
namespace Blackcube\Handler\Dialog;

abstract class AbstractDialogInit    extends Blackcube\Handler\AbstractPipelineHandler
abstract class AbstractDialogConfirm extends Blackcube\Handler\AbstractPipelineHandler
```

## Prerequisites

The dialog layer depends on three external packages, declared as `suggest` in `composer.json`:

| Package | Why |
|---|---|
| `blackcube/yii-bleet` | `AureliaCommunication`, `DialogAction`, `UiColor` — the JSON envelope expected by the front-end Aurelia widgets |
| `blackcube/yii-bridge-model` | `BridgeFormModel` — the form model contract |
| `yiisoft/session` | `SessionInterface` — to persist the form between submit and confirm |

If you don't use Bleet, ignore this layer.

## The flow

```
                    ┌────────────┐
                    │   User     │
                    │  clicks    │
                    │  "Delete"  │
                    └──────┬─────┘
                           │
                           ▼
   ┌─────────────────────────────────────────────────────┐
   │  1.  POST /article/{id}/delete                      │
   │      no confirmationId in route                     │
   │      → AbstractDialogInit (submit mode)             │
   │                                                      │
   │      • createForm() → BridgeFormModel               │
   │      • form->load(bodyParams)                       │
   │      • form->validate()                             │
   │      • storeInSession([bodyParams, …])              │
   │      • return JSON { modalUrl: …confirmationId }    │
   └─────────────────────────────────────────────────────┘
                           │
                           ▼
                  ┌──────────────────┐
                  │ Front opens      │
                  │ modalUrl         │
                  └────────┬─────────┘
                           │
                           ▼
   ┌─────────────────────────────────────────────────────┐
   │  2.  GET /article/{id}/delete/{confirmationId}      │
   │      confirmationId in route                        │
   │      → AbstractDialogInit (init mode)               │
   │                                                      │
   │      • setup() loads sessionData                    │
   │      • handleInit() renders the modal HTML          │
   │        wrapped in AureliaCommunication JSON         │
   │      • return JSON { dialogContent, header, body }  │
   └─────────────────────────────────────────────────────┘
                           │
                           ▼
                  ┌──────────────────┐
                  │ User clicks      │
                  │ "Confirm"        │
                  └────────┬─────────┘
                           │
                           ▼
   ┌─────────────────────────────────────────────────────┐
   │  3.  POST /article/{id}/delete/{confirmationId}/    │
   │            confirm                                   │
   │      → AbstractDialogConfirm                         │
   │                                                      │
   │      • setup() reloads sessionData                   │
   │      • session->remove(sessionKey)                   │
   │      • createForm() + form->load(bodyParams)         │
   │      • form->validate()                              │
   │      • handleConfirm() performs the action          │
   │      • return JSON { toast, dialog: close }          │
   └─────────────────────────────────────────────────────┘
```

The session is the glue. The `confirmationId` (16 random bytes, hex) lets the front pass the same context across the three exchanges without exposing internal IDs.

## `AbstractDialogInit` reference

Handles steps 1 and 2 in the diagram above. Whether the handler is in *submit* or *init* mode is decided by the presence of a `confirmationId` argument in the current route.

### Constructor

```php
public function __construct(
    LoggerInterface $logger,
    HandlerConfig $config,
    WebViewRenderer $viewRenderer,
    ResponseFactoryInterface $responseFactory,
    JsonResponseFactory $jsonResponseFactory,
    UrlGeneratorInterface $urlGenerator,
    Aliases $aliases,
    CurrentRoute $currentRoute,
    protected SessionInterface $session,
)
```

Same as `AbstractPipelineHandler` plus `SessionInterface`.

### Properties

| Property | Type | Set in | Notes |
|---|---|---|---|
| `$form` | `?BridgeFormModel` | `setup()` (submit mode) | The form instance built by `createForm()` |
| `$sessionData` | `?array` | `setup()` (init mode) | The data restored from session |
| `$confirmationId` | `?string` | `setup()` | `null` in submit mode, the route value in init mode |
| `$isInit` | `bool` | `setup()` | `true` if a `confirmationId` was found in the route |

### Abstract methods

```php
abstract protected function getSessionPrefix(): string;
abstract protected function createForm(): BridgeFormModel;
abstract protected function handleSubmit(BridgeFormModel $form, ?array $bodyParams): Output;
abstract protected function handleInit(array $data, string $confirmationId): Output;
```

| Method | Called when | Returns |
|---|---|---|
| `getSessionPrefix()` | always | The string prefix for session keys (e.g. `'article_delete_'`) |
| `createForm()` | always | A fresh `BridgeFormModel` instance |
| `handleSubmit()` | submit mode (no confirmationId in route) | The JSON `Output` describing the modal URL the front should open |
| `handleInit()` | init mode (confirmationId in route) | The JSON `Output` containing the modal content (header + body) |

### `storeInSession(array $data): string`

Generates a 16-byte hex `confirmationId`, stores `$data` under `getSessionPrefix() . $confirmationId`, and returns the id. Call it from `handleSubmit()`.

### Built-in error handling

`setup()` covers two failure modes automatically:

- **Session expired** (init mode, no data found): returns a JSON `Output` that closes the dialog and displays a danger toast.
- **Validation failed** (submit mode): returns a JSON `Output` that displays a danger toast asking the user to check the form.

You don't have to handle these cases in `handleSubmit()` or `handleInit()`.

## `AbstractDialogConfirm` reference

Handles step 3. Reloads from session, removes the session entry, revalidates and runs the action.

### Constructor

Same as `AbstractDialogInit`.

### Properties

| Property | Type | Notes |
|---|---|---|
| `$form` | `?BridgeFormModel` | Reloaded from session in `setup()` |
| `$sessionData` | `?array` | Loaded then removed from session in `setup()` |

### Abstract methods

```php
abstract protected function getSessionPrefix(): string;
abstract protected function createForm(): BridgeFormModel;
abstract protected function handleConfirm(BridgeFormModel $form, array $data): Output;
```

| Method | Called when | Returns |
|---|---|---|
| `getSessionPrefix()` | always | Same prefix as the matching `AbstractDialogInit` |
| `createForm()` | always | Same form as the matching `AbstractDialogInit` |
| `handleConfirm()` | after session reload + revalidation | The JSON `Output` (typically a success toast and a `DialogAction::Close`) |

### Built-in error handling

`setup()` covers:
- **Session expired**: closes the dialog and shows a danger toast.
- **Revalidation failed**: closes the dialog and shows a danger toast (defense in depth — the data was already validated at submit, but we re-check on confirm).

`handleConfirm()` is only called when the form is valid and the session data is present.

## Complete example — delete with confirmation

Anonymized from a production handler. The action is "delete an article", with a confirmation modal that recaps which article will be deleted.

### Routes

```
POST   /admin/articles/{id}/delete                            → DeleteSubmit
GET    /admin/articles/{id}/delete/{confirmationId}           → DeleteSubmit (init mode)
POST   /admin/articles/{id}/delete/{confirmationId}/confirm   → DeleteConfirm
```

The `DeleteSubmit` handler covers both submit and init modes — `AbstractDialogInit` decides based on the route argument.

### `DeleteSubmit.php`

```php
<?php

declare(strict_types=1);

namespace App\Handlers\Admin\Articles;

use App\Forms\ArticleDeleteForm;
use Blackcube\Bleet\Enums\DialogAction;
use Blackcube\Bleet\Enums\UiColor;
use Blackcube\Bleet\Helper\AureliaCommunication;
use Blackcube\BridgeModel\BridgeFormModel;
use Blackcube\Handler\Dialog\AbstractDialogInit;
use Blackcube\Handler\Output;
use Blackcube\Handler\OutputType;

final class DeleteSubmit extends AbstractDialogInit
{
    protected function getSessionPrefix(): string
    {
        return 'article_delete_';
    }

    protected function createForm(): BridgeFormModel
    {
        $form = new ArticleDeleteForm();
        $form->setScenario(ArticleDeleteForm::SCENARIO_CONFIRMATION);
        return $form;
    }

    protected function handleSubmit(BridgeFormModel $form, ?array $bodyParams): Output
    {
        $articleId = (string) $this->currentRoute->getArgument('id');

        $confirmationId = $this->storeInSession([
            'bodyParams' => $bodyParams,
            'articleId'  => $articleId,
        ]);

        return new Output(OutputType::Json, [
            'modalUrl' => $this->urlGenerator->generate('admin.articles.delete.modal', [
                'id'             => $articleId,
                'confirmationId' => $confirmationId,
            ]),
        ]);
    }

    protected function handleInit(array $data, string $confirmationId): Output
    {
        $articleId = $data['articleId'];

        $header = (string) $this->renderPartial('//Commons/_confirm-header', [
            'titre' => 'Delete this article?',
            'type'  => UiColor::Danger,
        ])->getBody();

        $confirmUrl = $this->urlGenerator->generate('admin.articles.delete.confirm', [
            'id'             => $articleId,
            'confirmationId' => $confirmationId,
        ]);

        $body = (string) $this->renderPartial('_delete-content', [
            'articleId' => $articleId,
        ])->getBody();

        $content = (string) $this->renderPartial('//Commons/_confirm-content', [
            'confirmUrl'  => $confirmUrl,
            'content'     => $body,
            'boutonLabel' => 'Delete',
            'boutonType'  => UiColor::Danger,
        ])->getBody();

        return new Output(OutputType::Json, [
            ...AureliaCommunication::dialog(DialogAction::Keep),
            ...AureliaCommunication::dialogContent($header, $content, UiColor::Danger),
        ]);
    }
}
```

### `DeleteConfirm.php`

```php
<?php

declare(strict_types=1);

namespace App\Handlers\Admin\Articles;

use App\Forms\ArticleDeleteForm;
use App\Models\Article;
use Blackcube\Bleet\Enums\DialogAction;
use Blackcube\Bleet\Enums\UiColor;
use Blackcube\Bleet\Helper\AureliaCommunication;
use Blackcube\BridgeModel\BridgeFormModel;
use Blackcube\Handler\Dialog\AbstractDialogConfirm;
use Blackcube\Handler\Output;
use Blackcube\Handler\OutputType;

final class DeleteConfirm extends AbstractDialogConfirm
{
    protected function getSessionPrefix(): string
    {
        return 'article_delete_';
    }

    protected function createForm(): BridgeFormModel
    {
        $form = new ArticleDeleteForm();
        $form->setScenario(ArticleDeleteForm::SCENARIO_CONFIRMATION);
        return $form;
    }

    protected function handleConfirm(BridgeFormModel $form, array $data): Output
    {
        $article = Article::query()
            ->andWhere(['id' => $data['articleId']])
            ->one();

        if ($article === null) {
            return new Output(OutputType::Json, [
                ...AureliaCommunication::dialog(DialogAction::Close),
                ...AureliaCommunication::toast('Error', 'Article not found.', UiColor::Danger),
            ]);
        }

        $article->softDelete();

        return new Output(OutputType::Json, [
            ...AureliaCommunication::dialog(DialogAction::Close),
            ...AureliaCommunication::toast('Deleted', 'The article has been deleted.', UiColor::Success),
        ]);
    }
}
```

### Notes on the example

- **`getSessionPrefix()` must match** between `DeleteSubmit` and `DeleteConfirm`. They are two classes but one logical flow — they share the session key.
- **`createForm()` must match** too. The same form class with the same scenario is reinstantiated in both handlers, so the validation rules are identical.
- **The shared partials** `//Commons/_confirm-header` and `//Commons/_confirm-content` use the absolute `//path` syntax — they live outside the handler's namespace folder and are reused by every dialog in the application.
- **Session expiration is automatic.** If the user closes the modal and waits an hour, the next confirm click hits a missing session key. Both `AbstractDialogInit` (init mode) and `AbstractDialogConfirm` return a JSON `Output` that closes the dialog and shows a "session expired" toast — without you writing a single line.
- **Revalidation on confirm** is also automatic. Even though the form was validated at submit, `AbstractDialogConfirm::setup()` re-runs `validate()` before calling `handleConfirm()`. If the data has been tampered with in the session, the confirm aborts.

## When to use which class

| You are building… | Use |
|---|---|
| The submit endpoint that opens the modal | `AbstractDialogInit` |
| The init endpoint that fills the modal HTML | `AbstractDialogInit` (same class — it auto-detects mode) |
| The confirm endpoint that runs the action | `AbstractDialogConfirm` |
| A dialog with no two-step flow (one click → done) | Don't use the dialog layer — use `AbstractPipelineHandler` directly |
