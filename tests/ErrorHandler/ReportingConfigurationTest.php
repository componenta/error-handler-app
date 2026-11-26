<?php

declare(strict_types=1);

namespace Componenta\Error\App\Tests\ErrorHandler;

use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Error\App\ConfigKey;
use Componenta\Error\App\Factory\ErrorListenerProviderFactory;
use Componenta\Error\App\Factory\NonReportableErrorsFactory;
use Componenta\Error\Context\CliContext;
use Componenta\Error\ErrorContextInterface;
use Componenta\Error\ErrorOccurrence;
use Componenta\Error\Event\Attribute\ListenTo;
use Componenta\Error\Event\ErrorEventInterface;
use Componenta\Error\Event\ErrorListener;
use Componenta\Error\Event\ErrorListenerInterface;
use Componenta\Error\Reporter\ErrorReporter;
use Componenta\Tokenizer\ClassInfo;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

#[ListenTo(RuntimeException::class, priority: 10)]
final class DiscoveredErrorListener implements ErrorListenerInterface
{
    public array $events = [];

    public function supports(ErrorOccurrence $error, ErrorContextInterface $context): bool { return true; }

    public function handleEvent(ErrorEventInterface $event): void { $this->events[] = $event; }
}

#[ListenTo(LogicException::class, priority: 100)]
final class ExplicitAttributedListener implements ErrorListenerInterface
{
    public array $events = [];
    public function supports(ErrorOccurrence $error, ErrorContextInterface $context): bool { return true; }
    public function handleEvent(ErrorEventInterface $event): void { $this->events[] = $event; }
}

final class ReportingConfigurationTest extends TestCase
{
    public function testExplicitRegistrationsAndDiscoveredListenersShareTheReportingPolicy(): void
    {
        $seen = [];
        $all = ErrorListener::createFrom(static function (ErrorEventInterface $event) use (&$seen): void {
            $seen[] = 'all:' . $event->error->id;
        });
        $priority = ErrorListener::createFrom(static function (ErrorEventInterface $event) use (&$seen): void {
            $seen[] = 'priority:' . $event->error->id;
        });
        $discovered = new DiscoveredErrorListener();
        $explicit = new ExplicitAttributedListener();
        $scans = 0;
        $source = new ClassIterator((static function () use (&$scans): \Generator {
            ++$scans;
            yield new ClassInfo(DiscoveredErrorListener::class);
            yield new ClassInfo(\stdClass::class);
            yield new ClassInfo(DiscoveredErrorListener::class);
        })());
        $config = new Config([
            ConfigKey::ERROR_LISTENERS => [
                Throwable::class => ['all'],
                RuntimeException::class => [['listener' => 'priority', 'priority' => 100]],
            ],
            ConfigKey::ERROR_LISTENER_CLASSES => [ExplicitAttributedListener::class],
            ConfigKey::NON_REPORTABLE_ERRORS => [InvalidArgumentException::class],
        ], new Environment([]));
        $resolutions = [];
        $container = $this->container([
            Config::class => $config,
            ClassIteratorInterface::class => $source,
            'all' => $all,
            'priority' => $priority,
            DiscoveredErrorListener::class => $discovered,
            ExplicitAttributedListener::class => $explicit,
        ], $resolutions);

        $provider = (new ErrorListenerProviderFactory())($container);
        $reporter = new ErrorReporter($provider, (new NonReportableErrorsFactory())($container));
        self::assertSame(1, $scans);
        self::assertNotContains(DiscoveredErrorListener::class, $resolutions);
        self::assertNotContains(ExplicitAttributedListener::class, $resolutions);

        $context = CliContext::fromArgv();
        $reporter->report(new ErrorOccurrence(new RuntimeException(), 'ERR-runtime'), $context);
        $reporter->report(new ErrorOccurrence(new RuntimeException(), 'ERR-warm'), $context);
        $reporter->report(new ErrorOccurrence(new LogicException(), 'ERR-logic'), $context);
        $reporter->report(new ErrorOccurrence(new InvalidArgumentException(), 'ERR-excluded'), $context);

        self::assertSame(['priority:ERR-runtime', 'all:ERR-runtime', 'priority:ERR-warm', 'all:ERR-warm', 'all:ERR-logic'], $seen);
        self::assertSame(['ERR-runtime', 'ERR-warm'], array_map(static fn ($event) => $event->error->id, $discovered->events));
        self::assertSame(['ERR-logic'], array_map(static fn ($event) => $event->error->id, $explicit->events));
        self::assertSame(1, $scans);
        self::assertSame(2, count(array_keys($resolutions, DiscoveredErrorListener::class, true)));
        self::assertSame(1, count(array_keys($resolutions, ExplicitAttributedListener::class, true)));
    }

    public function testDiscoveryCanBeDisabledAndExplicitClassesStillWork(): void
    {
        $config = new Config([
            ConfigKey::DISCOVER_ERROR_LISTENERS => false,
            ConfigKey::ERROR_LISTENER_CLASSES => [ExplicitAttributedListener::class],
        ], new Environment([]));
        $listener = new ExplicitAttributedListener();
        $resolutions = [];
        $container = $this->container([
            Config::class => $config,
            ClassIteratorInterface::class => new \stdClass(),
            ExplicitAttributedListener::class => $listener,
        ], $resolutions);
        $provider = (new ErrorListenerProviderFactory())($container);
        self::assertSame([$listener], iterator_to_array($provider->provideFor(new LogicException())));
        self::assertNotContains(ClassIteratorInterface::class, $resolutions);
    }

    public function testUnannotatedExplicitServicesStillWorkWithoutDiscovery(): void
    {
        $config = new Config([ConfigKey::ERROR_LISTENERS => [Throwable::class => ['all']]], new Environment([]));
        $listener = ErrorListener::createFrom(static function (): void {});
        $resolutions = [];
        $container = $this->container([Config::class => $config, 'all' => $listener], $resolutions);
        self::assertSame([$listener], iterator_to_array((new ErrorListenerProviderFactory())($container)->provideFor(new RuntimeException())));
    }

    public function testPreparedDiscoveryMapAndFreshClassIteratorHaveTheSameRouting(): void
    {
        $classes = [DiscoveredErrorListener::class, ExplicitAttributedListener::class];
        $fresh = new ClassIterator(array_map(static fn (string $class): ClassInfo => new ClassInfo($class), $classes));
        $map = [];
        foreach ($classes as $class) {
            $info = new ClassInfo($class);
            $map[] = [
                'file' => __FILE__,
                'name' => $class,
                'type' => $info->type->value,
                'abstract' => false,
                'final' => true,
                'readonly' => false,
            ];
        }
        $prepared = ClassIterator::fromMap($map);
        $runtime = new DiscoveredErrorListener();
        $logic = new ExplicitAttributedListener();
        $config = new Config([], new Environment([]));
        $resolutions = [];
        foreach ([$fresh, $prepared] as $source) {
            $container = $this->container([
                Config::class => $config,
                ClassIteratorInterface::class => $source,
                DiscoveredErrorListener::class => $runtime,
                ExplicitAttributedListener::class => $logic,
            ], $resolutions);
            $provider = (new ErrorListenerProviderFactory())($container);

            self::assertSame([$runtime], iterator_to_array($provider->provideFor(new RuntimeException())));
            self::assertSame([$logic], iterator_to_array($provider->provideFor(new LogicException())));
            self::assertSame([], iterator_to_array($provider->provideFor(new \TypeError())));
            self::assertSame([$runtime], iterator_to_array($provider->provideFor(new RuntimeException())));
        }
    }

    private function container(array $services, array &$resolutions): ContainerInterface
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => array_key_exists($id, $services));
        $container->method('get')->willReturnCallback(static function (string $id) use ($services, &$resolutions): mixed {
            $resolutions[] = $id;
            return $services[$id];
        });

        return $container;
    }
}
