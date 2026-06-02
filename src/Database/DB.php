<?php
declare(strict_types=1);
namespace Swiftphp\Framework\Database;

use Swiftphp\Framework\Core\Support\Facade;

final class DB extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DBManager::class;
    }
}