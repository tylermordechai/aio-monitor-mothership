<?php

/**
 * Agent API front controller on 8443 (ingest-api.md; MS-ING-01 to 12).
 * Apache sends every path that isn't a file here (FallbackResource).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Aio\Api\AgentApi;
use Aio\Config;
use Aio\Http\Request;

AgentApi::fromConfig(Config::load())->handle(Request::fromGlobals($_SERVER))->send();
