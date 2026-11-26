<?php

declare(strict_types=1);

namespace Componenta\Error\App;

use Componenta\Config\Config;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Error\ConfigKey as ErrorConfigKey;
use Componenta\Error\App\Listener\FileLogListener;
use Componenta\Error\App\Listener\HtmlLogListener;
use Componenta\Error\Renderer\PlainTextRenderer;
use Componenta\Error\Renderer\PrettyPageRenderer;
use Componenta\Error\Renderer\SafeRenderer;
use Componenta\Error\Renderer\SafeCliRenderer;
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

                return self::debugEnabled($container, $paths)
                    ? new PrettyPageRenderer(debug: true)
                    : new SafeRenderer(templatePath: $paths->resolve('templates/error/500.phtml'));
            },
            ErrorConfigKey::CLI_RENDERER => static function (ContainerInterface $container): PlainTextRenderer|SafeCliRenderer {
                return self::debugEnabled($container, $container->get(PathResolverInterface::class))
                    ? new PlainTextRenderer(debug: true)
                    : new SafeCliRenderer();
            },
            FileLogListener::class => static function (ContainerInterface $container): FileLogListener {
                $paths = $container->get(PathResolverInterface::class);

                return new FileLogListener(
                    $paths->resolve('log/error-handler'),
                    includeDebugDetails: self::debugEnabled($container, $paths),
                );
            },
            HtmlLogListener::class => static function (ContainerInterface $container): HtmlLogListener {
                $paths = $container->get(PathResolverInterface::class);

                return new HtmlLogListener(
                    logDir: $paths->resolve('log/error-handler'),
                    renderer: new PrettyPageRenderer(debug: true),
                    enabled: self::debugEnabled($container, $paths),
                );
            },
            SafeRenderer::class => static function (ContainerInterface $container): SafeRenderer {
                return new SafeRenderer(
                    templatePath: $container->get(PathResolverInterface::class)->resolve('templates/error/500.phtml'),
                );
            },
        ];
    }

    private static function debugEnabled(ContainerInterface $container, PathResolverInterface $paths): bool
    {
        if (!$container->has(Config::class)) {
            return EnvDebugResolver::resolve($paths);
        }

        $config = $container->get(Config::class);
        if (!$config instanceof Config) {
            return false;
        }

        return EnvDebugResolver::resolve($paths, $config->environment);
    }
}
