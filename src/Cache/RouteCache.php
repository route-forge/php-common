<?php

declare(strict_types=1);

namespace RouteForge\Common\Cache;

use RouteForge\Common\Contract\CacheInterface;
use RouteForge\Common\Exception\CacheDriverException;

/**
 * 路由元信息缓存：按层级独立存放，互不污染。
 *
 * cache key 形如：route-forge:{level}
 *
 * TTL 由构造函数统一传入（对应 config('forge.cache_ttl')）：
 *   - null：不缓存（每次扫描）
 *   - 0：永久缓存
 *   - 正整数：TTL 秒
 *   - 负值：归一化为 null（不缓存）
 *
 * Keys 索引：
 *   为支持 clear() 一次性清空所有层级（底层缓存不支持通配符 key），
 *   维护一个独立的 route-forge:_keys 列表，set() 时追加、forget() 时移除、clear() 时遍历删除。
 *
 * 框架无关：只依赖 {@see CacheInterface}，由框架适配层桥接各自缓存组件。
 */
class RouteCache
{
    private const KEY_PREFIX = 'route-forge:';

    private const KEYS_INDEX = 'route-forge:_keys';

    /**
     * 摘要端点的缓存「层级名」（getSummary 写、route:forge:clear --level 读）。
     *
     * 摘要缓存与层级缓存同表存放：摘要的 route_count 依赖各层级路由数据，
     * 任何层级失效都必须同步失效摘要，否则计数与明细漂移——该不变量由
     * {@see self::forgetLevel()} 封装，各框架 clear 命令一律经它失效层级。
     */
    public const SUMMARY_LEVEL = 'summary';

    private readonly ?CacheInterface $store;
    private readonly bool $debugMode;
    private readonly ?int $ttl;

    /**
     * @param bool     $debugMode 开发模式下跳过所有缓存读写，确保路由变更即时生效
     * @param int|null $ttl       统一缓存 TTL（秒）；null=不缓存，0=永久缓存；负值视为 null（不缓存）
     */
    public function __construct(?CacheInterface $store = null, bool $debugMode = false, ?int $ttl = null)
    {
        $this->store = $store;
        $this->debugMode = $debugMode;
        // 负值 TTL 无意义，降级为不缓存（与 null 行为一致）
        $this->ttl = ($ttl !== null && $ttl < 0) ? null : $ttl;
    }

    /**
     * 取某层级的缓存条目；未缓存或不可用返回 null。
     *
     * @return array<string,mixed>|null
     */
    public function get(string $level): ?array
    {
        if ($this->store === null || $this->debugMode) {
            return null;
        }
        try {
            $value = $this->store->get($this->key($level));

            return is_array($value) ? $value : null;
        } catch (\Throwable $e) {
            throw new CacheDriverException(
                'Cache driver error: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * 写入某层级缓存条目；TTL 由构造函数统一控制。
     *
     * @param array<string,mixed> $payload
     */
    public function set(string $level, array $payload): void
    {
        if ($this->store === null || $this->debugMode) {
            return;
        }
        if ($this->ttl === null) {
            return; // 不缓存
        }
        try {
            $key = $this->key($level);
            // ttl=0 语义为永久缓存：以 null 传给底层（Laravel forever / 等价实现）
            $this->store->put($key, $payload, $this->ttl === 0 ? null : $this->ttl);
            // 维护 keys 索引（用于 clear() 不依赖通配符）
            $this->registerKey($key);
        } catch (\Throwable $e) {
            throw new CacheDriverException(
                'Cache driver error: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function forget(string $level): void
    {
        if ($this->store === null || $this->debugMode) {
            return;
        }
        try {
            $key = $this->key($level);
            $this->store->forget($key);
            $this->unregisterKey($key);
        } catch (\Throwable $e) {
            throw new CacheDriverException(
                'Cache driver error: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * 失效单个层级，并同步失效摘要缓存。
     *
     * 不变量（勿绕过）：摘要的 route_count 依赖各层级路由数据，层级失效后
     * 摘要必须一并失效，否则摘要计数与层级明细漂移。各框架的
     * route:forge:clear --level 实现一律调用本方法，禁止直接 forget($level)。
     */
    public function forgetLevel(string $level): void
    {
        $this->forget($level);
        if ($level !== self::SUMMARY_LEVEL) {
            $this->forget(self::SUMMARY_LEVEL);
        }
    }

    public function clear(): void
    {
        if ($this->store === null) {
            return;
        }
        try {
            $keys = $this->store->get(self::KEYS_INDEX);
            if (is_array($keys)) {
                foreach ($keys as $key) {
                    $this->store->forget((string) $key);
                }
                $this->store->forget(self::KEYS_INDEX);
            }
        } catch (\Throwable $e) {
            throw new CacheDriverException(
                'Cache driver error: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function key(string $level): string
    {
        return self::KEY_PREFIX . $level;
    }

    private function registerKey(string $key): void
    {
        try {
            $keys = $this->store->get(self::KEYS_INDEX);
            $keys = is_array($keys) ? $keys : [];
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
                // 索引本身永久缓存（不随单个 level TTL 失效）
                $this->store->put(self::KEYS_INDEX, $keys, null);
            }
        } catch (\Throwable $e) {
            throw new CacheDriverException(
                'Cache driver error: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    private function unregisterKey(string $key): void
    {
        try {
            $keys = $this->store->get(self::KEYS_INDEX);
            if (!is_array($keys)) {
                return;
            }
            $keys = array_values(array_filter($keys, fn ($k) => $k !== $key));
            $this->store->put(self::KEYS_INDEX, $keys, null);
        } catch (\Throwable $e) {
            throw new CacheDriverException(
                'Cache driver error: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }
}
