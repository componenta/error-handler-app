# Componenta Error Handler App

Интеграция обработки ошибок для Componenta-приложения. Пакет регистрирует рендереры с учетом режима отладки и слушатели файлового/HTML-логирования, которые используют базовые сервисы `componenta/error-handler`.

Базовые контракты, контексты, обработчики, рендереры, промежуточные обработчики и генераторы HTTP-ответов находятся в `componenta/error-handler`. Этот пакет подключает их к приложению через `Componenta\Error\App\ConfigProvider`.

## Установка

```bash
composer require componenta/error-handler-app
```

Пакет публикует `Componenta\Error\App\ConfigProvider` через метаданные Composer.

## Поведение отладки

`APP_DEBUG=true` управляет тем, увидит ли пользователь подробную HTTP-ошибку. Когда отладка отключена, рендерер приложения использует безопасный шаблон `templates/error/500.phtml` через `Componenta\Error\Renderer\SafeRenderer`.

Режим отладки читает `Componenta\Error\App\EnvDebugResolver` напрямую из файла окружения, поэтому это решение не зависит от уже собранной конфигурации приложения.

Значения `1`, `true`, `yes` и `on` включают отладку. Отсутствующий `.env`, отсутствующий `APP_DEBUG` и любые другие значения отключают отладку.

## Логирование

`Componenta\Error\App\Listener\FileLogListener` пишет текстовые логи. `Componenta\Error\App\Listener\HtmlLogListener` пишет HTML-снимки для просмотра в браузере. Пути разрешаются через `componenta/path-resolver`.

`FileLogListener` регистрируется для HTTP и CLI ошибок. `HtmlLogListener` регистрируется только для HTTP ошибок и пишет снимки только при включенном `APP_DEBUG`.

## Регистрируемые сервисы

`Componenta\Error\App\ConfigProvider` настраивает:

| Ключ или сервис | Значение |
|---|---|
| `ErrorConfigKey::HTTP_LISTENERS` | `FileLogListener`, `HtmlLogListener` |
| `ErrorConfigKey::CLI_LISTENERS` | `FileLogListener` |
| `ErrorConfigKey::HTTP_RENDERER` | `PrettyPageRenderer(debug: true)` в режиме отладки, иначе `SafeRenderer` с `templates/error/500.phtml`. |
| `ErrorConfigKey::CLI_RENDERER` | `PlainTextRenderer` с флагом отладки из `.env`. |
| `SafeRenderer::class` | Безопасный рендерер, привязанный к `templates/error/500.phtml`. |

Пакет не заменяет базовые обработчики из `componenta/error-handler`; он только предоставляет рендереры и слушатели приложения, которые используют эти обработчики.

## Связанные пакеты

- [`componenta/error-handler`](../error-handler/README.ru.md) определяет базовые контракты и обработчики ошибок.
- [`componenta/http-emitter`](../http-emitter/README.ru.md) отправляет HTTP-ответы.
- [`componenta/skeleton`](../../componenta-skeleton/README.ru.md) показывает, как точки входа приложения логируют ошибки загрузки и рендерят безопасный шаблон 500 для HTTP-сценариев.
