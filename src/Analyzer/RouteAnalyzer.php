<?php

declare(strict_types=1);

namespace RouteForge\Common\Analyzer;

use RouteForge\Common\Alias\AliasResolver;
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
}
