<?php

declare(strict_types=1);

namespace Componenta\Error\App\Tests\ErrorHandler;

use Componenta\Error\App\Listener\FileLogListener;
use Componenta\Error\Context\CliContext;
use Componenta\Error\Event\ErrorEvent;
use Componenta\Error\ErrorOccurrence;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ConcurrentLogTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/error-handler-storage-' . bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testHardLinkedLogCannotModifyAnotherFile(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('link')) {
            self::markTestSkipped('POSIX hard links are required.');
        }
        $target = $this->directory . '/unrelated.txt';
        file_put_contents($target, 'untouched');
        $event = $this->event();
        $log = $this->directory . '/error-' . $event->timestamp->format('Y-m-d') . '.log';
        self::assertTrue(link($target, $log));

        try {
            (new FileLogListener($this->directory))->handleEvent($event);
            self::fail('A hard-linked file must be rejected.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('regular, non-linked file', $failure->getMessage());
        }
        self::assertSame('untouched', file_get_contents($target));
    }

    public function testSymbolicLinkLogCannotModifyItsTarget(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('symlink')) {
            self::markTestSkipped('POSIX symbolic links are required.');
        }
        $target = $this->directory . '/unrelated.txt';
        file_put_contents($target, 'untouched');
        $event = $this->event();
        $log = $this->directory . '/error-' . $event->timestamp->format('Y-m-d') . '.log';
        self::assertTrue(symlink($target, $log));

        try {
            (new FileLogListener($this->directory))->handleEvent($event);
            self::fail('A symbolic link must be rejected.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('regular, non-linked file', $failure->getMessage());
        }
        self::assertSame('untouched', file_get_contents($target));
    }

    public function testConcurrentProcessesAppendWholeUniqueRecords(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('Process spawning is required for the concurrency regression.');
        }

        $workers = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__) . '/Fixture/append-log.php', $this->directory, (string) $i],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes[1], $pipes[2]];
            }

            foreach ($workers as $index => [$process, $stdout, $stderr]) {
                $output = stream_get_contents($stdout);
                $errors = stream_get_contents($stderr);
                fclose($stdout);
                fclose($stderr);
                $status = proc_close($process);
                unset($workers[$index]);
                self::assertSame(0, $status, $output . $errors);
            }

            $files = glob($this->directory . '/*.log');
            self::assertNotEmpty($files);
            $ids = [];
            foreach ($files as $file) {
                foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
                    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    self::assertSame(str_repeat('record-', 400), $record['message']);
                    self::assertArrayNotHasKey($record['error_id'], $ids);
                    $ids[$record['error_id']] = true;
                }
            }
            self::assertCount(100, $ids);
            for ($worker = 0; $worker < 4; $worker++) {
                for ($i = 0; $i < 25; $i++) {
                    self::assertArrayHasKey('worker-' . $worker . '-' . $i, $ids);
                }
            }
        } finally {
            foreach ($workers as [$process, $stdout, $stderr]) {
                proc_terminate($process);
                fclose($stdout);
                fclose($stderr);
                proc_close($process);
            }
        }
    }

    private function event(): ErrorEvent
    {
        return new ErrorEvent(
            new ErrorOccurrence(new RuntimeException('failure'), 'ERR-example'),
            new CliContext(new ArrayInput([]), new BufferedOutput()),
            timestamp: new DateTimeImmutable('2026-01-01T12:00:00Z'),
        );
    }
}
