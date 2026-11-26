<?php

declare(strict_types=1);

use Componenta\Error\App\EnvDebugResolver;
use Componenta\Config\Environment;
use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Error\App\ConfigProvider;
use Componenta\Error\ConfigKey as ErrorConfigKey;
use Componenta\Error\Renderer\SafeRenderer;
use Psr\Container\ContainerInterface;
use Componenta\Stdlib\PathResolverInterface;

function errorHandlerAppPathResolver(string $baseDir): PathResolverInterface
{
    return new readonly class($baseDir) implements PathResolverInterface {
        public function __construct(
            public string $baseDir,
        ) {
        }

        public function resolve(string $path): string
        {
            return $this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        }
    };
}

function errorHandlerAppTempDir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'componenta-error-handler-app-' . bin2hex(random_bytes(6));

    mkdir($dir, 0777, true);

    return $dir;
}

it('uses false when env file is missing', function (): void {
    $dir = errorHandlerAppTempDir();

    expect(EnvDebugResolver::resolve(errorHandlerAppPathResolver($dir)))->toBeFalse();

    rmdir($dir);
});

it('reads enabled APP_DEBUG values from env file', function (string $value): void {
    $dir = errorHandlerAppTempDir();
    file_put_contents($dir . DIRECTORY_SEPARATOR . '.env', "APP_DEBUG=$value\n");

    expect(EnvDebugResolver::resolve(errorHandlerAppPathResolver($dir)))->toBeTrue();

    unlink($dir . DIRECTORY_SEPARATOR . '.env');
    rmdir($dir);
})->with(['1', 'true', 'yes', 'on', '"true"', "'yes'"]);

it('reads disabled APP_DEBUG values from env file', function (string $value): void {
    $dir = errorHandlerAppTempDir();
    file_put_contents($dir . DIRECTORY_SEPARATOR . '.env', "APP_DEBUG=$value\n");

    expect(EnvDebugResolver::resolve(errorHandlerAppPathResolver($dir)))->toBeFalse();

    unlink($dir . DIRECTORY_SEPARATOR . '.env');
    rmdir($dir);
})->with(['0', 'false', 'no', 'off', '']);


it('uses the final assignment and local dotenv override', function (): void {
    $dir = errorHandlerAppTempDir();
    $paths = errorHandlerAppPathResolver($dir);
    try {
        file_put_contents($dir . '/.env', "APP_DEBUG=true\nAPP_DEBUG=false\n");
        expect(EnvDebugResolver::resolve($paths))->toBeFalse();
        file_put_contents($dir . '/.env.local', "APP_DEBUG=true\n");
        expect(EnvDebugResolver::resolve($paths))->toBeTrue();
        file_put_contents($dir . '/.env.local', "APP_DEBUG=\"true\n");
        expect(EnvDebugResolver::resolve($paths))->toBeFalse();
    } finally {
        @unlink($dir . '/.env.local');
        @unlink($dir . '/.env');
        rmdir($dir);
    }
});

it('does not reread dotenv after runtime configuration is composed', function (): void {
    $dir = errorHandlerAppTempDir();
    $paths = errorHandlerAppPathResolver($dir);
    try {
        file_put_contents($dir . '/.env', "APP_DEBUG=true\n");
        $disabled = new Environment(['APP_DEBUG' => false]);
        expect(EnvDebugResolver::resolve($paths, $disabled))->toBeFalse();
        expect(EnvDebugResolver::resolve($paths, new Environment([])))->toBeFalse();
        expect(EnvDebugResolver::resolve($paths, new Environment(['APP_DEBUG' => true])))->toBeTrue();

        $container = new class($paths, $disabled) implements ContainerInterface {
            public function __construct(private PathResolverInterface $paths, private Environment $environment) {}
            public function has(string $id): bool
            {
                return in_array($id, [PathResolverInterface::class, Config::class], true);
            }
            public function get(string $id): mixed
            {
                return match ($id) {
                    PathResolverInterface::class => $this->paths,
                    Config::class => new Config([], $this->environment),
                    default => throw new LogicException('Unexpected service.'),
                };
            }
        };
        $definition = (new ConfigProvider())();
        $factory = $definition[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES][ErrorConfigKey::HTTP_RENDERER];
        expect($factory($container))->toBeInstanceOf(SafeRenderer::class);
    } finally {
        @unlink($dir . '/.env');
        rmdir($dir);
    }
});

it('honors explicit process-level debug disablement during bootstrap', function (): void {
    $dir = errorHandlerAppTempDir();
    $previous = getenv('APP_DEBUG', true);
    try {
        file_put_contents($dir . '/.env', "APP_DEBUG=true\n");
        putenv('APP_DEBUG=false');
        expect(EnvDebugResolver::resolve(errorHandlerAppPathResolver($dir)))->toBeFalse();
    } finally {
        putenv($previous === false ? 'APP_DEBUG' : 'APP_DEBUG=' . $previous);
        @unlink($dir . '/.env');
        rmdir($dir);
    }
});
