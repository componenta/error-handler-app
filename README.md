# Componenta Error Handler App

Application integration for Componenta error handling. The package registers debug-aware renderers and file/HTML logging listeners used by the base `componenta/error-handler` services.

The base error contracts, contexts, handlers, renderers, middleware, and response generators live in `componenta/error-handler`. This package wires them into a Componenta application through `Componenta\Error\App\ConfigProvider`.

## Installation

```bash
composer require componenta/error-handler-app
```

The package exposes `Componenta\Error\App\ConfigProvider` through Composer metadata.

## Debug Behavior

`APP_DEBUG=true` controls whether the HTTP renderer shows detailed exception output. When debug is disabled, the application renderer uses the safe `templates/error/500.phtml` template through `Componenta\Error\Renderer\SafeRenderer`.

`EnvDebugResolver` uses the composed Config environment snapshot when available. During bootstrap it checks the local process environment, then `$_ENV`, then the project's `.env` and `.env.local` files using Config's dotenv parser. Later assignments override earlier ones. Explicit false values, invalid or unreadable configuration disable debug.

Values `1`, `true`, `yes`, and `on` enable debug. A missing `APP_DEBUG` and other values disable debug.

## Logging

`Componenta\Error\App\Listener\FileLogListener` writes structured JSON Lines logs. `Componenta\Error\App\Listener\HtmlLogListener` writes HTML snapshots for browser inspection. Paths are resolved through `componenta/path-resolver`.

`FileLogListener` is registered for HTTP and CLI errors. `HtmlLogListener` is registered only for HTTP errors and writes snapshots only when `APP_DEBUG` is enabled.

## Registered Services

`Componenta\Error\App\ConfigProvider` configures:

| Key or service | Value |
|---|---|
| `ErrorConfigKey::HTTP_LISTENERS` | `FileLogListener`, `HtmlLogListener` |
| `ErrorConfigKey::CLI_LISTENERS` | `FileLogListener` |
| `ErrorConfigKey::HTTP_RENDERER` | `PrettyPageRenderer(debug: true)` in debug, otherwise `SafeRenderer` with `templates/error/500.phtml`. |
| `ErrorConfigKey::CLI_RENDERER` | `PlainTextRenderer` with debug flag from the environment resolver. |
| `SafeRenderer::class` | Safe renderer bound to `templates/error/500.phtml`. |

This package does not replace the base handlers from `componenta/error-handler`; it only supplies application renderers and listeners used by those handlers.

## Related Packages

- [`componenta/error-handler`](../error-handler/README.md) defines the core error handling contracts and runtime handlers.
- [`componenta/http-emitter`](../http-emitter/README.md) emits HTTP responses.
- [`componenta/skeleton`](../../componenta-skeleton/README.md) shows how application entry points log bootstrap failures and render the safe 500 template for HTTP flows.

## Version 2 migration and operational security

Requires Config 4 and Error Handler 2. The package no longer depends on an application runtime or HTTP emitter. Both log listeners write to `log/error-handler` by default. Log records carry an error ID for correlation; request URIs and command arguments are omitted. On POSIX systems the log directory must be private (0700) and files use 0600. HTML snapshots are exclusive files, limited to 2 MiB per snapshot, and disabled by default when constructed manually. Keep the directory outside the document root and configure rotation and retention with your deployment tooling. Debug output may contain sensitive exception messages and source code; enable it only in trusted environments.
