<?php

declare(strict_types=1);

namespace RouteForge\Common\Tests\Unit\Fixtures;

use RouteForge\Common\Contract\CacheInterface;

/**
 * 内存 CacheInterface 测试实现（框架无关，等价 ArrayStore 语义）。
 *
 * - put 的 $seconds=null 表示永久；
 * - 正数 $seconds 记录过期时间，get 时检查；
 * - forget 删除单键；get/forget 不存在的键安全返回 null/false。
 */
final class MemoryCacheStore implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: ?int}> */
    private array $items = [];

    public function get(string $key): mixed
    {
        if (!isset($this->items[$key])) {
            return null;
        }

        $item = $this->items[$key];
        if ($item['expiresAt'] !== null && $item['expiresAt'] < time()) {
            unset($this->items[$key]);

            return null;
        }

        return $item['value'];
    }

    public function put(string $key, mixed $value, ?int $seconds): void
    {
        $this->items[$key] = [
            'value'     => $value,
            'expiresAt' => $seconds === null ? null : time() + $seconds,
        ];
    }

    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }
}
