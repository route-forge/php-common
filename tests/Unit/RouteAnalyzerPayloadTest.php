<?php

declare(strict_types=1);

namespace RouteForge\Common\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\Common\Alias\AliasResolver;
use RouteForge\Common\Analyzer\RouteAnalyzer;
use RouteForge\Common\Contract\RouteNormalizerInterface;
use RouteForge\Common\Dto\RouteInfo;
use RouteForge\Common\Filter\RouteNameFilter;
use RouteForge\Common\Tier\TierResolver;

/**
 * RouteAnalyzer 的 list --json 契约组装（listPayload）与行过滤（filterRows）
 * 单元测试：analyze() 的端到端行为由框架侧 Feature 测试覆盖。
 */
class RouteAnalyzerPayloadTest extends TestCase
{
    private RouteAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new RouteAnalyzer(
            new TierResolver([]),
            new AliasResolver([]),
            new RouteNameFilter(),
        );
    }

    /**
     * 一行 analyzer row 的工厂。
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'name'               => 'a',
            'level'              => 'admin',
            'tier'               => 'admin',
            'methods'            => ['GET', 'HEAD'],
            'uri'                => 'a',
            'parameters'         => [],
            'parameter_defaults' => [],
            'alias_of'           => null,
        ], $overrides);
    }

    public function test_filter_rows_by_level_unassigned_and_aliases(): void
    {
        $rows = [
            $this->row(['name' => 'real', 'level' => 'admin', 'tier' => 'admin', 'alias_of' => null]),
            $this->row(['name' => 'alias', 'level' => 'admin', 'tier' => 'admin', 'alias_of' => 'real']),
            $this->row(['name' => 'loose', 'level' => 'unassigned', 'tier' => null]),
            $this->row(['name' => 'pub', 'level' => 'public', 'tier' => 'public']),
        ];

        $this->assertSame(
            ['real', 'alias', 'loose', 'pub'],
            array_column($this->analyzer->filterRows($rows, null), 'name'),
        );
        $this->assertSame(
            ['real', 'alias'],
            array_column($this->analyzer->filterRows($rows, 'admin'), 'name'),
        );
        $this->assertSame(
            ['loose'],
            array_column($this->analyzer->filterRows($rows, null, onlyUnassigned: true), 'name'),
        );
        $this->assertSame(
            ['alias'],
            array_column($this->analyzer->filterRows($rows, null, onlyAliases: true), 'name'),
        );
        // --level=unassigned 与 --unassigned 语义等价
        $this->assertSame(
            ['loose'],
            array_column($this->analyzer->filterRows($rows, 'unassigned'), 'name'),
        );
    }

    public function test_list_payload_shape_and_order(): void
    {
        $rows = [$this->row(), $this->row(['name' => 'alias', 'alias_of' => 'a'])];
        $tierCounts = ['admin' => 2, 'unassigned' => 1];

        $payload = $this->analyzer->listPayload(['admin', 'client'], $rows, $tierCounts, ['warn']);

        // 契约键序（SPEC §3.2）
        $this->assertSame(['levels', 'filter', 'count', 'tier_counts', 'warnings', 'routes'], array_keys($payload));
        $this->assertSame(['admin', 'client', 'unassigned'], $payload['levels']);
        $this->assertNull($payload['filter']);
        $this->assertSame(2, $payload['count']);
        // tier_counts：已配置层级按配置顺序（0 也列出）+ unassigned 收尾
        $this->assertSame(['admin' => 2, 'client' => 0, 'unassigned' => 1], $payload['tier_counts']);
        $this->assertSame(['warn'], $payload['warnings']);
        // routes 行：HEAD 已过滤，字段为契约五键
        $this->assertSame(['GET'], $payload['routes'][0]['methods']);
        $this->assertSame(['name', 'level', 'methods', 'uri', 'alias_of'], array_keys($payload['routes'][0]));
        $this->assertSame('a', $payload['routes'][1]['alias_of']);
    }

    public function test_list_payload_filter_description(): void
    {
        $payload = $this->analyzer->listPayload([], [], [], [], 'admin', true, true);

        $this->assertSame(['level' => 'admin', 'unassigned' => true, 'aliases' => true], $payload['filter']);
    }

    public function test_analyze_routes_normalizes_raw_collection(): void
    {
        // analyzeRoutes 便捷入口：raw 集合经 normalizer 逐一归一化后走同一 analyze 管道
        $normalizer = new class implements RouteNormalizerInterface {
            public function normalize(mixed $route): RouteInfo
            {
                return new RouteInfo(
                    name: $route['name'],
                    uri: $route['uri'],
                    methods: ['GET'],
                    parameters: [],
                    parameterDefaults: [],
                    middleware: [],
                    tier: 'admin',
                    forgeAliases: [],
                    source: $route,
                );
            }
        };

        $analysis = (new RouteAnalyzer(
            new TierResolver(['admin' => ['description' => '', 'load' => 'lazy']]),
            new AliasResolver([]),
            new RouteNameFilter(),
        ))->analyzeRoutes(
            [['name' => 'a', 'uri' => 'a'], ['name' => 'b', 'uri' => 'b']],
            $normalizer,
        );

        $this->assertSame(['a', 'b'], array_column($analysis['rows'], 'name'));
        $this->assertSame(['admin' => 2], $analysis['tier_counts']);
    }
}
