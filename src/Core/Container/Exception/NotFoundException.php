<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Core\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class NotFoundException extends RuntimeException implements NotFoundExceptionInterface {}