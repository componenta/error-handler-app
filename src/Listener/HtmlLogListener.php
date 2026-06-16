<?php

declare(strict_types=1);

namespace Componenta\Error\App\Listener;

use Componenta\Error\Context\HttpErrorContextInterface;
use Componenta\Error\Event\ErrorEventInterface;
use Componenta\Error\Listener\AbstractErrorListener;
use Componenta\Error\Renderer\PrettyPageRenderer;
use ReflectionClass;
use Throwable;

final readonly class HtmlLogListener extends AbstractErrorListener
{
    public function __construct(
        private string $logDir,
        private PrettyPageRenderer $renderer,
        private bool $enabled = true,
    ) {
    }

    public function handleEvent(ErrorEventInterface $event): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$event->context instanceof HttpErrorContextInterface) {
            return;
        }

        try {
            $html = $this->renderer->render($event->exception, $event->context);

            file_put_contents($this->resolveFilePath($event), $html, LOCK_EX);
        } catch (Throwable) {
        }
    }

    private function resolveFilePath(ErrorEventInterface $event): string
    {
        $this->ensureDirectory();

        $timestamp = $event->timestamp->format('d-m-Y-H-i-s');
        $class = (new ReflectionClass($event->exception))->getShortName();
        $base = $this->logDir . '/' . $timestamp . '_' . $class;
        $path = $base . '.html';

        $index = 2;
        while (file_exists($path)) {
            $path = $base . '_' . $index . '.html';
            $index++;
        }

        return $path;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->logDir)) {
            return;
        }

        @mkdir($this->logDir, 0755, true);
    }
}
