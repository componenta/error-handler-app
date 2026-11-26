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
| `ConfigKey::ERROR_LISTENERS` | `FileLogListener`, `HtmlLogListener` |
| `ErrorConfigKey::HTTP_RENDERER` | `PrettyPageRenderer(debug: true)` in debug, otherwise `SafeRenderer` with `templates/error/500.phtml`. |
| `ErrorConfigKey::CLI_RENDERER` | `PlainTextRenderer(debug: true)` in debug mode, otherwise `SafeCliRenderer`. |
| `SafeRenderer::class` | Safe renderer bound to `templates/error/500.phtml`. |

This package supplies the framework listener-provider and reporting-policy factories, as well as application renderers and listeners. Core handlers remain in error-handler.

## Related Packages

- [`componenta/error-handler`](../error-handler/README.md) defines the core error handling contracts and runtime handlers.
- [`componenta/http-emitter`](../http-emitter/README.md) emits HTTP responses.
- [`componenta/skeleton`](../../componenta-skeleton/README.md) shows how application entry points log bootstrap failures and render the safe 500 template for HTTP flows.

## Version 2 migration and operational security

Requires Config 4 and Error Handler 2. The package no longer depends on an application runtime or HTTP emitter. Both log listeners write to `log/error-handler` by default. Log records carry an error ID for correlation; request URIs and command arguments are omitted. On POSIX systems the log directory must be private (0700) and files use 0600. HTML snapshots are exclusive files, limited to 2 MiB per snapshot, and disabled by default when constructed manually. Keep the directory outside the document root and configure rotation and retention with your deployment tooling. Debug output may contain sensitive exception messages and source code; enable it only in trusted environments.

Renderers return the core `RenderedError` value (`content`, `contentType`, `Stringable`). HTML snapshots consume this same contract, without a separate HTTP rendering method.

The core registers a single transport-independent reporter. File logging handles HTTP and CLI events, while HTML snapshots support only HTTP when debug is enabled. Each listener consumes the mandatory `ErrorOccurrence` through `$event->error`, and HTTP status comes from the context.

## Listener registration and discovery

Use Componenta\Error\App\ConfigKey for reporting configuration:

```php
return [
    ConfigKey::ERROR_LISTENERS => [
        Throwable::class => [FileLogListener::class],
        RuntimeException::class => [
            ['listener' => MonitoringListener::class, 'priority' => 100],
        ],
    ],
    ConfigKey::ERROR_LISTENER_CLASSES => [CustomAttributedListener::class],
    ConfigKey::NON_REPORTABLE_ERRORS => [ExpectedException::class],
];
```

The application creates a composite provider (explicit registrations first, attribute registrations second). Mark concrete listener classes with #[\Componenta\Error\Event\Attribute\ListenTo(RuntimeException::class, priority: 10)] and make them resolvable by class name in the container. When the application exposes Componenta\ClassFinder\ClassIteratorInterface, its existing discovery results are consumed once during provider construction; no second filesystem scan is launched. Set ConfigKey::DISCOVER_ERROR_LISTENERS to false to use only explicitly configured candidates. If no discovery iterator exists, the explicit class list still works. Resolve ErrorListenerProviderInterface during application initialization to prepare the routing metadata before errors are handled. The core provider never depends on ClassFinder or the application runtime. Clock selection and event creation remain the reporter's responsibility.

The configured log directory and its ancestors must be deployment-controlled. The leaf directory must not be a symlink, including paths with equivalent trailing separators or dot components; file identity is checked again after acquiring the append lock. External rotation may race with writes and must be coordinated with the application.
