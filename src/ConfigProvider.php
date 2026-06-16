<?php

declare(strict_types=1);

namespace Componenta\Error\App;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Error\ConfigKey as ErrorConfigKey;
use Componenta\Error\App\Listener\FileLogListener;
use Componenta\Error\App\Listener\HtmlLogListener;
use Componenta\Error\Renderer\PlainTextRenderer;
use Componenta\Error\Renderer\PrettyPageRenderer;
use Componenta\Error\Renderer\SafeRenderer;
use Componenta\Stdlib\PathResolverInterface;
use Psr\Container\ContainerInterface;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getConfig(): array
    {
        return [
            ErrorConfigKey::HTTP_LISTENERS => [
                FileLogListener::class,
                HtmlLogListener::class,
            ],
            ErrorConfigKey::CLI_LISTENERS => [
                FileLogListener::class,
            ],
        ];
    }

    protected function getFactories(): array
    {
        return [
            ErrorConfigKey::HTTP_RENDERER => static function (ContainerInterface $container): PrettyPageRenderer|SafeRenderer {
                $paths = $container->get(PathResolverInterface::class);

                return EnvDebugResolver::resolve($paths)
                    ? new PrettyPageRenderer(debug: true)
                    : new SafeRenderer(templatePath: $paths->resolve('templates/error/500.phtml'));
            },
            ErrorConfigKey::CLI_RENDERER => static function (ContainerInterface $container): PlainTextRenderer {
                return new PlainTextRenderer(
                    EnvDebugResolver::resolve($container->get(PathResolverInterface::class)),
                );
            },
            FileLogListener::class => static function (ContainerInterface $container): FileLogListener {
                return new FileLogListener($container->get(PathResolverInterface::class)->resolve('log'));
            },
            HtmlLogListener::class => static function (ContainerInterface $container): HtmlLogListener {
                $paths = $container->get(PathResolverInterface::class);

                return new HtmlLogListener(
                    logDir: $paths->resolve('log'),
                    renderer: new PrettyPageRenderer(debug: true),
                    enabled: EnvDebugResolver::resolve($paths),
                );
            },
            SafeRenderer::class => static function (ContainerInterface $container): SafeRenderer {
                return new SafeRenderer(
                    templatePath: $container->get(PathResolverInterface::class)->resolve('templates/error/500.phtml'),
                );
            },
        ];
    }
}
