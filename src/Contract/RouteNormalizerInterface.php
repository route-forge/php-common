<?php

declare(strict_types=1);

namespace RouteForge\Common\Contract;

use RouteForge\Common\Dto\RouteInfo;

/**
 * 框架路由 → 统一路由信息（RouteInfo）的转换契约。
 *
 * 每个框架适配包（Laravel / ThinkPHP / Symfony）实现本接口，
 * 把框架原生路由对象转换为 common 层统一消费的 RouteInfo DTO，
 * 使 tier 解析、别名、仓库、类型生成等业务逻辑完全框架无关。
 *
 * RouteInfo::source 保留原始框架路由对象引用，供 classifier 回调
 * （用户按各自框架类型书写）经适配层包装后取回原对象使用。
 */
interface RouteNormalizerInterface
{
    public function normalize(mixed $route): RouteInfo;
}
