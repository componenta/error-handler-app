<?php

declare(strict_types=1);

namespace Componenta\Error\App;

use Componenta\Config\Environment;
use Componenta\Config\Loader\EnvLoader;
use Componenta\Stdlib\PathResolverInterface;
use Throwable;

final class EnvDebugResolver
{
    private const string KEY = 'APP_DEBUG';

    public static function resolve(PathResolverInterface $paths, ?Environment $environment = null): bool
    {
        // Once the application configuration exists, use its immutable snapshot.
        if ($environment !== null) {
            return self::enabled($environment->get(self::KEY, null));
        }

        $process = getenv(self::KEY, true);
        if ($process !== false) {
            return self::enabled($process);
        }
        if (array_key_exists(self::KEY, $_ENV)) {
            return self::enabled($_ENV[self::KEY]);
        }

        // Bootstrap fallback: use the same dotenv grammar and precedence as Config.
        try {
            $value = null;
            foreach (['.env', '.env.local'] as $name) {
                $path = $paths->resolve($name);
                if (!is_file($path)) {
                    continue;
                }
                $size = @filesize($path);
                if ($size === false || $size > 1_048_576 || !is_readable($path)) {
                    return false;
                }
                $values = (new EnvLoader(dirname($path), filenames: [basename($path)]))->read() ?? [];
                if (array_key_exists(self::KEY, $values)) {
                    $value = $values[self::KEY];
                }
            }

            return self::enabled($value);
        } catch (Throwable) {
            // A malformed or unavailable configuration must never enable debug.
            return false;
        }
    }

    private static function enabled(mixed $value): bool
    {
        return $value === true || $value === 1
            || (is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true));
    }
}
