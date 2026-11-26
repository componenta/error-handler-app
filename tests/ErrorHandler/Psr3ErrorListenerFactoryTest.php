<?php

declare(strict_types=1);

namespace Componenta\Error\App\Tests\ErrorHandler;

use Componenta\Config\Config;
use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\Config\Environment;
use Componenta\Error\App\ConfigKey;
use Componenta\Error\App\ConfigProvider;
use Componenta\Error\App\Factory\ErrorListenerProviderFactory;
use Componenta\Error\App\Factory\Psr3ErrorListenerFactory;
use Componenta\Error\Context\CliContext;
use Componenta\Error\ErrorOccurrence;
use Componenta\Error\Listener\Psr3ErrorListener;
use Componenta\Error\Reporter\ErrorReporter;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class Psr3ErrorListenerFactoryTest extends TestCase
{
    public function testApplicationRegistersFactoryButDoesNotEnableListenerByDefault(): void
    {
        $configuration = (new ConfigProvider())();
        self::assertSame(
            Psr3ErrorListenerFactory::class,
            $configuration[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES][Psr3ErrorListener::class],
        );
        self::assertNotContains(Psr3ErrorListener::class, $configuration[ConfigKey::ERROR_LISTENERS][Throwable::class]);
    }

    public function testExplicitServiceRegistrationUsesTheApplicationLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')->willReturnCallback(
            static function (mixed $level, string|\Stringable $message, array $context): void {
                self::assertSame('error', $level);
                self::assertSame('ERR-app-logger', $context['error_id']);
                self::assertSame('cli', $context['transport']);
                self::assertArrayNotHasKey('exception', $context);
            },
        );
        $loggerContainer = $this->createMock(ContainerInterface::class);
        $loggerContainer->expects(self::once())->method('get')->with(LoggerInterface::class)->willReturn($logger);
        $listener = (new Psr3ErrorListenerFactory())($loggerContainer);

        $config = new Config([ConfigKey::ERROR_LISTENERS => [Throwable::class => [Psr3ErrorListener::class]]], new Environment([]));
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willReturnMap([
            [Config::class, $config],
            [Psr3ErrorListener::class, $listener],
        ]);
        $provider = (new ErrorListenerProviderFactory())($container);
        (new ErrorReporter($provider))->report(new ErrorOccurrence(new RuntimeException('private'), 'ERR-app-logger'), CliContext::fromArgv());
    }

    public function testInvalidLoggerServiceFailsAtConfigurationBoundary(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new \stdClass());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(LoggerInterface::class);
        (new Psr3ErrorListenerFactory())($container);
    }
}
