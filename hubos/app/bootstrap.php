<?php

declare(strict_types=1);

/*
 * Shared bootstrap for web, CLI and tests.
 * Returns the configuration array.
 */

if (!defined('HUBOS_ROOT')) {
    define('HUBOS_ROOT', dirname(__DIR__));
}

date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    $prefix = 'HubOS\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = HUBOS_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once HUBOS_ROOT . '/app/helpers.php';

return require HUBOS_ROOT . '/app/config.php';
