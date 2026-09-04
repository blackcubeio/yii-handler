# Documentation — blackcube/yii-handler

PSR-15 handler abstractions and pipeline for Yii3.

## What this package gives you

```
┌──────────────────────────────────────────────────────────┐
│  Layer 3 — Dialog\AbstractDialogInit / AbstractDialog…  │
│  AJAX submit → modal → confirm flow over the pipeline    │
├──────────────────────────────────────────────────────────┤
│  Layer 2 — AbstractPipelineHandler                       │
│  setup → setupMethod → process → prepareOutputData → out │
├──────────────────────────────────────────────────────────┤
│  Layer 1 — AbstractHandler                               │
│  render / json / redirect / download / AJAX / view path  │
└──────────────────────────────────────────────────────────┘
```

Each layer extends the previous one. Pick the lowest layer that fits your handler.

## Contents

| File | Topic |
|---|---|
| [installation.md](installation.md) | Composer install, DI wiring, `HandlerConfig` registration |
| [architecture.md](architecture.md) | Three layers in detail, when to choose which |
| [abstract-handler.md](abstract-handler.md) | Layer 1 reference: methods, view resolution, configuration |
| [pipeline-handler.md](pipeline-handler.md) | Layer 2 reference: pipeline steps, `Output`, `OutputType` |
| [dialog.md](dialog.md) | Layer 3 reference: session-backed AJAX dialog flow |

## When to use what

| You want to… | Use |
|---|---|
| Render a single view in a one-shot handler | `AbstractHandler` |
| Load data, run logic and render — with a verb-aware step | `AbstractPipelineHandler` |
| Return JSON / redirect / download from the same handler | `AbstractPipelineHandler` + `OutputType::Json/Redirect/Download` |
| Build a confirmation modal (delete, validate, two-step) | `Dialog\AbstractDialogInit` + `AbstractDialogConfirm` |

## Conventions

- **Namespace prefix.** All handler classes are expected to live under a single namespace prefix (default `App\Handlers\`). This prefix powers the automatic view resolution — see [abstract-handler.md](abstract-handler.md).
- **View names follow Yii native rules.** A view name starting with `/` is treated as basePath-relative (the Yii `//path` convention). A simple name is rewritten by the layer 1 base to mirror the handler folder structure.
- **`Output` is immutable.** The pipeline produces a value object. Use the withers (`withParams`, `withMergedParams`, `withView`, `withType`) to derive new instances.
