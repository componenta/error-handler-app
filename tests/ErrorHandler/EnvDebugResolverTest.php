<?php

declare(strict_types=1);

use Componenta\Error\App\EnvDebugResolver;
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
