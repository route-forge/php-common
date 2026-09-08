<?php

declare(strict_types=1);

namespace RouteForge\Common\Filter;

/**
 * 路由名排除过滤器：判断路由是否应从 forge 的所有用户可见输出中排除。
 *
 * 排除规则由「forge 自身端点前缀」（所有框架通用）与「框架内部路由前缀」
 * （框架特有，如 Laravel 12+ 的 storage.*）组成，经构造注入合并。
 *
 * 为什么必须排除：
 *   - forge 自身端点（forge.routes.* / forge.manager.*）永远不带 tier，
 *     strict_mode=true 时若不排除会让严格模式因包自身路由必然抛 RF_BE_001；
 *   - 框架内部路由（如 storage.*）混在用户路由表中，不属于用户业务路由，
 *     不应出现在任何元信息端点、命令输出与统计中。
 */
final class RouteNameFilter
{
    /**
     * 所有框架通用的 forge 自身端点前缀。
     */
    public const FORGE_PREFIXES = ['forge.routes.', 'forge.manager.'];

    /**
     * @param string[] $excludedPrefixes 完整排除前缀列表
     *                                  （默认仅 forge 自身；框架适配层追加框架内部前缀）
     */
    public function __construct(
        private readonly array $excludedPrefixes = self::FORGE_PREFIXES,
    ) {
    }

    /**
     * 判断路由名是否命中任一排除前缀。
     */
    public function isExcluded(string $name): bool
    {
        foreach ($this->excludedPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 快捷工厂：在默认 forge 前缀之外追加框架内部路由前缀。
     *
     * @param string[] $extraPrefixes
     */
    public static function withExtraPrefixes(array $extraPrefixes): self
    {
        return new self(array_values(array_unique([...self::FORGE_PREFIXES, ...$extraPrefixes])));
    }
}
