<?php

declare(strict_types=1);

namespace RouteForge\Common\Dto;

/**
 * 统一路由信息：框架适配层（RouteNormalizerInterface）把原生路由对象
 * 转换为本 DTO，common 层所有业务逻辑（tier 解析 / 别名 / 仓库 / 类型生成）
 * 只消费本结构，不接触任何框架类。
 *
 * @property-read mixed $source 原始框架路由对象引用（normalizer 填充），
 *                           供 classifier 回调经框架适配层包装取回原对象。
 */
final class RouteInfo
{
    /**
     * @param string[]                    $methods           HTTP 方法（含 HEAD，原始值）
     * @param string[]                    $parameters        路径参数名
     * @param array<string, mixed>        $parameterDefaults 路径参数默认值
     * @param string[]                    $middleware        路由中间件集合（gathered）
     * @param string|null                 $tier              显式/分组透传的层级标记（action['tier']）
     * @param string[]                    $forgeAliases      宏声明的别名列表（action['forge_aliases']）
     * @param mixed                       $source            原始框架路由对象
     */
    public function __construct(
        public readonly ?string $name,
        public readonly string $uri,
        public readonly array $methods,
        public readonly array $parameters,
        public readonly array $parameterDefaults,
        public readonly array $middleware,
        public readonly ?string $tier,
        public readonly array $forgeAliases,
        public readonly mixed $source = null,
    ) {
    }
}
