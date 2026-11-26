<?php

declare(strict_types=1);

namespace Componenta\Error\App\Listener;

use Componenta\Error\Context\HttpErrorContextInterface;
use Componenta\Error\Context\HttpContext;
use Componenta\Error\Event\ErrorEventInterface;
use Componenta\Error\Listener\AbstractErrorListener;
use Componenta\Error\Renderer\PrettyPageRenderer;

final readonly class HtmlLogListener extends AbstractErrorListener
{
    private PrivateLogStorage $storage;

    public function __construct(
        string $logDir,
        private PrettyPageRenderer $renderer,
        private bool $enabled = false,
    ) {
        $this->storage = new PrivateLogStorage($logDir);
    }

    public function handleEvent(ErrorEventInterface $event): void
    {
        if (!$this->enabled || !$event->context instanceof HttpErrorContextInterface) {
            return;
        }

        // Keep original request metadata in the diagnostic snapshot. A
        // non-debug PrettyPageRenderer can negotiate JSON, so only that path
        // needs a separate HTML representation.
        $rendered = $this->renderer->renderHttp($event->exception, $event->context);
        if (strtolower(trim(explode(';', $rendered->contentType, 2)[0])) !== 'text/html') {
            $context = new HttpContext(
                $event->context->request->withHeader('Accept', 'text/html'),
                $event->context->getAttributes(),
            );
            $rendered = $this->renderer->renderHttp($event->exception, $context);
        }
        if (strtolower(trim(explode(';', $rendered->contentType, 2)[0])) !== 'text/html') {
            throw new \RuntimeException('The error snapshot renderer did not produce HTML.');
        }
        $html = $rendered->body;
        if (strlen($html) > 2_097_152) {
            throw new \RuntimeException('Error snapshot exceeds the storage limit.');
        }
        $correlation = $event->errorId === null ? 'uncorrelated' : hash('sha256', $event->errorId);
        $name = $event->timestamp->format('Ymd-His') . '_' . $correlation . '_' . bin2hex(random_bytes(16)) . '.html';
        $this->storage->create($name, $html);
    }
}
