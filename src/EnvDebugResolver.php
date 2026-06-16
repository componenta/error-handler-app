<?php

declare(strict_types=1);

namespace Componenta\Error\App;

use Componenta\Stdlib\PathResolverInterface;

final class EnvDebugResolver
{
    private const string KEY = 'APP_DEBUG';

    public static function resolve(PathResolverInterface $paths): bool
    {
        $value = self::readValue($paths->resolve('.env'));

        return match (strtolower(trim($value ?? ''))) {
            '1', 'true', 'yes', 'on' => true,
            default => false,
        };
    }

    private static function readValue(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));

            if ($key !== self::KEY) {
                continue;
            }

            return self::parseValue(trim(substr($line, $separator + 1)));
        }

        return null;
    }

    private static function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];

        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            $value = substr($value, 1, -1);

            return $quote === '"'
                ? str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $value)
                : $value;
        }

        $comment = strpos($value, ' #');

        if ($comment !== false) {
            $value = substr($value, 0, $comment);
        }

        return trim($value);
    }
}
