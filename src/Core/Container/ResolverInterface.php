<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Core\Container;

interface ResolverInterface
{
    public function resolve(string $class): mixed;
}