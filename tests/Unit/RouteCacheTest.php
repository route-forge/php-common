<?php

declare(strict_types=1);

namespace RouteForge\Common\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\Common\Cache\RouteCache;
use RouteForge\Common\Tests\Unit\Fixtures\MemoryCacheStore;

/**
 * RouteCache 单元测试（框架无关，基于 MemoryCacheStore）。
 *
 * 重点覆盖 clear() 行为：基于 route-forge:_keys 索引清空所有层级，
 * 而非依赖缓存驱动的通配符语义。
 *
 * TTL 由构造函数统一传入，set() 不再从 payload 中读取 cache 字段。
 * 驱动无关：Redis 等真实驱动复用同一代码路径（clear 只遍历 keys 索引），
 * 故不单列真实驱动测试（如需可另建带 service 的 CI job）。
 */
class RouteCacheTest extends TestCase
{
    private function makeCache(?int $ttl = 60): RouteCache
    {
        return new RouteCache(new MemoryCacheStore(), ttl: $ttl);
    }

    public function test_set_and_get_returns_payload(): void
    {
        $cache = $this->makeCache();
        $payload = ['level' => 'admin', 'routes' => []];
        $cache->set('admin', $payload);
        $this->assertSame($payload, $cache->get('admin'));
    }

    public function test_set_with_null_ttl_does_not_cache(): void
    {
        $cache = $this->makeCache(ttl: null);
        $payload = ['level' => 'admin', 'routes' => []];
        $cache->set('admin', $payload);
        $this->assertNull($cache->get('admin'));
    }

    public function test_set_with_zero_ttl_caches_forever(): void
    {
        $cache = $this->makeCache(ttl: 0);
        $payload = ['level' => 'admin', 'routes' => []];
        $cache->set('admin', $payload);
        $this->assertSame($payload, $cache->get('admin'));
    }

    public function test_forget_removes_single_level(): void
    {
        $cache = $this->makeCache();
        $cache->set('admin', ['level' => 'admin', 'routes' => []]);
        $cache->set('client', ['level' => 'client', 'routes' => []]);
        $cache->forget('admin');
        $this->assertNull($cache->get('admin'));
        $this->assertNotNull($cache->get('client'));
    }

    public function test_clear_removes_all_levels(): void
    {
        $cache = $this->makeCache();
        $cache->set('public', ['level' => 'public', 'routes' => []]);
        $cache->set('client', ['level' => 'client', 'routes' => []]);
        $cache->set('manage', ['level' => 'manage', 'routes' => []]);
        $cache->set('admin', ['level' => 'admin', 'routes' => []]);

        $cache->clear();

        $this->assertNull($cache->get('public'));
        $this->assertNull($cache->get('client'));
        $this->assertNull($cache->get('manage'));
        $this->assertNull($cache->get('admin'));
    }

    public function test_clear_does_not_rely_on_wildcards(): void
    {
        // 验证 clear() 通过 keys-index 工作，而非依赖通配符语义。
        $cache = $this->makeCache();
        $cache->set('admin', ['level' => 'admin', 'routes' => []]);
        $cache->clear();
        $this->assertNull($cache->get('admin'));
    }

    public function test_clear_also_clears_keys_index(): void
    {
        // clear 后再 set 新 level 应能正常工作，且 keys 索引不残留旧数据
        $cache = $this->makeCache();
        $cache->set('admin', ['level' => 'admin', 'routes' => []]);
        $cache->clear();
        $cache->set('client', ['level' => 'client', 'routes' => []]);
        $cache->clear();
        $this->assertNull($cache->get('admin'));
        $this->assertNull($cache->get('client'));
    }

    public function test_get_with_null_store_returns_null(): void
    {
        $cache = new RouteCache(null);
        $this->assertNull($cache->get('any'));
    }

    public function test_set_with_null_store_is_noop(): void
    {
        $cache = new RouteCache(null);
        $cache->set('any', ['level' => 'any', 'routes' => []]); // should not throw
        $this->assertNull($cache->get('any'));
    }

    public function test_clear_with_null_store_is_noop(): void
    {
        $cache = new RouteCache(null);
        $cache->clear(); // should not throw
        $this->assertTrue(true);
    }

    public function test_debug_mode_skips_set_and_get(): void
    {
        $cache = new RouteCache(new MemoryCacheStore(), debugMode: true, ttl: 60);
        $payload = ['level' => 'admin', 'routes' => []];
        $cache->set('admin', $payload);
        $this->assertNull($cache->get('admin'));
    }

    public function test_debug_mode_does_not_throw_on_clear(): void
    {
        $cache = new RouteCache(new MemoryCacheStore(), debugMode: true);
        $cache->clear(); // should not throw
        $this->assertTrue(true);
    }

    public function test_positive_ttl_entry_expires(): void
    {
        // 真实 TTL 过期：正整数 TTL 写入后，超过有效期应返回 null
        $cache   = $this->makeCache(ttl: 1);
        $payload = ['level' => 'admin', 'routes' => []];
        $cache->set('admin', $payload);

        // 立即读取应命中
        $this->assertSame($payload, $cache->get('admin'));

        // 超过 TTL 后应过期
        sleep(2);
        $this->assertNull($cache->get('admin'));
    }
}
