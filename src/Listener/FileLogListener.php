<?php

declare(strict_types=1);

namespace Componenta\Error\App\Listener;

use Componenta\Error\Context\CliErrorContextInterface;
use Componenta\Error\Context\ErrorContextAttribute;
use Componenta\Error\Context\HttpErrorContextInterface;
use Componenta\Error\Event\ErrorEventInterface;
use Componenta\Error\Listener\AbstractErrorListener;

final readonly class FileLogListener extends AbstractErrorListener
{
    private PrivateLogStorage $storage;

    public function __construct(string $logDir, private bool $includeDebugDetails = false)
    {
        $this->storage = new PrivateLogStorage($logDir);
    }

    public function handleEvent(ErrorEventInterface $event): void
    {
        $exception = $event->exception;
        $context = $event->context;
        $record = [
            'timestamp' => $event->timestamp->format(DATE_ATOM),
            'level' => $exception instanceof \Error ? 'critical' : 'error',
            'error_id' => $event->errorId,
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        if ($context instanceof HttpErrorContextInterface) {
            $record['transport'] = 'http';
            $record['method'] = $context->request->getMethod();
            $status = $context->getAttribute(ErrorContextAttribute::HTTP_STATUS_CODE, null);
            if (is_int($status) && $status >= 100 && $status <= 599) {
                $record['status'] = $status;
            }
        } elseif ($context instanceof CliErrorContextInterface) {
            $record['transport'] = 'cli';
        }

        if ($this->includeDebugDetails) {
            // Messages can contain arbitrary confidential data. This mode must
            // be enabled explicitly only for a trusted diagnostic environment.
            $record['message'] = mb_strcut($exception->getMessage(), 0, 4096, 'UTF-8');
            $record['trace'] = array_map(static fn (array $frame): array => array_intersect_key(
                $frame, array_flip(['file', 'line', 'class', 'function', 'type']),
            ), array_slice($exception->getTrace(), 0, 50));
        }

        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->storage->append('error-' . $event->timestamp->format('Y-m-d') . '.log', $line . "\n");
    }
}
