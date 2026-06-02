<?php

use Swiftphp\Framework\Core\Config\Config;
use Swiftphp\Framework\Core\Container\Container;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true',  '(true)'  => true,
            'false', '(false)' => false,
            'null',  '(null)'  => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (! function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        $config = new \Swiftphp\Framework\Core\Config\Config();
        $config->load('config');
        return $config->get($key, $default);
    }
}