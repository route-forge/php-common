<?php

declare(strict_types=1);

namespace RouteForge\Common\Repository;

use RouteForge\Common\Alias\AliasResolver;
use RouteForge\Common\Cache\RouteCache;
use RouteForge\Common\Contract\RouteNormalizerInterface;
use RouteForge\Common\Dto\RouteInfo;
use RouteForge\Common\Exception\UnknownLevelException;
use RouteForge\Common\Filter\RouteNameFilter;
use RouteForge\Common\Tier\TierResolver;

/**
 * 路由仓库：扫描框架路由集合，按层级分组返回元信息。
 *
 * 框架无关：构造函数接收「框架路由可迭代对象 + 框架适配器（normalizer）」，
 * 内部把每个原生路由转换为统一 RouteInfo DTO 后消费；层级解析、缓存、
 * 排除过滤、别名注入等全部业务逻辑在本类内完成，不依赖任何框架类。
 *
 * 元信息结构（对应前端 RouteMeta）：
 *   {
 *     "level": "admin",
 *     "routes": {
 *       "admin.users.show": { "uri": "...", "methods": [...], "parameters": [...], "parameter_defaults": {...} }
 *     }
 *   }
 */
final class RouteRepository
{
    /**
     * 特殊层级名：未命中任何层级的命名路由归属此层级，
     * 通过与已定义层级相同的端点格式获取：GET /{endpoint_prefix}/unassigned
     */
    public const UNASSIGNED_LEVEL = 'unassigned';

    /**
     * 摘要端点响应格式版本（schemeVersion 字段）。
     * 后续迭代引入不兼容的格式变更时递增，前端据此做版本兼容。
     */
    public const SCHEME_VERSION = 1;

    /**
     * 默认排除前缀：forge 自身端点（所有框架通用）。
     * 框架适配层通过 RouteNameFilter::withExtraPrefixes() 追加框架内部路由前缀。
     */
    public const DEFAULT_EXCLUDED_PREFIXES = ['forge.routes.', 'forge.manager.'];

    private readonly AliasResolver $aliasResolver;

    /**
     * @param iterable<mixed>            $routes        框架路由集合（懒加载可迭代对象亦可）
     * @param RouteNormalizerInterface   $normalizer    框架路由 → RouteInfo 适配器
     * @param TierResolver               $tierResolver  层级解析器
     * @param RouteCache                 $cache         路由元信息缓存
     * @param array<string, mixed>       $levelsConfig  levels 配置
     * @param array<string, string>      $aliasesConfig aliases 配置（键=别名，值=真实路由名）
     * @param array<string, mixed>       $runtimeConfig 摘要下发的运行时配置：
     *                                                   endpoint_prefix、url_prefix、
     *                                                   strict_mode、cache_ttl、scheme_version
     * @param RouteNameFilter            $filter        路由名排除过滤器
     */
    public function __construct(
        private readonly iterable $routes,
        private readonly RouteNormalizerInterface $normalizer,
        private readonly TierResolver $tierResolver,
        private readonly RouteCache $cache,
        private readonly array $levelsConfig,
        private readonly array $aliasesConfig = [],
        private readonly array $runtimeConfig = [],
        private readonly RouteNameFilter $filter = new RouteNameFilter(),
    ) {
        $this->aliasResolver = new AliasResolver($aliasesConfig, $filter);
    }

    /**
     * 取某层级下所有命名路由的元信息（带缓存）。
     *
     * level 支持特殊值 unassigned：返回所有未命中层级的命名路由，
     * 响应结构与已定义层级完全一致。
     *
     * 包自身端点路由（forge.*）与框架内部路由（由 filter 的前缀决定）在所有扫描中排除；
     * routes 字段为 stdClass，空层级序列化为 {}（按路由名索引的对象契约）。
     *
     * 别名（SPEC §3.1.7）：经宏或 config aliases 声明的旧名
     * 作为额外键注入目标路由所在层级的 routes，元信息与目标路由完全一致。
     *
     * @return array{
     *   level:string,
     *   routes:\stdClass<string,array{uri:string,methods:string[],parameters:string[],parameter_defaults:\stdClass}>
     * }
     */
    public function getRoutesByLevel(string $level): array
    {
        $isUnassigned = $level === self::UNASSIGNED_LEVEL;
        if (!$isUnassigned && !isset($this->levelsConfig[$level])) {
            throw new UnknownLevelException("Unknown level: $level");
        }

        $cached = $this->cache->get($level);
        if ($cached !== null) {
            return $cached;
        }

        $infos = $this->infos();

        if ($isUnassigned) {
            // unassigned 特殊层级：数据源为未命中任何层级的命名路由（按路由名索引）
            $routes = $this->collectUnassigned($infos);
        } else {
            $routes = [];
            foreach ($infos as $info) {
                if ($info->name === null) {
                    continue; // 未命名路由不出现在元信息里
                }
                // 包自身端点（forge.*）与框架内部路由不属于用户业务路由
                if ($this->filter->isExcluded($info->name)) {
                    continue;
                }
                $resolved = $this->tierResolver->resolve($info);
                if ($resolved !== $level) {
                    continue;
                }
                $routes[$info->name] = [
                    'uri'                => $info->uri,
                    'methods'            => $info->methods,
                    'parameters'         => $info->parameters,
                    'parameter_defaults' => (object) $info->parameterDefaults,
                ];
            }
        }

        // 别名注入（SPEC §3.1.7）：别名条目出现在目标路由所在层级的 routes 中，
        // 元信息与目标路由完全一致（纯复制，无附加标记字段）。
        // unassigned 特殊层级同样注入（别名跟随目标路由的层级归属）。
        // 解析结果随扫描进缓存；悬空别名在此 fail-fast（RF_BE_008）。
        $aliases = $this->aliasResolver->resolve($infos)['aliases'];
        foreach ($aliases as $alias => $target) {
            if (isset($routes[$target])) {
                $routes[$alias] = $routes[$target];
            }
        }

        $payload = [
            'level'  => $level,
            // (object) 强转：空层级序列化为 {} 而非 []，保持「按路由名索引的对象」契约
            'routes' => (object) $routes,
        ];

        $this->cache->set($level, $payload);

        return $payload;
    }

    /**
     * 摘要端点响应（SPEC §3.1.6）：返回格式版本（schemeVersion）、
     * 所有层级概览（含 unassigned 特殊层级）与全局配置。
     *
     * unassigned 路由明细不在摘要中内联返回，前端按摘要中 unassigned 层级
     * 的 route 字段另行请求 GET /{endpoint_prefix}/unassigned 获取。
     *
     * 缓存策略：TTL 由 RouteCache 构造函数统一控制。
     * 缓存 key：route-forge:summary
     *
     * @return array{
     *   schemeVersion: int,
     *   levels: array<string,array{description:string,load:string,route_count:int,route:array{uri:string,methods:string[]}}>,
     *   config: array{strict_mode:bool,endpoint_prefix:string,url_prefix:string|null,cache_ttl:int|null}
     * }
     */
    public function getSummary(): array
    {
        $cached = $this->cache->get(RouteCache::SUMMARY_LEVEL);
        if ($cached !== null) {
            return $cached;
        }

        // levels 概览：每个层级 description/load + route_count + route（端点自描述）
        $levelsSummary    = [];
        $levelRouteCounts = $this->countRoutesPerLevel();
        $endpointPrefix   = $this->normalizedEndpointPrefix();

        foreach ($this->levelsConfig as $level => $cfg) {
            $levelsSummary[$level] = [
                'description' => $cfg['description'] ?? '',
                'load'        => $cfg['load'] ?? 'lazy',
                'route_count' => $levelRouteCounts['counts'][$level] ?? 0,
                'route'       => [
                    'uri'     => "{$endpointPrefix}/{$level}",
                    'methods' => ['GET', 'HEAD'],
                ],
            ];
        }

        // unassigned 特殊层级：与已定义层级结构一致
        $levelsSummary[self::UNASSIGNED_LEVEL] = [
            'description' => '未命中任何层级的路由',
            'load'        => 'lazy',
            'route_count' => $levelRouteCounts['unassigned'],
            'route'       => [
                'uri'     => "{$endpointPrefix}/" . self::UNASSIGNED_LEVEL,
                'methods' => ['GET', 'HEAD'],
            ],
        ];

        // 全局配置摘要
        $cacheTtl = $this->runtimeConfig['cache_ttl'] ?? null;
        $cacheTtl = $cacheTtl !== null ? (int) $cacheTtl : null;
        // 负值与 RouteCache 构造函数同款归一化：视为不缓存（null），
        // 保证摘要下发的值与实际缓存行为一致
        if ($cacheTtl !== null && $cacheTtl < 0) {
            $cacheTtl = null;
        }
        $urlPrefix = $this->runtimeConfig['url_prefix'] ?? null;
        $config = [
            'strict_mode'     => (bool) ($this->runtimeConfig['strict_mode'] ?? false),
            // 与端点注册路径保持同一规范化（/ 前缀、无尾部斜杠），
            // 避免自定义 endpoint_prefix（如 'forge/routes/'）下发值与实际路径不一致
            'endpoint_prefix' => $endpointPrefix,
            'url_prefix'      => is_string($urlPrefix) && $urlPrefix !== '' ? $urlPrefix : null,
            // 统一转为 int|null
            'cache_ttl'       => $cacheTtl,
        ];

        $payload = [
            'schemeVersion' => (int) ($this->runtimeConfig['scheme_version'] ?? self::SCHEME_VERSION),
            'levels'        => $levelsSummary,
            'config'        => $config,
        ];

        $this->cache->set(RouteCache::SUMMARY_LEVEL, $payload);

        return $payload;
    }

    /**
     * 扫描所有命名路由，返回每个层级命中的路由数量及未分配数量。
     *
     * 一次遍历同时统计各层级命中数和 unassigned 数，
     * 避免 getSummary() 再调用 getUnassignedRoutes() 造成二次全量扫描。
     *
     * 别名（SPEC §3.1.7）计入目标路由所在层级的 route_count，
     * 与层级端点实际返回的 routes 键数量保持一致。
     *
     * @return array{counts: array<string,int>, unassigned: int}
     */
    private function countRoutesPerLevel(): array
    {
        $counts = [];
        $unassigned = 0;
        $levelByName = []; // 真实路由名 => 层级（含 unassigned），供别名归属统计
        $infos = $this->infos();
        foreach ($infos as $info) {
            $name = $info->name;
            if ($name === null) {
                continue;
            }
            // 包自身端点（forge.*）与框架内部路由不计入任何层级统计
            if ($this->filter->isExcluded($name)) {
                continue;
            }
            $resolved = $this->tierResolver->resolve($info);
            if ($resolved !== null) {
                $counts[$resolved] = ($counts[$resolved] ?? 0) + 1;
                $levelByName[$name] = $resolved;
            } else {
                $unassigned++;
                $levelByName[$name] = self::UNASSIGNED_LEVEL;
            }
        }

        // 别名计入目标路由所在层级（unassigned 归入特殊层级计数）
        $aliases = $this->aliasResolver->resolve($infos)['aliases'];
        foreach ($aliases as $target) {
            $targetLevel = $levelByName[$target] ?? null;
            if ($targetLevel === self::UNASSIGNED_LEVEL) {
                $unassigned++;
            } elseif ($targetLevel !== null) {
                $counts[$targetLevel] = ($counts[$targetLevel] ?? 0) + 1;
            }
        }

        return ['counts' => $counts, 'unassigned' => $unassigned];
    }

    /**
     * 获取所有命名路由及其层级分配结果（管理器页面 / 命令共用，不缓存）。
     *
     * 一次遍历收集所有路由的元信息与层级归属，供管理器页面按层级分组展示、
     * 搜索过滤与详情查看。
     *
     * @return array{routes: list<array{name:string,uri:string,methods:string[],parameters:string[],parameter_defaults:array<string,mixed>,middleware:string[],tier:string}>,
     *                       tiers: array<string,int>}
     */
    public function getAllRoutesWithTiers(): array
    {
        $routes = [];
        $tiers  = [];

        $infos = $this->infos();
        foreach ($infos as $info) {
            $name = $info->name;
            if ($name === null || $name === '') {
                continue;
            }
            // 跳过 forge 自身端点路由与框架内部路由
            if ($this->filter->isExcluded($name)) {
                continue;
            }

            $resolved     = $this->tierResolver->resolve($info);
            $tier         = $resolved ?? self::UNASSIGNED_LEVEL;
            $tiers[$tier] = ($tiers[$tier] ?? 0) + 1;

            $routes[] = [
                'name'               => $name,
                'uri'                => $info->uri,
                'methods'            => array_values(array_filter(
                    $info->methods,
                    fn (string $m) => strtoupper($m) !== 'HEAD',
                )),
                'parameters'         => $info->parameters,
                'parameter_defaults' => $info->parameterDefaults,
                'middleware'         => $info->middleware,
                'tier'               => $tier,
            ];
        }

        // 别名条目（SPEC §3.1.7）：带 alias_of 标记，跟随目标路由的层级归属；
        // 撞车被丢弃的别名由 resolver 的 warnings 反映，管理器侧忽略（条目不出现即被丢弃）
        // 目标名以多次注册命中多个 tier 时逐 tier 铺开，与层级端点的别名注入保持一致
        $aliasMap = $this->aliasResolver->resolve($infos);
        $rowsByName = [];
        foreach ($routes as $routeRow) {
            $rowsByName[$routeRow['name']][] = $routeRow;
        }
        foreach ($aliasMap['aliases'] as $alias => $target) {
            $targetRows = $rowsByName[$target] ?? [];
            if ($targetRows === []) {
                continue; // 目标为未命名/被排除路由，不可能（resolver 已保证目标为真实命名路由）；防御性跳过
            }

            // 计数口径与摘要 route_count 一致：一个别名只计一次，计在末次注册 tier
            $lastRow = $targetRows[array_key_last($targetRows)];
            $tiers[$lastRow['tier']] = ($tiers[$lastRow['tier']] ?? 0) + 1;

            $emittedTiers = [];
            foreach ($targetRows as $targetRow) {
                if (isset($emittedTiers[$targetRow['tier']])) {
                    continue;
                }
                $emittedTiers[$targetRow['tier']] = true;

                $routes[] = [
                    'name'               => $alias,
                    'uri'                => $targetRow['uri'],
                    'methods'            => $targetRow['methods'],
                    'parameters'         => $targetRow['parameters'],
                    'parameter_defaults' => $targetRow['parameter_defaults'],
                    'middleware'         => $targetRow['middleware'],
                    'tier'               => $targetRow['tier'],
                    'alias_of'           => $target,
                ];
            }
        }

        return ['routes' => $routes, 'tiers' => $tiers];
    }

    /**
     * unassigned 特殊层级的路由元信息（按路由名索引，与层级端点 routes 结构一致）。
     *
     * tierResolver->resolve() 返回 null 的命名路由即为"未分配"，
     * strict_mode=false 时未命中任何层级的路由统一归入此特殊层级。
     *
     * @return array<string,array{uri:string,methods:string[],parameters:string[],parameter_defaults:array<string,mixed>}>
     */
    public function getUnassignedRoutes(): array
    {
        return $this->collectUnassigned($this->infos());
    }

    /**
     * 全量转换框架路由集合为 RouteInfo 列表（单次遍历，各方法内部复用）。
     *
     * @return list<RouteInfo>
     */
    private function infos(): array
    {
        $infos = [];
        foreach ($this->routes as $route) {
            $infos[] = $this->normalizer->normalize($route);
        }

        return $infos;
    }

    /**
     * 从未命中任何层级的命名路由中收集 unassigned 元信息。
     *
     * @param list<RouteInfo> $infos
     *
     * @return array<string,array{uri:string,methods:string[],parameters:string[],parameter_defaults:array<string,mixed>}>
     */
    private function collectUnassigned(array $infos): array
    {
        $unassigned = [];
        foreach ($infos as $info) {
            $name = $info->name;
            if ($name === null) {
                continue;
            }
            // 包自身端点（forge.*）与框架内部路由不属于用户业务路由，
            // 不进入 unassigned 元信息（否则前端会把包内部路由当作用户路由消费）
            if ($this->filter->isExcluded($name)) {
                continue;
            }
            if ($this->tierResolver->resolve($info) === null) {
                $unassigned[$name] = [
                    'uri'                => $info->uri,
                    'methods'            => $info->methods,
                    'parameters'         => $info->parameters,
                    'parameter_defaults' => (object) $info->parameterDefaults,
                ];
            }
        }

        return $unassigned;
    }

    /**
     * 端点前缀规范化：确保前导 /、去除尾部 /，避免双斜杠。
     *
     * 公共静态工具：端点注册、摘要下发（endpoint_prefix）与各框架
     * types 命令的文件头注释必须使用同一规范化，防止自定义 prefix
     * （如 'forge/routes/'）后各处取值失真。
     */
    public static function normalizeEndpointPrefix(string $prefix): string
    {
        return '/' . ltrim(rtrim($prefix, '/'), '/');
    }

    private function normalizedEndpointPrefix(): string
    {
        return self::normalizeEndpointPrefix(
            (string) ($this->runtimeConfig['endpoint_prefix'] ?? '/_forge/routes'),
        );
    }
}
