<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    date_default_timezone_set((string) ($context['APP_TIMEZONE'] ?? 'Europe/Paris'));

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};