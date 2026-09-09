<?php

declare(strict_types=1);

namespace RouteForge\Common\Support;

/**
 * 内嵌 JS 的安全 JSON 编码器。
 *
 * 语义等价 Illuminate\Support\Js::from($data)->toHtml()（原 @forgeSummary Blade
 * 指令依赖该转义），框架无关重实现：
 *
 *   - 第一层：数据 → JSON 文本，flags 带 JSON_HEX_TAG | JSON_HEX_APOS |
 *     JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE，使字符串内容中的
 *     < > ' " & 全部转为 \uXXXX（</script> 无法截断脚本块），而非 ASCII 文本保持原样；
 *   - 第二层：把整个 JSON 文本作为字符串再做一次 json_encode（同样带
 *     JSON_HEX_*），结构引号 " → \u0022、JSON 文本中的反斜杠 \ → \\，
 *     再剥掉外层双引号，产出 JSON.parse('...') 形态的安全 JS 表达式。
 *
 * 双层转义是必须的：只做第一层时，JSON 的结构引号会破坏单引号 JS 字符串
 * （实测 htmlspecialchars 会把结构引号变成 &quot;，使载荷不再是合法 JSON）。
 *
 * 红线：内嵌 JSON 必须经本编码器，禁止裸 json_encode 直拼（XSS 逃逸风险）。
 */
final class JsSafeEncoder
{
    /**
     * 与 Illuminate\Support\Js::REQUIRED_FLAGS 逐位对齐：JSON_UNESCAPED_UNICODE
     * 不可省略——少了它，非 ASCII 文本（如 levels.*.description 的中文）会被转成
     * \uXXXX，JS 解析结果虽等价，但内嵌进 <head> 的载荷体积按字符数成倍膨胀。
     */
    private const REQUIRED_FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * 编码任意可 JSON 化的数据为安全 JS 表达式（JSON.parse('...') 形态）。
     *
     * @throws \JsonException 数据不可 JSON 化（非法 UTF-8 等）时抛出
     */
    public static function encode(mixed $data, int $depth = 512): string
    {
        // 第一层：数据 → JSON 文本。JSON_INVALID_UTF8_SUBSTITUTE 与 Laravel 对齐：
        // 非法 UTF-8 用 � 替换而非抛错。
        $json = json_encode(
            $data,
            self::REQUIRED_FLAGS | JSON_INVALID_UTF8_SUBSTITUTE,
            $depth,
        );

        if ($json === false) {
            throw new \InvalidArgumentException('Failed to encode data to JSON for inline script.');
        }

        // 第二层：JSON 文本 → JS 单引号字符串内容（结构引号与反斜杠全部转义）
        $quoted = json_encode($json, self::REQUIRED_FLAGS);

        return "JSON.parse('" . substr($quoted, 1, -1) . "')";
    }
}
