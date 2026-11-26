<?php

declare(strict_types=1);

namespace Componenta\Error\App;

use Componenta\Config\Config;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Error\App\Factory\ErrorListenerProviderFactory;
use Componenta\Error\App\Factory\CliErrorHandlerFactory;
use Componenta\Error\App\Factory\ErrorHandlerMiddlewareFactory;
use Componenta\Error\App\Factory\ErrorIdGeneratorFactory;
use Componenta\Error\App\Factory\ErrorOccurrenceFactoryFactory;
use Componenta\Error\App\Factory\ErrorReporterFactory;
use Componenta\Error\App\Factory\HttpErrorHandlerFactory;
use Componenta\Error\App\Factory\HttpErrorResponseGeneratorFactory;
use Componenta\Error\App\Factory\RenderingCliErrorHandlerFactory;
use Componenta\Error\ErrorId\ErrorIdGeneratorInterface;
use Componenta\Error\ErrorId\RandomErrorIdGenerator;
use Componenta\Error\ErrorOccurrenceFactory;
use Componenta\Error\Handler\CliErrorHandler;
use Componenta\Error\Handler\CliErrorHandlerInterface;
use Componenta\Error\Handler\HttpErrorHandler;
use Componenta\Error\Handler\HttpErrorHandlerInterface;
use Componenta\Error\Handler\RenderingCliErrorHandler;
use Componenta\Error\Http\HttpErrorResponseGenerator;
use Componenta\Error\Http\Middleware\ErrorHandlerMiddleware;
use Componenta\Error\Reporter\ErrorReporter;
use Componenta\Error\Reporter\ErrorReporterInterface;
use Componenta\Error\App\Factory\NonReportableErrorsFactory;
use Componenta\Error\Event\ErrorListenerProviderInterface;
use Componenta\Error\Reporter\Policy\ErrorReportingPolicyInterface;
use Throwable;
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
            ConfigKey::ERROR_LEVEL => E_ALL,
            ConfigKey::ERROR_LISTENERS => [
                Throwable::class => [FileLogListener::class, HtmlLogListener::class],
            ],
        ];
    }

    protected function getFactories(): array
    {
        return [
            HttpErrorHandler::class => HttpErrorHandlerFactory::class,
            HttpErrorResponseGenerator::class => HttpErrorResponseGeneratorFactory::class,
            CliErrorHandler::class => CliErrorHandlerFactory::class,
            RenderingCliErrorHandler::class => RenderingCliErrorHandlerFactory::class,
            ErrorHandlerMiddleware::class => ErrorHandlerMiddlewareFactory::class,
            ErrorReporter::class => ErrorReporterFactory::class,
            ErrorOccurrenceFactory::class => ErrorOccurrenceFactoryFactory::class,
            RandomErrorIdGenerator::class => ErrorIdGeneratorFactory::class,
            ErrorListenerProviderInterface::class => ErrorListenerProviderFactory::class,
            ErrorReportingPolicyInterface::class => NonReportableErrorsFactory::class,
            ConfigKey::HTTP_RENDERER => static function (ContainerInterface $container): PrettyPageRenderer|SafeRenderer {
                $paths = $container->get(PathResolverInterface::class);

                return self::debugEnabled($container, $paths)
                    ? new PrettyPageRenderer(debug: true)
                    : new SafeRenderer(templatePath: $paths->resolve('templates/error/500.phtml'));
            },
            ConfigKey::CLI_RENDERER => static function (ContainerInterface $container): PlainTextRenderer|SafeCliRenderer {
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

    protected function getAliases(): array
    {
        return [
            HttpErrorHandlerInterface::class => HttpErrorHandler::class,
            CliErrorHandlerInterface::class => CliErrorHandler::class,
            ErrorReporterInterface::class => ErrorReporter::class,
            ErrorIdGeneratorInterface::class => RandomErrorIdGenerator::class,
            ConfigKey::HTTP_FALLBACK_GENERATOR => HttpErrorResponseGenerator::class,
            ConfigKey::CLI_FALLBACK_HANDLER => RenderingCliErrorHandler::class,
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
