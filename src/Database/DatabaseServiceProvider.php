<?php
declare(strict_types=1);
namespace Swiftphp\Framework\Database;

use Swiftphp\Framework\Core\Container\ServiceProvider;
use Swiftphp\Framework\Database\Pool\PoolFactory;

final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(DBManager::class, function () {

            $connections = config('database.connections', []);
            $default     = config('database.default', 'mysql');

            if (empty($connections)) {
                throw new \RuntimeException(
                    'No database connections configured. '
                    . 'Check config/database.php.'
                );
            }

            $pools = [];

            foreach ($connections as $name => $config) {
                $pools[$name] = PoolFactory::make($name, $config);
            }

            return new DBManager($pools, $default);
        });
    }
}