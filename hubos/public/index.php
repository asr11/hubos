<?php

declare(strict_types=1);

/*
 * Front controller. The web root is hubos/public — nothing else is exposed.
 */

$config = require dirname(__DIR__) . '/app/bootstrap.php';

ini_set('display_errors', $config['env'] === 'development' ? '1' : '0');
ini_set('log_errors', '1');

\HubOS\Core\App::run($config);
