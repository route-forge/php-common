<?php

declare(strict_types=1);

namespace RouteForge\Common\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\Common\Support\JsSafeEncoder;

/**
 * 内嵌 JS 安全编码器单元测试。
 *
 * 契约基线是 Illuminate\Support\Js::from(...)->toHtml()：
 * 本编码器必须与之逐字节对齐（含 JSON_UNESCAPED_UNICODE），
 * 因此这里同时锁定「安全转义」与「非 ASCII 不被转义」两侧行为。
 */
class JsSafeEncoderTest extends TestCase
{
    /**
     * 从 JSON.parse('...') 形态的表达式里还原原始数据。
     */
    private function decode(string $expression): mixed
    {
        $this->assertStringStartsWith("JSON.parse('", $expression);
        $this->assertStringEndsWith("')", $expression);

        $inner = substr($expression, 12, -2);
        // 外层是 JS 单引号字符串内容，借 JSON 的双引号字符串语义还原出第一层 JSON 文本
        $json = json_decode('"' . $inner . '"', true);
        $this->assertIsString($json, '外层未产出合法的 JS 字符串内容');

        return json_decode($json, true);
    }

    public function test_non_ascii_is_not_escaped(): void
    {
        $encoded = JsSafeEncoder::encode(['description' => '公开内容']);

        $this->assertStringContainsString('公开内容', $encoded);
        $this->assertStringNotContainsString('\\u516c', $encoded);
        $this->assertSame(['description' => '公开内容'], $this->decode($encoded));
    }

    public function test_structural_quotes_are_hex_escaped(): void
    {
        $encoded = JsSafeEncoder::encode(['k' => 'v']);

        // 结构引号必须转义，否则 </script> 之外的引号也会截断 JS 字符串
        $this->assertStringContainsString('\\u0022k\\u0022', $encoded);
        $this->assertSame(['k' => 'v'], $this->decode($encoded));
    }

    public function test_script_tag_cannot_escape_the_script_block(): void
    {
        $payload = ['description' => '</script><script>alert(1)</script>'];
        $encoded = JsSafeEncoder::encode($payload);

        $this->assertStringNotContainsString('</script>', $encoded);
        $this->assertStringNotContainsString('<script>', $encoded);
        $this->assertSame($payload, $this->decode($encoded));
    }

    public function test_ampersand_and_apostrophe_are_hex_escaped(): void
    {
        $payload = ['s' => "a & b ' c"];
        $encoded = JsSafeEncoder::encode($payload);

        $this->assertStringNotContainsString(" '", $encoded);
        $this->assertSame($payload, $this->decode($encoded));
    }

    public function test_nested_summary_shape_survives_round_trip(): void
    {
        $payload = [
            'schemeVersion' => 1,
            'levels'        => [
                'public' => ['description' => '公开内容', 'load' => 'eager', 'route_count' => 2],
                'admin'  => ['description' => '后台 & 管理', 'load' => 'lazy', 'route_count' => 0],
            ],
            'config'        => ['strict_mode' => false, 'endpoint_prefix' => '/_forge/routes'],
        ];

        $this->assertSame($payload, $this->decode(JsSafeEncoder::encode($payload)));
    }

    public function test_invalid_utf8_is_substituted_instead_of_throwing(): void
    {
        $encoded = JsSafeEncoder::encode(['s' => "bad\xb1"]);

        $this->assertStringStartsWith("JSON.parse('", $encoded);
    }

    public function test_unencodable_value_throws_json_exception(): void
    {
        // REQUIRED_FLAGS 含 JSON_THROW_ON_ERROR：不可编码的值必须显式抛错，不得静默产出坏载荷
        $this->expectException(\JsonException::class);
        JsSafeEncoder::encode([STDIN]);
    }
}
