<?php

declare(strict_types=1);

namespace RouteForge\Common\Tier;

use Closure;
use Psr\Log\LoggerInterface;
use RouteForge\Common\Dto\RouteInfo;
use RouteForge\Common\Exception\ClassifierException;
use RouteForge\Common\Exception\RouteMissingNameException;
use RouteForge\Common\Exception\RouteTierNotAssignedException;
use RouteForge\Common\Exception\UnknownClassifierTierException;
use RouteForge\Common\Exception\UnknownLevelException;
use Throwable;

/**
 * 层级分配器：根据 SPEC §3.1.4 的优先级规则，决定一条路由最终归属的层级。
 *
 * 优先级（高 → 低）：
 *   1. 显式 tier 标注（RouteInfo::tier，来自框架适配层提取的 action['tier']，
 *      对应 Laravel 的 ->tier() 调用与 group tier 透传）
 *   2. classifier 自定义回调返回非 null 值
 *   3. 配置 match 规则匹配（prefix / middleware，middleware_match 支持 any/all/DNF；
 *      多层级命中取最后一个 = last-wins）
 *   4. 未命中：strict_mode=false 时归入 unassigned 特殊层级（resolve 返回 null）；
 *      strict_mode=true 时抛 RouteTierNotAssignedException
 *
 * 框架无关：本类只消费 RouteInfo DTO。classifier 回调的参数类型为 RouteInfo，
 * 框架适配层负责把用户按各自框架类型书写的回调包装为 fn(RouteInfo)（见各适配包）。
 */
readonly class TierResolver
{
    /**
     * @param array<string, mixed> $levelsConfig
     * @param Closure(RouteInfo): ?string|null $classifier
     */
    public function __construct(
        private array            $levelsConfig,
        private ?Closure         $classifier = null,
        private bool             $strictMode = false,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * 解析一条路由的最终层级。
     *
     * @throws RouteMissingNameException      当 strict_mode=true 且路由设置了 tier 但没有路由名
     * @throws RouteTierNotAssignedException  当 strict_mode=true 且未命中任何层级
     * @throws ClassifierException            当 classifier 回调抛错
     * @throws UnknownClassifierTierException 当 classifier 返回的层级名不在 levels 配置中
     * @throws UnknownLevelException          当显式 tier 值不在 levels 配置中
     */
    public function resolve(RouteInfo $route): ?string
    {
        // 前置检查：有 tier 无 name 在严格模式下是配置错误
        $explicit = $route->tier;
        if ($explicit !== null && $explicit !== '' && $route->name === null) {
            if ($this->strictMode) {
                throw new RouteMissingNameException(
                    'Route (' . $route->uri . ') has tier [' . $explicit . '] but no route name assigned. '
                    . 'Route Forge requires a route name when tier is set. '
                    . 'Add ->name(...) to the route or remove the tier.'
                );
            }
            // 非严格模式：有 tier 无 name 的路由无法被纳入元信息。
            // 记录 warning 提示配置错误——否则该路由在层级端点、unassigned、
            // route:forge:list / types 中都不出现，排查极其困难（RF_BE_005 仅严格模式抛出）。
            // logger 为 null（纯单元测试/未注入）时静默，不影响解析结果。
            $this->logger?->warning(
                'Route (' . $route->uri . ') has tier [' . $explicit . '] but no route name assigned; '
                . 'it will not appear in any forge endpoint or command output. '
                . 'Add ->name(...) to the route or remove the tier.'
            );

            return null;
        }

        // 1：显式 tier 标注（含 Laravel 的 group tier 透传，均写入 action['tier']）
        if ($explicit !== null && $explicit !== '') {
            // 显式 tier 值必须在 levels 配置中存在
            if (!isset($this->levelsConfig[$explicit])) {
                throw new UnknownLevelException(
                    'Route ' . ($route->name ?? '(' . $route->uri . ')')
                    . ' has tier [' . $explicit . '] which is not defined in levels config. '
                    . 'Available levels: ' . implode(', ', array_keys($this->levelsConfig)),
                );
            }

            return $explicit;
        }

        // 2：classifier 回调
        if ($this->classifier !== null) {
            try {
                $result = call_user_func($this->classifier, $route);
                if (is_string($result) && $result !== '') {
                    // 验证 classifier 返回的层级名必须在 levels 配置中
                    if (!isset($this->levelsConfig[$result])) {
                        throw new UnknownClassifierTierException(
                            'Classifier returned unknown tier [' . $result . '] for route '
                            . ($route->name ?? '(' . $route->uri . ')')
                            . '. Available tiers: ' . implode(', ', array_keys($this->levelsConfig))
                        );
                    }

                    return $result;
                }
            } catch (UnknownClassifierTierException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new ClassifierException(
                    'Classifier callback threw: ' . $e->getMessage(),
                    previous: $e
                );
            }
        }

        // 3：配置 match 规则（last-wins：多个层级同时命中时取最后一个，SPEC §3.1.2）
        $matched = null;
        foreach ($this->levelsConfig as $level => $config) {
            if ($this->matchConfig($route, $config['match'] ?? [])) {
                $matched = $level;
            }
        }
        if ($matched !== null) {
            return $matched;
        }

        // 4：未命中——strict_mode=true 抛异常；否则归入 unassigned 特殊层级（返回 null）
        if ($this->strictMode) {
            throw new RouteTierNotAssignedException(
                'Route ' . $route->name . ' (' . $route->uri . ') has no tier assigned. '
                . 'Add ->tier(...) to the route or a match rule in config/forge.php, or disable strict_mode.'
            );
        }

        return null;
    }

    /**
     * 配置 match 规则匹配：
     *   - prefix: 路由 URI 命中任一前缀即归入此层级
     *   - middleware: 路由中间件集合按 middleware_match 模式匹配（SPEC §3.1.2 中间件匹配模式）
     *
     * prefix 与 middleware 同时配置时，任一命中即归入此层级（OR 关系，与原有行为一致）。
     * 若两者都为空配置，则不命中（避免空 match 全量命中所有路由）。
     *
     * 类型归一化（SPEC §3.1.2）：prefix / middleware 除数组外也接受单个字符串（或标量），
     * 等价于只含该值的单元素数组；null / 缺键等价于空数组。归一化不改变任何匹配语义：
     * 空字符串仍按「空前缀跳过」处理，不会命中全部路由。
     * middleware_match 仅接受 'any' | 'all' | DNF 数组；其他类型回落 'any' 并记 warning
     * （未知字符串的静默降级为 any 是既有声明行为，见 matchMiddleware）。
     *
     * @param array<string, mixed> $match
     */
    private function matchConfig(RouteInfo $route, array $match): bool
    {
        $prefixes = (array) ($match['prefix'] ?? []);
        $middlewares = (array) ($match['middleware'] ?? []);
        $middlewareMatch = $this->normalizeMiddlewareMatch($route, $match['middleware_match'] ?? 'any');

        // prefix: URI 命中任一前缀即命中此层级
        $prefixHit = false;
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && (str_starts_with($route->uri, $prefix . '/') || $route->uri === $prefix)) {
                $prefixHit = true;
                break;
            }
        }

        // middleware: 按 middleware_match 模式匹配
        $middlewareHit = false;
        if (count($middlewares) > 0) {
            $middlewareHit = $this->matchMiddleware($route->middleware, $middlewares, $middlewareMatch);
        }

        if (count($prefixes) === 0 && count($middlewares) === 0) {
            return false;
        }

        return $prefixHit || $middlewareHit;
    }

    /**
     * middleware_match 类型守卫：string（'any'|'all'，含未知字符串的既有降级路径）与
     * array（DNF）之外的一切类型（int / bool / object 等）回落 'any' 并记录 warning。
     */
    private function normalizeMiddlewareMatch(RouteInfo $route, mixed $middlewareMatch): array|string
    {
        if (is_string($middlewareMatch) || is_array($middlewareMatch)) {
            return $middlewareMatch;
        }

        $this->logger?->warning(
            'Route (' . $route->uri . ') matched a level whose middleware_match rule has invalid type ['
            . (get_debug_type($middlewareMatch)) . ']; expected string ("any"/"all") or DNF array. '
            . 'Falling back to "any".'
        );

        return 'any';
    }

    /**
     * 中间件匹配模式实现（SPEC §3.1.2 中间件匹配模式）。
     *
     * @param string[]          $routeMiddlewares 路由实际中间件集合
     * @param string[]          $middlewares      配置的中间件列表（索引参与 DNF 求值）
     * @param array|string      $middlewareMatch  'any' | 'all' | DNF 嵌套数组
     */
    private function matchMiddleware(array $routeMiddlewares, array $middlewares, array|string $middlewareMatch): bool
    {
        // 简单字符串模式
        if ($middlewareMatch === 'any') {
            foreach ($middlewares as $mw) {
                if (in_array($mw, $routeMiddlewares, true)) {
                    return true;
                }
            }

            return false;
        }

        if ($middlewareMatch === 'all') {
            foreach ($middlewares as $mw) {
                if (!in_array($mw, $routeMiddlewares, true)) {
                    return false;
                }
            }

            return count($middlewares) > 0;
        }

        // DNF 数组模式：内层 AND，外层 OR
        // 配置示例 [[0, 1], [2]] => (middlewares[0] AND middlewares[1]) OR middlewares[2]
        if (is_array($middlewareMatch)) {
            foreach ($middlewareMatch as $conjGroup) {
                if (!is_array($conjGroup) || count($conjGroup) === 0) {
                    continue;
                }
                $allPresent = true;
                foreach ($conjGroup as $idx) {
                    $idx = (int) $idx;
                    if (!isset($middlewares[$idx])) {
                        $allPresent = false;
                        break;
                    }
                    if (!in_array($middlewares[$idx], $routeMiddlewares, true)) {
                        $allPresent = false;
                        break;
                    }
                }
                if ($allPresent) {
                    return true;
                }
            }

            return false;
        }

        // 未知值降级为 any
        return $this->matchMiddleware($routeMiddlewares, $middlewares, 'any');
    }
}
