<?php

/**
 * Dashboard front controller on 443 (milestone 5, M-054). Apache sends every
 * path that isn't a file here (FallbackResource); assets and health.php are
 * served as files. Templates live outside the web root, in templates/.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Web\App;
use Aio\Web\Session;
use Aio\Web\View;
use Aio\Web\WebRequest;

$config = Config::load();
$app = new App(
    $config,
    new View(dirname(__DIR__, 2) . '/templates'),
    new Session($config->int('dashboard', 'session_idle_s'), $config->int('dashboard', 'session_max_s')),
);
$app->handle(WebRequest::fromGlobals())->send();
