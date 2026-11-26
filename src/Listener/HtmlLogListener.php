<?php

declare(strict_types=1);

namespace Componenta\Error\App\Listener;

use Componenta\Error\Context\HttpErrorContextInterface;
use Componenta\Error\ErrorContextInterface;
use Componenta\Error\ErrorOccurrence;
use Componenta\Error\Event\ErrorEventInterface;
use Componenta\Error\Listener\AbstractErrorListener;
use Componenta\Error\Renderer\ErrorRendererInterface;
use UnexpectedValueException;

final readonly class HtmlLogListener extends AbstractErrorListener
{
    private PrivateLogStorage $storage;

    public function __construct(
        string $logDir,
        private ErrorRendererInterface $renderer,
        private bool $enabled = false,
    ) {
        $this->storage = new PrivateLogStorage($logDir);
    }

    public function supports(ErrorOccurrence $error, ErrorContextInterface $context): bool
    {
        return $this->enabled
            && $context instanceof HttpErrorContextInterface
            && $this->renderer->supports($error, $context);
    }

    public function handleEvent(ErrorEventInterface $event): void
    {
        if (!$this->supports($event->error, $event->context)) {
            return;
        }

        $rendered = $this->renderer->render($event->error, $event->context);
        if (strtolower(trim(explode(';', $rendered->contentType, 2)[0])) !== 'text/html') {
            throw new UnexpectedValueException('The error snapshot renderer must produce text/html.');
        }
        $html = $rendered->content;
        if (strlen($html) > 2_097_152) {
            throw new \RuntimeException('Error snapshot exceeds the storage limit.');
        }
        $correlation = hash('sha256', $event->error->id);
        $name = $event->timestamp->format('Ymd-His') . '_' . $correlation . '_' . bin2hex(random_bytes(16)) . '.html';
        $this->storage->create($name, $html);
    }
}
