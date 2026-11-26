<?php

declare(strict_types=1);

namespace Componenta\Error\App\Listener;

use Componenta\Error\Context\HttpErrorContextInterface;
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

        $html = $this->renderer->render($event->exception, $event->context);
        if (strlen($html) > 2_097_152) {
            throw new \RuntimeException('Error snapshot exceeds the storage limit.');
        }
        $correlation = $event->errorId === null ? 'uncorrelated' : hash('sha256', $event->errorId);
        $name = $event->timestamp->format('Ymd-His') . '_' . $correlation . '_' . bin2hex(random_bytes(16)) . '.html';
        $this->storage->create($name, $html);
    }
}
