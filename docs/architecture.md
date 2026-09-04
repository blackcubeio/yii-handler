# Architecture

The package is organized as three concentric layers. Each layer extends the one below and adds capabilities. You pick the lowest layer that gives you what you need.

```
┌─────────────────────────────────────────────────────────────┐
│  Layer 3                                                    │
│  Dialog\AbstractDialogInit / Dialog\AbstractDialogConfirm   │
│                                                             │
│  + SessionInterface                                         │
│  + AJAX dialog flow (submit/init/confirm via session)       │
│  ↓ extends                                                  │
├─────────────────────────────────────────────────────────────┤
│  Layer 2                                                    │
│  AbstractPipelineHandler                                    │
│                                                             │
│  + CurrentRoute                                             │
│  + 4-step pipeline: setup → setupMethod → process →         │
│                     prepareOutputData → output              │
│  + Output DTO (immutable, with withers)                     │
│  + OutputType enum (Render | Partial | Json |               │
│                     Redirect | Download)                    │
│  ↓ extends                                                  │
├─────────────────────────────────────────────────────────────┤
│  Layer 1                                                    │
│  AbstractHandler                                            │
│                                                             │
│  + LoggerInterface, HandlerConfig, WebViewRenderer,         │
│    ResponseFactoryInterface, JsonResponseFactoryInterface,  │
│    UrlGeneratorInterface, Aliases                           │
│  + render / renderPartial / renderJson / redirect           │
│  + downloadContent / downloadFile                           │
│  + getBodyParams / isAjax / isAjaxify                       │
│  + view path resolution (//path convention)                 │
│  + getLayout() / getViewPath() (overridable)                │
└─────────────────────────────────────────────────────────────┘
                          ↓ implements
                ┌──────────────────────┐
                │ RequestHandlerInterf │
                │      (PSR-15)        │
                └──────────────────────┘
```

## Layer 1 — `AbstractHandler`

The shared trunk. Every Blackcube handler eventually relies on this layer.

**What it gives you:**
- All the response builders (`render`, `renderPartial`, `renderJson`, `redirect`, `downloadContent`, `downloadFile`).
- Request helpers (`getBodyParams`, `isAjax`, `isAjaxify`).
- Automatic view path resolution: a simple view name like `detail` is rewritten to `//Admin/Articles/detail` based on the handler's class namespace and `HandlerConfig::handlerNamespacePrefix`.
- A configurable view path (`getViewPath()`) and layout (`getLayout()`), both overridable per handler.

**What it does not give you:**
- A `handle()` implementation. The base class implements `RequestHandlerInterface` but leaves `handle()` to subclasses. You write it yourself, or you extend layer 2 / 3 which provides it.
- Access to the route arguments. You inject `CurrentRoute` yourself if you need it at this layer (or extend layer 2).

**Use it when:**
- You write a one-shot handler that does its own thing in `handle()`.
- You write the base handler for a small app that does not need the pipeline structure.

See [abstract-handler.md](abstract-handler.md) for the full reference.

## Layer 2 — `AbstractPipelineHandler`

Adds a structured 4-step pipeline and an immutable `Output` DTO.

```
handle($request)
   │
   ├── $this->request = $request
   │
   ├── setup()             ─┐ may return Output → short-circuit to output()
   │                         │ (e.g. 404 if entity not found, redirect if expired)
   │
   ├── setupMethod()       ─┘ same: short-circuit on validation error, etc.
   │
   ├── process()             returns Output (mandatory; the only abstract step)
   │
   ├── prepareOutputData()   hook to complete/modify the Output before render
   │
   └── output(Output)        dispatches to render / json / redirect / download
```

**What it gives you:**
- A clear separation between data loading, verb handling, business logic and output generation.
- An immutable `Output` value object that decouples *what to return* from *how to send it*.
- A short-circuit mechanism: any step that returns `Output` skips the rest of the pipeline.
- An `output()` dispatcher driven by an `OutputType` enum (`Render`, `Partial`, `Json`, `Redirect`, `Download`).
- A `download()` extension point for handlers that produce binary content (PDF, Excel, ZIP).
- `CurrentRoute` injected for route-argument lookups.

**Use it when:**
- Your handler loads data, possibly mutates it, then renders something.
- Your handler may return *several kinds* of response (JSON for AJAX, full page for navigation, redirect after save).
- You want a place for verb-specific behavior (`POST` validates and saves, `GET` just renders).

See [pipeline-handler.md](pipeline-handler.md) for the full reference.

## Layer 3 — `Dialog\AbstractDialogInit` and `AbstractDialogConfirm`

Two specialized pipeline handlers for AJAX dialogs.

The dialog flow has three HTTP exchanges:

```
1. User clicks "Delete" on row
        │
        │  POST /article/{id}/delete       ← AbstractDialogInit (submit mode)
        ▼
   handler validates the form,
   stores it in the session under a confirmationId,
   returns JSON { modalUrl: '/article/{id}/delete/{confirmationId}' }

2. Front opens the modal at modalUrl
        │
        │  GET /article/{id}/delete/{confirmationId}   ← AbstractDialogInit (init mode)
        ▼
   handler reloads from session,
   renders the modal HTML inside a JSON envelope (AureliaCommunication)

3. User clicks "Confirm" inside the modal
        │
        │  POST /article/{id}/delete/{confirmationId}/confirm  ← AbstractDialogConfirm
        ▼
   handler reloads from session, revalidates,
   performs the action (delete, save…),
   returns JSON { toast, dialog: close }
```

**What it gives you:**
- The session bookkeeping (key prefix, confirmationId generation, expiration handling).
- The validation re-run between submit and confirm (defense in depth).
- Standard error JSON responses (`AureliaCommunication::dialog/toast`) for session-expired or validation-failed cases.

**Use it when:**
- You build a confirmation modal triggered from a list or detail view.
- You implement a two-step action that should not be irreversible from a single click (delete, validate, publish, escalate).

See [dialog.md](dialog.md) for the full reference and a complete example.

## Choosing a layer

| Question | Answer |
|---|---|
| Do I need a confirmation modal flow over AJAX? | Layer 3 |
| Do I need a structured handler with data loading, business logic and a typed response? | Layer 2 |
| Do I just need to render a view or return a JSON? | Layer 1 |

When in doubt, start at the lowest layer that gives you the response builders, then promote upward if your handler grows.
