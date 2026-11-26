<?php

declare(strict_types=1);

namespace Componenta\Error\App\Tests\Integration;

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Error\App\ConfigProvider as AppProvider;
use Componenta\Error\App\ConfigKey as ErrorConfigKey;
use Componenta\Error\Listener\Psr3ErrorListener;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use Componenta\Error\App\Tests\Fixture\IntegrationErrorListener;
use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Error\Event\ErrorListenerProviderInterface;
use Componenta\Error\Context\CliContext;
use Componenta\Error\Context\HttpContext;
use Componenta\Error\ErrorOccurrenceFactory;
use Componenta\Error\Reporter\Attribute\NonReportable;
use Componenta\Error\Handler\CliErrorHandlerInterface;
use Componenta\Error\Handler\HttpErrorHandlerInterface;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Stdlib\PathResolver;
use Psr\Clock\ClockInterface;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[NonReportable]
final class ExpectedApplicationFailure extends RuntimeException
{
}

final class AppContainerIntegrationTest extends TestCase
{
    #[DataProvider('loggingModes')]
    public function testComposedProvidersUseSafeOutputAndCorrelatedPrivateLogs(bool $withPsr3): void
    {
        $directory = sys_get_temp_dir() . '/error-handler-app-di-' . bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $paths = new PathResolver($directory);
        $instant = new DateTimeImmutable('2026-02-03T04:05:06+00:00');
        $listener = new IntegrationErrorListener();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($instant);
        $records = [];
        $loggerServices = [];
        $loggingConfig = [];
        if ($withPsr3) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::exactly(2))->method('log')->willReturnCallback(
                static function (mixed $level, string|\Stringable $message, array $record) use (&$records): void {
                    $records[] = [$level, $message, $record];
                },
            );
            $loggerServices = [LoggerInterface::class => $logger];
            $loggingConfig = [ErrorConfigKey::ERROR_LISTENERS => [Throwable::class => [Psr3ErrorListener::class]]];
        }

        try {
            file_put_contents($directory . '/.env', "APP_DEBUG=true\n");
            $composition = (new ConfigFactory())->create(
                new Environment(['APP_DEBUG' => false]),
                new AppProvider(),
                static fn (): array => [
                    ...$loggingConfig,
                    ConfigKey::DEPENDENCIES => [
                        ConfigKey::SERVICES => [
                            ...$loggerServices,
                            PathResolverInterface::class => $paths,
                            ResponseFactoryInterface::class => new Psr17Factory(),
                            ClockInterface::class => $clock,
                            ClassIteratorInterface::class => new ClassIterator([new ClassInfo(IntegrationErrorListener::class)]),
                            IntegrationErrorListener::class => $listener,
                        ],
                    ],
                ],
            );

            $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
            $container->get(ErrorListenerProviderInterface::class);
            self::assertSame([], $listener->events);
            $error = new RuntimeException('private-exception-message');
            $factory = $container->get(ErrorOccurrenceFactory::class);
            $httpError = $factory->create($error);
            $cliError = $factory->create($error);
            $response = $container->get(HttpErrorHandlerInterface::class)->handle(
                $httpError,
                new HttpContext(new ServerRequest('GET', '/', ['Accept' => 'application/json'])),
            );
            $output = new BufferedOutput();
            $container->get(CliErrorHandlerInterface::class)->handle(
                $cliError,
                new CliContext(new ArrayInput([]), $output),
            );

            $json = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(500, $response->getStatusCode());
            $httpId = $response->getHeaderLine('X-Error-ID');
            self::assertSame($httpError->id, $httpId);
            self::assertSame($httpId, $json['error']['id']);
            self::assertStringNotContainsString('private-exception-message', (string) $response->getBody());
            $cli = $output->fetch();
            self::assertStringContainsString('An unexpected error occurred', $cli);
            self::assertStringNotContainsString('private-exception-message', $cli);
            self::assertStringNotContainsString(__FILE__, $cli);

            // An attributed error still renders a correlated response, but
            // neither the configured file logger nor the discovered listener runs.
            $expected = $factory->create(new ExpectedApplicationFailure('expected failure'));
            $expectedResponse = $container->get(HttpErrorHandlerInterface::class)->handle(
                $expected,
                new HttpContext(new ServerRequest('GET', '/', ['Accept' => 'application/json'])),
            );
            self::assertSame(500, $expectedResponse->getStatusCode());
            self::assertSame($expected->id, $expectedResponse->getHeaderLine('X-Error-ID'));

            $logs = glob($directory . '/log/error-handler/*.log');
            self::assertCount(1, $logs);
            $lines = file($logs[0], FILE_IGNORE_NEW_LINES);
            self::assertCount(2, $lines);
            $http = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
            $cliRecord = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('http', $http['transport']);
            self::assertSame(500, $http['status']);
            self::assertSame($instant->format(DATE_ATOM), $http['timestamp']);
            self::assertSame($instant->format(DATE_ATOM), $cliRecord['timestamp']);
            self::assertSame('cli', $cliRecord['transport']);
            self::assertSame($httpId, $http['error_id']);
            self::assertStringContainsString('Error ID: ' . $cliRecord['error_id'], $cli);
            self::assertSame($cliError->id, $cliRecord['error_id']);
            self::assertNotSame($http['error_id'], $cliRecord['error_id']);
            self::assertSame([$httpError, $cliError], array_map(static fn ($event) => $event->error, $listener->events));
            self::assertSame($instant, $listener->events[0]->timestamp);
            self::assertArrayNotHasKey('message', $http);
            self::assertArrayNotHasKey('message', $cliRecord);
            self::assertSame([], glob($directory . '/log/error-handler/*.html'));
            if ($withPsr3) {
                self::assertSame([
                    ['error', 'Application error {error_id} ({exception_type})', [
                        'error_id' => $httpId,
                        'exception_type' => RuntimeException::class,
                        'timestamp' => $instant->format('Y-m-d\TH:i:s.uP'),
                        'transport' => 'http',
                        'status' => 500,
                    ]],
                    ['error', 'Application error {error_id} ({exception_type})', [
                        'error_id' => $cliError->id,
                        'exception_type' => RuntimeException::class,
                        'timestamp' => $instant->format('Y-m-d\TH:i:s.uP'),
                        'transport' => 'cli',
                    ]],
                ], $records);
            } else {
                self::assertSame([], $records);
            }
        } finally {
            foreach (glob($directory . '/log/error-handler/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($directory . '/log/error-handler');
            @rmdir($directory . '/log');
            @unlink($directory . '/.env');
            rmdir($directory);
        }
    }

    public static function loggingModes(): iterable
    {
        yield 'standalone file logging without PSR-3' => [false];
        yield 'explicit PSR-3 integration alongside file logging' => [true];
    }
}
