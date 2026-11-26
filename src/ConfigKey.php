<?php

declare(strict_types=1);

namespace Componenta\Error\App;

final class ConfigKey extends \Componenta\Config\ConfigKey
{
    public const string HTTP_FALLBACK_GENERATOR = 'error.http.fallback_generator';
    public const string HTTP_RENDERER = 'error.http.renderer';
    public const string HTTP_GENERATORS = 'error.http.generators';

    public const string CLI_FALLBACK_HANDLER = 'error.cli.fallback_handler';
    public const string CLI_RENDERER = 'error.cli.renderer';
    public const string ERROR_LEVEL = 'error.http.error_level';

    public const string ERROR_LISTENERS = 'error.listeners';
    public const string ERROR_LISTENER_CLASSES = 'error.listener_classes';
    public const string DISCOVER_ERROR_LISTENERS = 'error.discover_listeners';
    public const string NON_REPORTABLE_ERRORS = 'error.non_reportable';
}
