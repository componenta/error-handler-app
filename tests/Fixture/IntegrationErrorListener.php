<?php

declare(strict_types=1);

namespace Componenta\Error\App\Tests\Fixture;

use Componenta\Error\ErrorContextInterface;
use Componenta\Error\ErrorOccurrence;
use Componenta\Error\Event\Attribute\ListenTo;
use Componenta\Error\Event\ErrorEventInterface;
use Componenta\Error\Event\ErrorListenerInterface;
use RuntimeException;

#[ListenTo(RuntimeException::class)]
final class IntegrationErrorListener implements ErrorListenerInterface
{
    /** @var list<ErrorEventInterface> */
    public array $events = [];

    public function supports(ErrorOccurrence $error, ErrorContextInterface $context): bool { return true; }

    public function handleEvent(ErrorEventInterface $event): void { $this->events[] = $event; }
}
