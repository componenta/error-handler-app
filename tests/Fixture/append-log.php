<?php

declare(strict_types=1);

use Componenta\Error\App\Listener\FileLogListener;
use Componenta\Error\Context\CliContext;
use Componenta\Error\Event\ErrorEvent;
use Componenta\Error\ErrorOccurrence;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$listener = new FileLogListener($argv[1], includeDebugDetails: true);
$context = new CliContext(new ArrayInput([]), new BufferedOutput());
for ($i = 0; $i < 25; $i++) {
    $listener->handleEvent(new ErrorEvent(
        new ErrorOccurrence(new RuntimeException(str_repeat('record-', 400)), 'worker-' . $argv[2] . '-' . $i),
        $context,
        timestamp: new DateTimeImmutable('2026-01-01T12:00:00Z'),
    ));
}
