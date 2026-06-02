<?php
declare(strict_types=1);

$app = require __DIR__ . '/bootstrap/app.php';

require __DIR__ . '/routes/api.php';

$app->serve();