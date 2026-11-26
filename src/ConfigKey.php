<?php

declare(strict_types=1);

namespace Componenta\Error\App;

final class ConfigKey extends \Componenta\Config\ConfigKey
{
    public const string ERROR_LISTENERS = 'error.listeners';
    public const string ERROR_LISTENER_CLASSES = 'error.listener_classes';
    public const string DISCOVER_ERROR_LISTENERS = 'error.discover_listeners';
    public const string NON_REPORTABLE_ERRORS = 'error.non_reportable';
}
