<?php
declare(strict_types=1);

use Swoole\Runtime;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->bootstrap();