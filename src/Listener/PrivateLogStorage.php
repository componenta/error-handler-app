<?php

declare(strict_types=1);

namespace Componenta\Error\App\Listener;

use RuntimeException;

/** @internal Storage for private, bounded diagnostic records. */
final readonly class PrivateLogStorage
{
    public function __construct(private string $directory)
    {
    }

    public function append(string $name, string $record): void
    {
        $path = $this->path($name);
        clearstatcache(true, $path);
        $existing = @lstat($path);
        if ($existing !== false) {
            self::assertRegular($existing);
        }

        $file = @fopen($path, 'ab');
        if ($file === false) {
            throw new RuntimeException('Unable to open the error log.');
        }
        try {
            $this->verifyOpenedFile($file, $path);
            $this->protect($path);
            if (!flock($file, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the error log.');
            }
            try {
                $metadata = fstat($file);
                if ($metadata === false) {
                    throw new RuntimeException('Unable to determine the original log size.');
                }
                $size = $metadata['size'];
                try {
                    self::write($file, $record);
                } catch (\Throwable $failure) {
                    // Roll back a partial append while the exclusive lock is held.
                    if (!@ftruncate($file, $size)) {
                        throw new RuntimeException('Unable to roll back a partial error log record.', previous: $failure);
                    }
                    throw $failure;
                }
            } finally {
                flock($file, LOCK_UN);
            }
        } finally {
            fclose($file);
        }
    }

    public function create(string $name, string $record): void
    {
        $path = $this->path($name);
        $file = @fopen($path, 'xb');
        if ($file === false) {
            throw new RuntimeException('Unable to create a unique error snapshot.');
        }
        $complete = false;
        try {
            $this->verifyOpenedFile($file, $path);
            $this->protect($path);
            self::write($file, $record);
            $complete = true;
        } finally {
            fclose($file);
            if (!$complete) {
                @unlink($path);
            }
        }
    }

    private function path(string $name): string
    {
        if ($name !== basename($name) || $name === '.' || $name === '..' || $name === '') {
            throw new RuntimeException('Invalid error log file name.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the error log directory.');
        }
        clearstatcache(true, $this->directory);
        if (is_link($this->directory)) {
            throw new RuntimeException('Refusing to use a symbolic-link log directory.');
        }
        if (DIRECTORY_SEPARATOR === '/') {
            $mode = fileperms($this->directory);
            if ($mode === false || ($mode & 0077) !== 0) {
                throw new RuntimeException('Error log directory must be private (0700).');
            }
        }

        return $this->directory . DIRECTORY_SEPARATOR . $name;
    }

    /** @param array<array-key, int> $metadata */
    private static function assertRegular(array $metadata): void
    {
        if (($metadata['mode'] & 0170000) !== 0100000
            || (DIRECTORY_SEPARATOR === '/' && $metadata['nlink'] !== 1)) {
            throw new RuntimeException('Error log target must be a regular, non-linked file.');
        }
    }

    /** @param resource $file */
    private function verifyOpenedFile($file, string $path): void
    {
        clearstatcache(true, $path);
        $opened = fstat($file);
        $named = @lstat($path);
        if ($opened === false || $named === false) {
            throw new RuntimeException('Unable to verify the error log file.');
        }
        self::assertRegular($opened);
        self::assertRegular($named);

        if (DIRECTORY_SEPARATOR === '/' && ($opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino'])) {
            throw new RuntimeException('Error log path changed while opening the file.');
        }
    }

    private function protect(string $path): void
    {
        if (DIRECTORY_SEPARATOR === '/' && !@chmod($path, 0600)) {
            throw new RuntimeException('Unable to restrict error log permissions.');
        }
    }

    /** @param resource $file */
    private static function write($file, string $record): void
    {
        $length = strlen($record);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($file, substr($record, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write the complete error log record.');
            }
            $offset += $written;
        }
        if (!fflush($file)) {
            throw new RuntimeException('Unable to flush the error log.');
        }
    }
}
