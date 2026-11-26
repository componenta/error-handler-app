<?php

declare(strict_types=1);

namespace Componenta\Error\App\Tests\ErrorHandler;

use Componenta\Error\App\Listener\FileLogListener;
use Componenta\Error\App\Listener\HtmlLogListener;
use Componenta\Error\Context\HttpContext;
use Componenta\Error\Context\CliContext;
use Componenta\Error\Context\ErrorContextAttribute;
use Componenta\Error\Event\ErrorEvent;
use Componenta\Error\ErrorOccurrence;
use Componenta\Error\Renderer\PrettyPageRenderer;
use Componenta\Error\Renderer\ErrorRendererInterface;
use Componenta\Error\Renderer\RenderedError;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class LogSecurityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/error-handler-' . bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testPrivateStructuredRecordContainsCorrelationWithoutSecrets(): void
    {
        $event = new ErrorEvent(
            new ErrorOccurrence(new RuntimeException("injected\nfake record secret"), 'ERR-test'),
            new HttpContext(new ServerRequest('GET', '/?token=private-query'), [ErrorContextAttribute::HTTP_STATUS_CODE => 503]),
            timestamp: new \DateTimeImmutable('2026-01-01T12:00:00Z'),
        );
        (new FileLogListener($this->directory))->handleEvent($event);
        $files = glob($this->directory . '/*.log');
        self::assertCount(1, $files);
        $lines = file($files[0], FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $lines);
        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ERR-test', $record['error_id']);
        self::assertSame(503, $record['status']);
        self::assertSame('2026-01-01T12:00:00+00:00', $record['timestamp']);
        self::assertStringNotContainsString('private-query', $lines[0]);
        self::assertStringNotContainsString('fake record secret', $lines[0]);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($files[0]) & 0777);
        }
    }

    public function testHtmlSnapshotsAreOptInAndNeverOverwriteEachOther(): void
    {
        $event = new ErrorEvent(new ErrorOccurrence(new RuntimeException('failure'), 'same'), new HttpContext(new ServerRequest('GET', '/', ['Accept' => 'application/json'])), timestamp: new \DateTimeImmutable('2026-01-01T12:00:00Z'));
        (new HtmlLogListener($this->directory, new PrettyPageRenderer()))->handleEvent($event);
        self::assertSame([], glob($this->directory . '/*.html'));

        $listener = new HtmlLogListener($this->directory, new PrettyPageRenderer(debug: true, includeSource: false), enabled: true);
        $listener->handleEvent($event);
        $listener->handleEvent($event);
        $snapshots = glob($this->directory . '/*.html');
        self::assertCount(2, $snapshots);
        foreach ($snapshots as $snapshot) {
            $html = file_get_contents($snapshot);
            self::assertIsString($html);
            self::assertStringContainsString('<html', strtolower($html));
            self::assertStringContainsString('same', $html);
        }
        self::assertSame('application/json', $event->context->request->getHeaderLine('Accept'));
    }

    public function testDebugSnapshotRetainsOriginalRequestMetadataAndCorrelation(): void
    {
        $template = $this->directory . '/snapshot.phtml';
        file_put_contents($template, '<!doctype html><html><body><?= $e($headers["Accept"] ?? "") ?>;<?= $e($errorId) ?></body></html>');
        $event = new ErrorEvent(
            new ErrorOccurrence(new RuntimeException('failure'), 'ERR-snapshot-123'),
            new HttpContext(new ServerRequest('GET', '/', ['Accept' => 'application/json'])),
            timestamp: new \DateTimeImmutable('2026-01-01T12:00:00Z'),
        );

        $listener = new HtmlLogListener(
            $this->directory,
            new PrettyPageRenderer(templatePath: $template, includeSource: false),
            enabled: true,
        );
        $listener->handleEvent($event);

        $files = glob($this->directory . '/*.html');
        self::assertCount(1, $files);
        self::assertSame('<!doctype html><html><body>application/json;ERR-snapshot-123</body></html>', file_get_contents($files[0]));
        self::assertSame('application/json', $event->context->request->getHeaderLine('Accept'));
    }

    public function testSnapshotAcceptsCustomRendererAndPreservesOriginalEventContext(): void
    {
        $event = new ErrorEvent(
            new ErrorOccurrence(new RuntimeException('failure'), 'ERR-custom'),
            new HttpContext(new ServerRequest('GET', '/', ['Accept' => 'application/json'])),
            new \DateTimeImmutable('2026-01-01T12:00:00Z'),
        );
        $html = '<!doctype html><html><body>ERR-custom</body></html>';
        $renderer = $this->createMock(ErrorRendererInterface::class);
        $renderer->expects(self::once())->method('supports')->with(self::identicalTo($event->error), self::identicalTo($event->context))->willReturn(true);
        $renderer->expects(self::once())->method('render')->with(self::identicalTo($event->error), self::identicalTo($event->context))->willReturn(new RenderedError($html, 'Text/HTML; charset=utf-8'));

        (new HtmlLogListener($this->directory, $renderer, enabled: true))->handleEvent($event);

        $files = glob($this->directory . '/*.html');
        self::assertCount(1, $files);
        self::assertSame($html, file_get_contents($files[0]));
        self::assertSame('application/json', $event->context->request->getHeaderLine('Accept'));
    }

    public function testUnsupportedRendererIsNeverInvoked(): void
    {
        $event = new ErrorEvent(
            new ErrorOccurrence(new RuntimeException('failure'), 'ERR-unsupported'),
            new HttpContext(new ServerRequest('GET', '/')),
            new \DateTimeImmutable('2026-01-01T12:00:00Z'),
        );
        $renderer = $this->createMock(ErrorRendererInterface::class);
        $renderer->method('supports')->willReturn(false);
        $renderer->expects(self::never())->method('render');
        $listener = new HtmlLogListener($this->directory, $renderer, enabled: true);

        self::assertFalse($listener->supports($event->error, $event->context));
        $listener->handleEvent($event);
        self::assertSame([], glob($this->directory . '/*.html'));
    }

    public function testDisabledListenerAndCliContextDoNotConsultRenderer(): void
    {
        $renderer = $this->createMock(ErrorRendererInterface::class);
        $renderer->expects(self::never())->method('supports');
        $renderer->expects(self::never())->method('render');
        $error = new ErrorOccurrence(new RuntimeException('failure'), 'ERR-disabled');
        $http = new HttpContext(new ServerRequest('GET', '/'));
        $cli = CliContext::fromArgv();
        $disabled = new HtmlLogListener($this->directory, $renderer);
        $enabled = new HtmlLogListener($this->directory, $renderer, enabled: true);

        self::assertFalse($disabled->supports($error, $http));
        self::assertFalse($enabled->supports($error, $cli));
        $disabled->handleEvent(new ErrorEvent($error, $http, new \DateTimeImmutable()));
        $enabled->handleEvent(new ErrorEvent($error, $cli, new \DateTimeImmutable()));
        self::assertSame([], glob($this->directory . '/*.html'));
    }

    public function testNonHtmlResultIsRejectedWithoutRetryOrWritingFile(): void
    {
        $event = new ErrorEvent(
            new ErrorOccurrence(new RuntimeException('failure'), 'ERR-json'),
            new HttpContext(new ServerRequest('GET', '/', ['Accept' => 'application/json'])),
            new \DateTimeImmutable('2026-01-01T12:00:00Z'),
        );
        $renderer = $this->createMock(ErrorRendererInterface::class);
        $renderer->method('supports')->willReturn(true);
        $renderer->expects(self::once())->method('render')->with(self::identicalTo($event->error), self::identicalTo($event->context))->willReturn(new RenderedError('{}', 'application/json'));
        $listener = new HtmlLogListener($this->directory, $renderer, enabled: true);

        try {
            $listener->handleEvent($event);
            self::fail('A non-HTML snapshot must be rejected.');
        } catch (UnexpectedValueException $failure) {
            self::assertStringContainsString('text/html', $failure->getMessage());
        }

        self::assertSame([], glob($this->directory . '/*.html'));
        self::assertSame('application/json', $event->context->request->getHeaderLine('Accept'));
    }

    public function testNegotiatingRendererCannotSilentlyReplaceRequestAcceptHeader(): void
    {
        $event = new ErrorEvent(
            new ErrorOccurrence(new RuntimeException('failure'), 'ERR-negotiation'),
            new HttpContext(new ServerRequest('GET', '/', ['Accept' => 'application/json'])),
            new \DateTimeImmutable('2026-01-01T12:00:00Z'),
        );
        $listener = new HtmlLogListener($this->directory, new PrettyPageRenderer(debug: false), enabled: true);

        try {
            $listener->handleEvent($event);
            self::fail('A JSON result must not be negotiated into HTML by the listener.');
        } catch (UnexpectedValueException) {
            self::assertSame([], glob($this->directory . '/*.html'));
            self::assertSame('application/json', $event->context->request->getHeaderLine('Accept'));
        }
    }
}
