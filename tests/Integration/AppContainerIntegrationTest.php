<?php

declare(strict_types=1);

namespace Componenta\Error\App\Tests\Integration;

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Error\App\ConfigProvider as AppProvider;
use Componenta\Error\ConfigProvider as CoreProvider;
use Componenta\Error\Context\CliContext;
use Componenta\Error\Context\HttpContext;
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

final class AppContainerIntegrationTest extends TestCase
{
    public function testComposedProvidersUseSafeOutputAndCorrelatedPrivateLogs(): void
    {
        $directory = sys_get_temp_dir() . '/error-handler-app-di-' . bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $paths = new PathResolver($directory);
        $instant = new DateTimeImmutable('2026-02-03T04:05:06+00:00');
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($instant);

        try {
            file_put_contents($directory . '/.env', "APP_DEBUG=true\n");
            $composition = (new ConfigFactory())->create(
                new Environment(['APP_DEBUG' => false]),
                new CoreProvider(),
                new AppProvider(),
                static fn (): array => [
                    ConfigKey::DEPENDENCIES => [
                        ConfigKey::SERVICES => [
                            PathResolverInterface::class => $paths,
                            ResponseFactoryInterface::class => new Psr17Factory(),
                            ClockInterface::class => $clock,
                        ],
                    ],
                ],
            );

            $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
            $error = new RuntimeException('private-exception-message');
            $response = $container->get(HttpErrorHandlerInterface::class)->handle(
                $error,
                new HttpContext(new ServerRequest('GET', '/', ['Accept' => 'application/json'])),
            );
            $output = new BufferedOutput();
            $container->get(CliErrorHandlerInterface::class)->handle(
                $error,
                new CliContext(new ArrayInput([]), $output),
            );

            $json = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(500, $response->getStatusCode());
            $httpId = $response->getHeaderLine('X-Error-ID');
            self::assertNotEmpty($httpId);
            self::assertSame($httpId, $json['error']['id']);
            self::assertStringNotContainsString('private-exception-message', (string) $response->getBody());
            $cli = $output->fetch();
            self::assertStringContainsString('An unexpected error occurred', $cli);
            self::assertStringNotContainsString('private-exception-message', $cli);
            self::assertStringNotContainsString(__FILE__, $cli);

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
            self::assertNotSame($http['error_id'], $cliRecord['error_id']);
            self::assertArrayNotHasKey('message', $http);
            self::assertArrayNotHasKey('message', $cliRecord);
            self::assertSame([], glob($directory . '/log/error-handler/*.html'));
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
}
