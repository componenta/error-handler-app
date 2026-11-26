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

`EnvDebugResolver` использует снимок окружения собранного Config. При начальной загрузке проверяются локальное окружение процесса, затем `$_ENV`, затем файлы `.env` и `.env.local` через парсер Config. Более поздние присваивания имеют приоритет. Явное отключение, некорректная или недоступная конфигурация не включают отладку.

Значения `1`, `true`, `yes` и `on` включают отладку. Отсутствующий `APP_DEBUG` и любые другие значения отключают отладку.

## Логирование

`Componenta\Error\App\Listener\FileLogListener` пишет структурированные записи JSON Lines. `Componenta\Error\App\Listener\HtmlLogListener` пишет HTML-снимки для просмотра в браузере. Пути разрешаются через `componenta/path-resolver`.

`FileLogListener` регистрируется для HTTP и CLI ошибок. `HtmlLogListener` регистрируется только для HTTP ошибок и пишет снимки только при включенном `APP_DEBUG`.

## Регистрируемые сервисы

`Componenta\Error\App\ConfigProvider` настраивает:

| Ключ или сервис | Значение |
|---|---|
| `ErrorConfigKey::HTTP_LISTENERS` | `FileLogListener`, `HtmlLogListener` |
| `ErrorConfigKey::CLI_LISTENERS` | `FileLogListener` |
| `ErrorConfigKey::HTTP_RENDERER` | `PrettyPageRenderer(debug: true)` в режиме отладки, иначе `SafeRenderer` с `templates/error/500.phtml`. |
| `ErrorConfigKey::CLI_RENDERER` | `PlainTextRenderer(debug: true)` в режиме отладки, иначе `SafeCliRenderer`. |
| `SafeRenderer::class` | Безопасный рендерер, привязанный к `templates/error/500.phtml`. |

Пакет не заменяет базовые обработчики из `componenta/error-handler`; он только предоставляет рендереры и слушатели приложения, которые используют эти обработчики.

## Связанные пакеты

- [`componenta/error-handler`](../error-handler/README.ru.md) определяет базовые контракты и обработчики ошибок.
- [`componenta/http-emitter`](../http-emitter/README.ru.md) отправляет HTTP-ответы.
- [`componenta/skeleton`](../../componenta-skeleton/README.ru.md) показывает, как точки входа приложения логируют ошибки загрузки и рендерят безопасный шаблон 500 для HTTP-сценариев.

## Переход на версию 2 и безопасность эксплуатации

Требуются Config 4 и Error Handler 2. Зависимости от среды выполнения приложения и HTTP-эмиттера удалены. По умолчанию журналы сохраняются в `log/error-handler`. Записи содержат идентификатор ошибки; URI запросов и аргументы команд не записываются. На POSIX-системах каталог должен быть закрытым (0700), файлы создаются с правами 0600. HTML-снимки создаются без перезаписи, ограничены 2 МиБ на снимок и по умолчанию отключены при самостоятельном создании слушателя. Размещайте каталог вне публичной директории и настройте ротацию и срок хранения средствами окружения. Отладочный вывод может содержать конфиденциальные сообщения исключений и исходный код; включайте его только в доверенной среде.

Рендереры возвращают объект `RenderedError` из ядра (`content`, `contentType`, `Stringable`). HTML-снимки используют этот же контракт без отдельного метода HTTP-рендеринга.
