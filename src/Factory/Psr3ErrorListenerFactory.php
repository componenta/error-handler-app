<?php

declare(strict_types=1);

namespace Componenta\Error\App\Factory;

use Componenta\Error\App\Internal\TypedService;
use Componenta\Error\Listener\Psr3ErrorListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/** Creates the opt-in listener from the application's explicitly supplied logger. */
final readonly class Psr3ErrorListenerFactory
{
    public function __invoke(ContainerInterface $container): Psr3ErrorListener
    {
        return new Psr3ErrorListener(
            TypedService::get($container, LoggerInterface::class, LoggerInterface::class),
        );
    }
}
