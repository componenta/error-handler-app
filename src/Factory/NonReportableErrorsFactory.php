<?php

declare(strict_types=1);

namespace Componenta\Error\App\Factory;

use Componenta\Config\Config;
use Componenta\Error\App\ConfigKey;
use Componenta\Error\Reporter\Policy\NonReportableErrors;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

final readonly class NonReportableErrorsFactory
{
    public function __invoke(ContainerInterface $container): NonReportableErrors
    {
        $config = $container->get(Config::class);
        if (!$config instanceof Config) {
            throw new InvalidArgumentException('Expected application Config.');
        }

        return new NonReportableErrors($config->array(ConfigKey::NON_REPORTABLE_ERRORS, []));
    }
}
