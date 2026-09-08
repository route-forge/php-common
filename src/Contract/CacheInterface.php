<?php

declare(strict_types=1);

namespace RouteForge\Common\Contract;

/**
 * 路由元信息缓存的框架无关契约。
 *
 * 由各框架适配层桥接到自身缓存组件（Laravel Cache / ThinkPHP Cache / PSR-6 等）。
 * RouteCache 依赖本接口完成层级元信息与摘要的缓存读写。
 *
 * put() 的 $seconds 语义（与 SPEC §3.1.5 对齐）：
 *   - null：永久缓存（对应 Laravel forever）
 *   - 0：永久缓存（SPEC 语义，RouteCache 已把 0 归一化为 null 传入）
 *   - 正整数：TTL 秒
 */
interface CacheInterface
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, ?int $seconds): void;

    public function forget(string $key): void;
}
