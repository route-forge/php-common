<?php

declare(strict_types=1);

namespace RouteForge\Common\Summary;

use RouteForge\Common\Support\JsSafeEncoder;

/**
 * 首页内嵌摘要渲染器：把「摘要端点」的返回值以一段 &lt;script&gt; 内嵌进
 * 服务端渲染的 HTML，供前端 @route-forge/core 在浏览器初始化时直接消费，
 * 跳过首屏的一次摘要 HTTP 往返。
 *
 * 框架无关：纯静态渲染，输入为 {@see \RouteForge\Common\Repository\RouteRepository::getSummary()}
 * 的摘要数组；各框架的模板指令（Laravel @forgeSummary / ThinkPHP / Twig 扩展）调用本类。
 *
 * 契约（对齐前端消费实现，勿单方面变更）：
 *   - 暴露的全局 key 固定为 {@see self::GLOBAL_KEY}。
 *   - 值 = 与 GET 摘要端点逐字段一致的 JSON（复用同一 producer）。
 *   - 定义方式：defineProperty 一次性 getter，读后即 delete、enumerable:false、
 *     configurable:true。语义 = 前端读一次拿到摘要、访问器自删、window 上不再残留。
 *
 * 红线（务必保持）：
 *   1. producer 复用：摘要只来自 RouteRepository::getSummary()，与摘要端点
 *      同一份内存结构，不另起扫描；缓存、dev 旁路、包自身路由排除等既有语义
 *      因此自动继承，杜绝前后端契约漂移。
 *   2. 只嵌摘要，绝不嵌层级路由表：受保护层级的路由明细不得预置进公开 HTML，
 *      各层级仍按 level 走 HTTP 懒加载。
 *   3. XSS 安全编码：内嵌 JSON 必须经 {@see JsSafeEncoder}，防 </script> 逃逸。
 *   4. 不递增 schemeVersion：这是既有摘要契约的"投递方式"扩展，非协议变更。
 *
 * 安全边界（如实说明，勿夸大为加密/安全机制）：一次性自删只缩小数据在 window 上的
 * 运行时驻留面；摘要数据仍随 HTML 源码可见，非抗 XSS / 抗网络窃取的硬边界。
 *
 * @see .docs/SPEC.md §3.1.6（摘要结构）/ §3.1.8（内嵌投递方式）
 */
final class SummaryRenderer
{
    /**
     * 前端约定消费的全局 key（勿改，与 @route-forge/core 消费实现对齐）。
     */
    public const GLOBAL_KEY = '__ROUTE_FORGE__';

    /**
     * 渲染可直接放进 HTML &lt;head&gt;（早于前端 bundle）的一段 &lt;script&gt;。
     *
     * 返回的是已安全编码的原始 HTML 字符串（内含 JSON.parse('...') 表达式，
     * 其中的 &lt; / &gt; 已被 JsSafeEncoder 转义），调用方应原样输出，勿再经模板转义。
     *
     * @param array<string, mixed> $summary {@see RouteRepository::getSummary()} 的返回值
     */
    public static function render(array $summary): string
    {
        $expression = JsSafeEncoder::encode($summary);

        $key = self::GLOBAL_KEY;

        return "<script>\n"
            . "Object.defineProperty(window, '{$key}', {\n"
            . "  configurable: true,\n"
            . "  enumerable: false,\n"
            . "  get: function () {\n"
            . "    var v = {$expression};\n"
            . "    delete window.{$key};\n"
            . "    return v;\n"
            . "  }\n"
            . "});\n"
            . "</script>";
    }
}
