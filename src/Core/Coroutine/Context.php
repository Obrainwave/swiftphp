<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Core\Coroutine;

use Swoole\Coroutine;

/**
 * Coroutine-local context store.
 *
 * Provides isolated state storage per coroutine.
 * Child coroutines can read up the parent chain, but all writes are isolated.
 */
final class Context
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private static array $storage = [];

    /**
     * @var array<int, int>
     */
    private static array $parents = [];

    /**
     * @var array<int, array<int>>
     */
    private static array $tree = [];

    public static function set(string $key, mixed $value): void
    {
        $cid = self::cid();
        self::bootstrap($cid);

        self::$storage[$cid][$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cid = self::cid();
        self::bootstrap($cid);

        // Traverse up the coroutine parent chain to find the key
        $currentCid = $cid;
        while ($currentCid !== null) {
            if (isset(self::$storage[$currentCid]) && array_key_exists($key, self::$storage[$currentCid])) {
                return self::$storage[$currentCid][$key];
            }
            $currentCid = self::$parents[$currentCid] ?? null;
        }

        return $default;
    }

    public static function has(string $key): bool
    {
        $cid = self::cid();
        self::bootstrap($cid);

        $currentCid = $cid;
        while ($currentCid !== null) {
            if (isset(self::$storage[$currentCid]) && array_key_exists($key, self::$storage[$currentCid])) {
                return true;
            }
            $currentCid = self::$parents[$currentCid] ?? null;
        }

        return false;
    }

    /**
     * Merges and returns the entire visible context spectrum for this coroutine.
     * Local keys take precedence over parent keys.
     */
    public static function all(): array
    {
        $cid = self::cid();
        self::bootstrap($cid);

        $chain = [];
        $currentCid = $cid;

        // Collect all storage arrays up the chain
        while ($currentCid !== null) {
            if (isset(self::$storage[$currentCid])) {
                $chain[] = self::$storage[$currentCid];
            }
            $currentCid = self::$parents[$currentCid] ?? null;
        }

        // Merge from the root parent down to the child so local overrides win
        if (empty($chain)) {
            return [];
        }
        return array_merge(...array_reverse($chain));
    }

    public static function destroy(?int $cid = null): void
    {
        $cid ??= self::cid();

        // 1. Bulk clean all child coroutine contexts tracked under this root tree
        if (isset(self::$tree[$cid])) {
            foreach (self::$tree[$cid] as $childCid) {
                unset(self::$storage[$childCid]);
                unset(self::$parents[$childCid]);
            }
            unset(self::$tree[$cid]);
        }

        // 2. Clean up local state
        unset(self::$storage[$cid]);
        unset(self::$parents[$cid]);
    }

    /**
     * Registers a child coroutine into the request tree for isolation.
     * Storage remains empty so changes to the parent can be read in real-time.
     */
    public static function inherit(int $parentCid, int $childCid): void
    {
        self::bootstrap($parentCid);

        self::$parents[$childCid] = $parentCid;

        // Track in tree for bulk cleanup at the end of the root HTTP request
        $rootCid = $parentCid;
        while (isset(self::$parents[$rootCid])) {
            $rootCid = self::$parents[$rootCid];
        }
        self::$tree[$rootCid][] = $childCid;

        // Starts isolated and empty. Writes stay here; reads fallback to parent.
        self::$storage[$childCid] = [];
    }

    private static function bootstrap(int $cid): void
    {
        if (isset(self::$storage[$cid])) {
            return;
        }

        $pcid = Coroutine::getPcid($cid);

        if ($pcid > 0 && isset(self::$storage[$pcid])) {
            self::inherit($pcid, $cid);
            return;
        }

        self::$storage[$cid] = [];
    }

    private static function cid(): int
    {
        $cid = Coroutine::getCid();

        if ($cid < 0) {
            throw new \RuntimeException(
                'Context cannot be used outside coroutine scope.'
            );
        }

        return $cid;
    }
}
