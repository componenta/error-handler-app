<?php

declare(strict_types=1);

namespace Componenta\Error\App\Factory;

use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\Config;
use Componenta\Error\App\ConfigKey;
use Componenta\Error\Event\Attribute\ListenTo;
use Componenta\Error\Event\ErrorListenerInterface;
use Componenta\Error\Event\Provider\ArrayErrorListenerProvider;
use Componenta\Error\Event\Provider\AttributeErrorListenerProvider;
use Componenta\Error\Event\Provider\CompositeErrorListenerProvider;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Throwable;

/** Composes listener registrations and the application's existing discovery source. */
final readonly class ErrorListenerProviderFactory
{
    public function __invoke(ContainerInterface $container): CompositeErrorListenerProvider
    {
        $config = $container->get(Config::class);
        if (!$config instanceof Config) {
            throw new InvalidArgumentException('Expected application Config.');
        }

        $providers = [
            new ArrayErrorListenerProvider($this->registrations($config, $container)),
            new AttributeErrorListenerProvider(
                $this->classes($config, $container),
                static function (string $class) use ($container): ErrorListenerInterface {
                    $listener = $container->get($class);
                    if (!$listener instanceof $class) {
                        throw new InvalidArgumentException('Resolved service is not the registered error listener class.');
                    }

                    return $listener;
                },
            ),
        ];

        return new CompositeErrorListenerProvider($providers);
    }

    /** @return array<class-string<Throwable>, list<ErrorListenerInterface|array{listener: ErrorListenerInterface, priority: int}>> */
    private function registrations(Config $config, ContainerInterface $container): array
    {
        $result = [];
        foreach ($config->array(ConfigKey::ERROR_LISTENERS, []) as $type => $entries) {
            if (!is_string($type) || !is_a($type, Throwable::class, true)
                || !is_array($entries) || !array_is_list($entries)) {
                throw new InvalidArgumentException('Invalid error listener group.');
            }

            $result[$type] = [];
            foreach ($entries as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $result[$type][] = $this->resolve($container, $entry);
                } elseif (is_array($entry) && isset($entry['listener'], $entry['priority'])
                    && is_string($entry['listener']) && $entry['listener'] !== '' && is_int($entry['priority'])) {
                    $result[$type][] = [
                        'listener' => $this->resolve($container, $entry['listener']),
                        'priority' => $entry['priority'],
                    ];
                } else {
                    throw new InvalidArgumentException('Invalid error listener service registration.');
                }
            }
        }

        return $result;
    }

    private function resolve(ContainerInterface $container, string $id): ErrorListenerInterface
    {
        $service = $container->get($id);
        if (!$service instanceof ErrorListenerInterface) {
            throw new InvalidArgumentException(sprintf('Service "%s" is not an error listener.', $id));
        }

        return $service;
    }

    /** @return list<class-string<ErrorListenerInterface>> */
    private function classes(Config $config, ContainerInterface $container): array
    {
        $classes = $config->array(ConfigKey::ERROR_LISTENER_CLASSES, []);
        if (!array_is_list($classes)) {
            throw new InvalidArgumentException('Error listener classes must be a list.');
        }

        $result = [];
        foreach ($classes as $class) {
            if (!is_string($class) || !is_a($class, ErrorListenerInterface::class, true)) {
                throw new InvalidArgumentException('Expected an error listener class name.');
            }
            $result[] = $class;
        }

        if (!$config->bool(ConfigKey::DISCOVER_ERROR_LISTENERS, true)
            || !$container->has(ClassIteratorInterface::class)) {
            return $result;
        }

        // Reuse the application's class iterator (including its built map).
        // Never start a second filesystem scan or inspect classes during dispatch.
        $discovered = $container->get(ClassIteratorInterface::class);
        if (!$discovered instanceof ClassIteratorInterface) {
            throw new InvalidArgumentException('Expected the application discovery iterator.');
        }
        foreach ($discovered as $info) {
            if (!$info->isConcrete) {
                continue;
            }
            $reflection = $info->reflector;
            if ($reflection->getAttributes(ListenTo::class) === []) {
                continue;
            }
            $name = $reflection->getName();
            if (!is_a($name, ErrorListenerInterface::class, true) || $reflection->isAbstract()) {
                throw new InvalidArgumentException(sprintf('Attributed class "%s" must be a concrete error listener.', $name));
            }
            $result[] = $name;
        }

        return $result;
    }
}
