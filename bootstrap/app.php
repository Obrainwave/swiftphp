<?php
declare(strict_types=1);

use Swiftphp\Framework\Core\Application;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Create and configure the application.
 */
return(new Application(dirname(__DIR__)))->withProviders(require __DIR__ . '/providers.php')->bootstrap();