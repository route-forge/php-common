<?php

declare(strict_types=1);

namespace RouteForge\Common\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\Common\Config\ConfigFileGenerator;

/**
 * ConfigFileGenerator 类型归一化单元测试。
 *
 * 与 TierResolver::matchConfig 的归一化口径对齐：match.prefix / match.middleware 与
 * level 级 endpoint_middleware 除数组外也接受单个字符串，落盘被规范化为单元素数组字面量，
 * 不再在 exportInlineArray 的 array 类型声明上崩溃。
 */
class ConfigFileGeneratorTest extends TestCase
{
    private ConfigFileGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ConfigFileGenerator();
    }

    /**
     * 生成一份仅含单个层级的配置源码，便于逐场景对比。
     *
     * @param array<string, mixed> $levelConfig
     */
    private function generateForLevel(array $levelConfig): string
    {
        return $this->generator->generate(['admin' => $levelConfig], [], []);
    }

    // ---------------------------------------------------------------------
    // match.prefix：单值字符串 ≡ 单元素数组
    // ---------------------------------------------------------------------

    public function test_string_prefix_generates_identical_source_as_single_element_array(): void
    {
        $stringForm = $this->generateForLevel(['match' => ['prefix' => 'admin']]);
        $arrayForm  = $this->generateForLevel(['match' => ['prefix' => ['admin']]]);

        $this->assertSame($arrayForm, $stringForm, '单值 prefix 与数组 prefix 生成的配置源码必须逐字一致');
        $this->assertStringContainsString("'prefix' => ['admin']", $stringForm);
    }

    public function test_string_prefix_does_not_throw(): void
    {
        // 修复前：exportInlineArray('admin') 因 array 类型声明抛 TypeError
        $this->assertStringContainsString(
            "'prefix' => ['api']",
            $this->generateForLevel(['match' => ['prefix' => 'api']]),
        );
    }

    // ---------------------------------------------------------------------
    // match.middleware：单值字符串 ≡ 单元素数组
    // ---------------------------------------------------------------------

    public function test_string_middleware_generates_identical_source_as_single_element_array(): void
    {
        $stringForm = $this->generateForLevel(['match' => ['middleware' => 'auth']]);
        $arrayForm  = $this->generateForLevel(['match' => ['middleware' => ['auth']]]);

        $this->assertSame($arrayForm, $stringForm);
        $this->assertStringContainsString("'middleware' => ['auth']", $stringForm);
    }

    public function test_string_prefix_and_middleware_together_do_not_throw(): void
    {
        $source = $this->generateForLevel([
            'match' => ['prefix' => 'admin', 'middleware' => 'auth'],
        ]);

        $this->assertStringContainsString("'prefix' => ['admin']", $source);
        $this->assertStringContainsString("'middleware' => ['auth']", $source);
    }

    // ---------------------------------------------------------------------
    // level.endpoint_middleware：单值字符串 ≡ 单元素数组
    // ---------------------------------------------------------------------

    public function test_string_level_endpoint_middleware_generates_array_form(): void
    {
        $stringForm = $this->generateForLevel([
            'match' => ['prefix' => ['admin']],
            'endpoint_middleware' => 'role:admin',
        ]);
        $arrayForm = $this->generateForLevel([
            'match' => ['prefix' => ['admin']],
            'endpoint_middleware' => ['role:admin'],
        ]);

        $this->assertSame($arrayForm, $stringForm);
        $this->assertStringContainsString("'endpoint_middleware' => ['role:admin']", $stringForm);
    }

    // ---------------------------------------------------------------------
    // 空值边界：与 TierResolver「空不命中」保持可落盘、不崩溃
    // ---------------------------------------------------------------------

    public function test_empty_prefix_scalar_and_missing_keys_round_trip_as_empty_array(): void
    {
        // 缺 match 键 / 空数组都应稳定输出 'prefix' => []
        $missing = $this->generateForLevel([]);
        $empty   = $this->generateForLevel(['match' => ['prefix' => [], 'middleware' => []]]);

        $this->assertStringContainsString("'prefix' => []", $missing);
        $this->assertStringContainsString("'middleware' => []", $missing);
        $this->assertSame($empty, $missing);
    }
}
