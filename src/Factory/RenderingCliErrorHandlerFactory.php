<?php

declare(strict_types=1);

namespace Componenta\Error\App\Factory;

use Componenta\Error\App\ConfigKey;
use Componenta\Error\Handler\RenderingCliErrorHandler;
use Componenta\Error\Renderer\SafeCliRenderer;
use Psr\Container\ContainerInterface;
use Componenta\Error\App\Internal\TypedService;

final readonly class RenderingCliErrorHandlerFactory
{
    public function __invoke(ContainerInterface $container): RenderingCliErrorHandler
    {
        $renderer = $container->has(ConfigKey::CLI_RENDERER)
            ? TypedService::get($container, ConfigKey::CLI_RENDERER, \Componenta\Error\Renderer\ErrorRendererInterface::class)
            : new SafeCliRenderer();

        return new RenderingCliErrorHandler($renderer);
    }
}
