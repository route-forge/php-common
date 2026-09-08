<?php

declare(strict_types=1);

namespace RouteForge\Common\Analyzer;

use RouteForge\Common\Alias\AliasResolver;
use RouteForge\Common\Contract\RouteNormalizerInterface;
use RouteForge\Common\Dto\RouteInfo;
use RouteForge\Common\Filter\RouteNameFilter;
use RouteForge\Common\Repository\RouteRepository;
use RouteForge\Common\Tier\TierResolver;

/**
 * 路由表分析器：一次遍历把所有命名路由（含别名行）归一化为结构化结果，
 * 供 route:forge:list / route:forge:types 等命令消费（框架无关，命令只负责渲染）。
 *
 * 输出契约（对齐 SPEC §3.2 的 list --json 与 types 收集口径）：
 *   - rows：全量命名路由行，含别名行（alias_of 标记，跟随目标路由的层级归属）；
 *   - tier_counts：过滤前统计（含 unassigned 特殊层级；别名计入目标层级，
 *     与摘要 route_count 口径一致）；
 *   - warnings：非致命配置问题（别名撞车 / 「有 tier 无 name」）；
 *   - aliases / collisions：别名映射与撞车声明（供 --aliases 过滤与表格红行）。
 */
final class RouteAnalyzer
{
    /**
     * @param RouteNameFilter $filter 路由名排除过滤器
     */
    public function __construct(
        private readonly TierResolver $tierResolver,
        private readonly AliasResolver $aliasResolver,
        private readonly RouteNameFilter $filter = new RouteNameFilter(),
    ) {
    }

    /**
     * analyze() 的便捷入口：直接消费框架原生路由集合，由调用方提供的
     * normalizer 逐一归一化。供各框架命令省去手写归一化循环。
     *
     * @param iterable<mixed> $rawRoutes 框架原生路由集合（如 Laravel Router）
     * @param RouteNormalizerInterface $normalizer 框架适配层提供的归一化器
     */
    public function analyzeRoutes(iterable $rawRoutes, RouteNormalizerInterface $normalizer): array
    {
        $infos = [];
        foreach ($rawRoutes as $route) {
            $infos[] = $normalizer->normalize($route);
        }

        return $this->analyze($infos);
    }

    /**
     * @param iterable<RouteInfo> $infos
     *
     * @return array{
     *   rows: list<array{
     *     name:string,
     *     level:string,
     *     uri:string,
     *     methods:string[],
     *     parameters:string[],
     *     parameter_defaults:array<string,mixed>,
     *     middleware:string[],
     *     tier:string|null,
     *     alias_of:string|null,
     *   }>,
     *   tier_counts: array<string,int>,
     *   warnings: string[],
     *   aliases: array<string,string>,
     *   collisions: array<string,string>,
     * }
     */
    public function analyze(iterable $infos): array
    {
        $rows       = [];
        $tierCounts = [];
        $warnings   = [];
        $rowByName  = [];

        foreach ($infos as $info) {
            $name = $info->name;

            // 「有 tier 无 name」的路由无法进入任何元信息（RF_BE_005 仅严格模式抛出），
            // 非严格模式下静默消失排查极难，命令层直接在结果中暴露
            if ($name === null || $name === '') {
                if ($info->tier !== null && $info->tier !== '') {
                    $warnings[] = 'Route (' . $info->uri . ') has tier [' . $info->tier
                        . '] but no route name assigned; it will not appear in any forge endpoint or command output. '
                        . 'Add ->name(...) to the route or remove the tier.';
                }
                continue;
            }
            // 跳过 forge 自身端点路由与框架内部路由
            if ($this->filter->isExcluded($name)) {
                continue;
            }

            // resolve 可能抛 Forge 系异常（RF_BE_001/002/004/005/006），由调用方捕获
            $resolved = $this->tierResolver->resolve($info);
            $level    = $resolved ?? RouteRepository::UNASSIGNED_LEVEL;

            $tierCounts[$level] = ($tierCounts[$level] ?? 0) + 1;

            $row = [
                'name'               => $name,
                'level'              => $level,
                'uri'                => $info->uri,
                'methods'            => $info->methods,
                'parameters'         => $info->parameters,
                'parameter_defaults' => $info->parameterDefaults,
                'middleware'         => $info->middleware,
                'tier'               => $resolved,
                'alias_of'           => null,
            ];
            $rows[]      = $row;
            $rowByName[$name] = $row;
        }

        // 别名条目：跟随目标路由的层级归属，预生成行（alias_of 标记）；
        // 撞车被丢弃的别名由 warnings 反映，不进入 rows
        $aliasResolution = $this->aliasResolver->resolve($infos);
        foreach ($aliasResolution['aliases'] as $alias => $target) {
            $targetRow = $rowByName[$target] ?? null;
            if ($targetRow === null) {
                continue; // 目标为未命名/被排除路由，不可能（resolver 已保证目标为真实命名路由）；防御性跳过
            }
            $tierCounts[$targetRow['level']] = ($tierCounts[$targetRow['level']] ?? 0) + 1;
            $rows[] = [
                'name'               => $alias,
                'level'              => $targetRow['level'],
                'uri'                => $targetRow['uri'],
                'methods'            => $targetRow['methods'],
                'parameters'         => $targetRow['parameters'],
                'parameter_defaults' => $targetRow['parameter_defaults'],
                'middleware'         => $targetRow['middleware'],
                'tier'               => $targetRow['tier'],
                'alias_of'           => $target,
            ];
        }

        return [
            'rows'        => $rows,
            'tier_counts' => $tierCounts,
            'warnings'    => array_merge($aliasResolution['warnings'], $warnings),
            'aliases'     => $aliasResolution['aliases'],
            'collisions'  => $aliasResolution['collisions'],
        ];
    }

    /**
     * 展示用方法列表：过滤 HEAD（与端点元信息、管理器口径一致）。
     *
     * @param string[] $methods
     *
     * @return list<string>
     */
    public static function withoutHead(array $methods): array
    {
        return array_values(array_filter(
            $methods,
            fn (string $m): bool => strtoupper($m) !== 'HEAD',
        ));
    }

    /**
     * 按命令行过滤条件筛 rows（--level / --unassigned / --aliases）。
     *
     * @param list<array{name:string, level:string, tier:string|null, alias_of:string|null, ...}> $rows analyze() 的 rows
     * @param string|null $level --level 值（可含 unassigned 特殊层级）；null/'' 表示不过滤
     *
     * @return list<array<string, mixed>>
     */
    public function filterRows(array $rows, ?string $level, bool $onlyUnassigned = false, bool $onlyAliases = false): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($level !== null && $level !== '' && $r['level'] !== $level) {
                continue;
            }
            // --unassigned 过滤：tier === null 才是未分配（--level=unassigned 语义等价）
            if ($onlyUnassigned && $r['tier'] !== null) {
                continue;
            }
            if ($onlyAliases && $r['alias_of'] === null) {
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * 组装 route:forge:list --json 的输出结构（SPEC §3.2 契约）。
     *
     * 框架无关：命令层（Artisan / ThinkPHP / Symfony）只负责 json_encode 与打印，
     * 结构、键序与口径变化只允许发生在本方法。
     *
     * @param string[] $levels 已配置层级名（决定 tier_counts 的展示顺序）
     * @param list<array<string, mixed>> $rows 经 filterRows() 过滤后的行
     * @param array<string, int> $tierCounts analyze() 的 tier_counts（过滤前统计）
     * @param string[] $warnings analyze() 的 warnings
     *
     * @return array{levels: string[], filter: array<string, mixed>|null, count: int, tier_counts: array<string, int>, warnings: string[], routes: list<array{name: string, level: string, methods: list<string>, uri: string, alias_of: string|null}>}
     */
    public function listPayload(
        array $levels,
        array $rows,
        array $tierCounts,
        array $warnings,
        ?string $level = null,
        bool $onlyUnassigned = false,
        bool $onlyAliases = false,
    ): array {
        // 过滤条件描述
        $filterDesc = [];
        if ($level !== null && $level !== '') {
            $filterDesc['level'] = $level;
        }
        if ($onlyUnassigned) {
            $filterDesc['unassigned'] = true;
        }
        if ($onlyAliases) {
            $filterDesc['aliases'] = true;
        }

        // 层级汇总：全部已配置层级 + unassigned（0 也列出），顺序 = 配置顺序
        $orderedCounts = [];
        foreach ($levels as $l) {
            $orderedCounts[$l] = $tierCounts[$l] ?? 0;
        }
        $orderedCounts['unassigned'] = $tierCounts['unassigned'] ?? 0;

        return [
            'levels'      => array_merge($levels, ['unassigned']),
            'filter'      => $filterDesc === [] ? null : $filterDesc,
            'count'       => count($rows),
            'tier_counts' => $orderedCounts,
            'warnings'    => $warnings,
            'routes'      => array_map(static fn (array $r): array => [
                'name'     => $r['name'],
                'level'    => $r['level'],
                'methods'  => self::withoutHead($r['methods']),
                'uri'      => $r['uri'],
                'alias_of' => $r['alias_of'],
            ], $rows),
        ];
    }
}
