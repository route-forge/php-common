<?php

declare(strict_types=1);

namespace RouteForge\Common\Config;

/**
 * config/forge.php 文件内容生成器（管理器页面「配置编辑」的落盘层）。
 *
 * 框架无关：把 levels 与 global 配置序列化为 PHP 数组字面量源码；
 * 各框架管理器控制器只负责请求校验与文件写入，本类只做纯文本生成。
 *
 * 安全设计：所有动态值经 var_export / intval / 显式布尔转换拼入，
 * 层级名等字符串不会被当作代码执行（层级名转义防注入，见原管理器测试 php -l）。
 */
final class ConfigFileGenerator
{
    /**
     * 生成 config/forge.php 文件内容。
     *
     * @param array<string, mixed> $levels    层级配置（JSON 编辑器提交）
     * @param array<string, mixed> $global    全局设置
     * @param array<string, mixed> $preserved 不在表单中编辑、需原样透传的配置项：
     *                                        endpoint_middleware / manager_allowed_ips / aliases
     */
    public function generate(array $levels, array $global, array $preserved): string
    {
        $levelsCode = $this->formatLevelsArray($levels);

        $endpointPrefix = $this->exportScalar($global['endpoint_prefix'] ?? '/_forge/routes');
        $urlPrefix      = $this->exportScalar($global['url_prefix'] ?? null);
        $cacheTtl       = $this->exportScalar($global['cache_ttl'] ?? 3600);
        $cacheDriver    = $this->exportScalar($global['cache_driver'] ?? null);
        $strictMode     = ($global['strict_mode'] ?? false) ? 'true' : 'false';
        $schemeVersion  = (int) ($global['scheme_version'] ?? 1);
        // 摘要端点中间件不在表单中编辑，保留现有配置值避免保存时丢失
        $endpointMiddleware = $this->exportInlineArray(
            array_values((array) ($preserved['endpoint_middleware'] ?? []))
        );
        // 管理器 IP 白名单同样不在表单中编辑，保留现有配置值
        $managerAllowedIps = $this->exportInlineArray(
            array_values(array_map('strval', (array) ($preserved['manager_allowed_ips'] ?? ['127.0.0.1', '::1'])))
        );
        // 路由别名映射表同样不在表单中编辑，保留现有配置值避免保存时静默丢失
        // （SPEC §3.1.7：aliases 是集中声明通道之一，被抹掉会让前端旧路由名全部失效）
        $aliases = $this->exportAssocArray((array) ($preserved['aliases'] ?? []));

        return <<<PHP
<?php

declare(strict_types=1);

/**
 * Route Forge 配置文件（由管理器页面生成）
 *
 * @see .docs/SPEC.md §3.1.2, §5
 */
return [

    /*
    |--------------------------------------------------------------------------
    | 层级定义表
    |--------------------------------------------------------------------------
    */
    'levels'            => {$levelsCode},

    /*
    |--------------------------------------------------------------------------
    | 路由元信息端点前缀
    |--------------------------------------------------------------------------
    */
    'endpoint_prefix'   => {$endpointPrefix},

    /*
    |--------------------------------------------------------------------------
    | 路由前缀（url_prefix）
    |--------------------------------------------------------------------------
    */
    'url_prefix' => {$urlPrefix},

    /*
    |--------------------------------------------------------------------------
    | 摘要端点中间件
    |--------------------------------------------------------------------------
    */
    'endpoint_middleware' => {$endpointMiddleware},

    /*
    |--------------------------------------------------------------------------
    | 缓存 TTL（秒）
    |--------------------------------------------------------------------------
    */
    'cache_ttl'         => {$cacheTtl},

    /*
    |--------------------------------------------------------------------------
    | 缓存驱动
    |--------------------------------------------------------------------------
    */
    'cache_driver'      => {$cacheDriver},

    /*
    |--------------------------------------------------------------------------
    | 严格模式
    |--------------------------------------------------------------------------
    */
    'strict_mode'       => {$strictMode},

    /*
    |--------------------------------------------------------------------------
    | 摘要端点响应格式版本（schemeVersion）
    |--------------------------------------------------------------------------
    */
    'scheme_version'    => {$schemeVersion},

    /*
    |--------------------------------------------------------------------------
    | 自定义分类回调
    |--------------------------------------------------------------------------
    */
    'classifier'        => null,

    /*
    |--------------------------------------------------------------------------
    | 管理器页面允许访问的 IP 列表
    |--------------------------------------------------------------------------
    */
    'manager_allowed_ips' => {$managerAllowedIps},

    /*
    |--------------------------------------------------------------------------
    | 路由别名映射表（aliases）
    |--------------------------------------------------------------------------
    */
    'aliases'           => {$aliases},
];

PHP;
    }

    /**
     * 格式化 levels 配置数组为 PHP 代码字符串。
     *
     * @param array<string, mixed> $levels
     */
    private function formatLevelsArray(array $levels): string
    {
        if (empty($levels)) {
            return '[]';
        }

        $parts = [];
        foreach ($levels as $name => $config) {
            $parts[] = $this->formatLevelEntry((string) $name, $config);
        }

        return "[\n" . implode("\n", $parts) . '    ]';
    }

    /**
     * 格式化单个层级配置条目。
     *
     * 层级名经 var_export 转义后拼入，避免特殊字符破坏
     * 生成的 PHP 文件语法（甚至注入代码）。
     *
     * @param array<string, mixed> $config
     */
    private function formatLevelEntry(string $name, array $config): string
    {
        $i       = '        '; // 8-space indent
        $lines   = [];
        $lines[] = "{$i}" . var_export($name, true) . ' => [';

        $lines[] = "{$i}    'description' => " . var_export($config['description'] ?? '',
                true) . ',';

        $match   = $config['match'] ?? [];
        $lines[] = "{$i}    'match' => " . $this->formatMatchArray($match, $i . '    ') . ',';

        $lines[] = "{$i}    'load'  => " . var_export($config['load'] ?? 'lazy', true) . ',';

        if (isset($config['endpoint_middleware']) && !empty($config['endpoint_middleware'])) {
            $lines[] = "{$i}    'endpoint_middleware' => " . $this->exportInlineArray((array) $config['endpoint_middleware']) . ',';
        }

        $lines[] = "{$i}],";

        return implode("\n", $lines);
    }

    /**
     * 格式化 match 规则数组。
     *
     * @param array<string, mixed> $match
     */
    private function formatMatchArray(array $match, string $indent): string
    {
        $parts = [];

        // 类型归一化（与 TierResolver::matchConfig 同口径）：prefix / middleware 除数组外
        // 也接受单个字符串（标量→单元素数组，null→[]），避免单值写法在 exportInlineArray
        // 的 array 类型声明上崩溃；落盘被规范化为数组字面量，语义不变。
        $prefix     = (array) ($match['prefix'] ?? []);
        $middleware = (array) ($match['middleware'] ?? []);
        $parts[]    = "'prefix' => " . $this->exportInlineArray($prefix);
        $parts[]    = "'middleware' => " . $this->exportInlineArray($middleware);

        if (isset($match['middleware_match'])) {
            $mm = $match['middleware_match'];
            if (is_string($mm)) {
                $parts[] = "'middleware_match' => " . var_export($mm, true);
            } elseif (is_array($mm)) {
                $parts[] = "'middleware_match' => " . $this->exportDnfArray($mm, $indent);
            }
        }

        return "[\n" . $indent . '    ' . implode(",\n" . $indent . '    ',
                $parts) . ",\n" . $indent . ']';
    }

    /**
     * 将简单索引数组导出为单行 PHP 数组字面量。
     */
    private function exportInlineArray(array $arr): string
    {
        if (empty($arr)) {
            return '[]';
        }
        $items = array_map(fn ($v) => var_export($v, true), array_values($arr));

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * 将字符串关联数组（键=别名，值=真实路由名）导出为单行 PHP 数组字面量。
     *
     * @param array<string, mixed> $map
     */
    private function exportAssocArray(array $map): string
    {
        if (empty($map)) {
            return '[]';
        }
        $items = [];
        foreach ($map as $alias => $target) {
            $items[] = var_export((string) $alias, true) . ' => ' . var_export((string) $target, true);
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * 将 DNF 嵌套数组格式化为多行 PHP 数组字面量。
     */
    private function exportDnfArray(array $dnf, string $indent): string
    {
        $groups = [];
        foreach ($dnf as $group) {
            if (is_array($group)) {
                $groups[] = '[' . implode(', ', array_map('intval', $group)) . ']';
            }
        }

        return "[\n" . $indent . '        ' . implode(",\n" . $indent . '        ',
                $groups) . ",\n" . $indent . '    ]';
    }

    /**
     * 导出标量值（null/string/int/bool）。
     */
    private function exportScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return var_export((string) $value, true);
    }
}
