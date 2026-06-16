<?php

declare(strict_types=1);

namespace Componenta\Error\App\Listener;

use Componenta\Error\Context\CliErrorContextInterface;
use Componenta\Error\Context\HttpErrorContextInterface;
use Componenta\Error\Event\ErrorEventInterface;
use Componenta\Error\Listener\AbstractErrorListener;
use Throwable;

final readonly class FileLogListener extends AbstractErrorListener
{
    public function __construct(
        private string $logDir,
    ) {
    }

    public function handleEvent(ErrorEventInterface $event): void
    {
        try {
            file_put_contents($this->resolveFilePath($event), $this->format($event), FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
        }
    }

    private function resolveFilePath(ErrorEventInterface $event): string
    {
        $this->ensureDirectory();

        return $this->logDir . '/error-' . $event->timestamp->format('Y-m-d') . '.log';
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->logDir)) {
            return;
        }

        if (!@mkdir($this->logDir, 0755, true) && !is_dir($this->logDir)) {
            return;
        }
    }

    private function format(ErrorEventInterface $event): string
    {
        $exception = $event->exception;
        $context = $event->context;
        $lines = [
            sprintf('[%s] %s', $event->timestamp->format('Y-m-d H:i:s'), $this->level($exception)),
            sprintf('%s: %s', $exception::class, $exception->getMessage()),
            sprintf('in %s:%d', $exception->getFile(), $exception->getLine()),
        ];

        $scope = $context->getAttribute('scope', null);

        if (is_string($scope) && $scope !== '') {
            $lines[] = 'scope: ' . $scope;
        }

        if ($context instanceof HttpErrorContextInterface) {
            $request = $context->request;
            $lines[] = sprintf('request: %s %s', $request->getMethod(), (string) $request->getUri());
        }

        if ($context instanceof CliErrorContextInterface) {
            $lines[] = 'command: ' . json_encode($context->input->getArguments(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $lines[] = $exception->getTraceAsString();
        $lines[] = "---\n";

        return implode("\n", $lines);
    }

    private function level(Throwable $exception): string
    {
        return $exception instanceof \Error ? 'critical' : 'error';
    }
}
