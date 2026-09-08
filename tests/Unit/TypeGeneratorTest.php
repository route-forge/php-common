<?php

declare(strict_types=1);

namespace RouteForge\Common\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\Common\Type\TypeGenerator;

/**
 * TypeGenerator 单元测试：聚焦 collectTargets 的收集语义
 * （生成字符串输出由框架侧 Feature 测试端到端覆盖）。
 */
class TypeGeneratorTest extends TestCase
{
    private TypeGenerator $gen;

    protected function setUp(): void
    {
        $this->gen = new TypeGenerator();
    }

    /**
     * 一行 analyzer row 的工厂。
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'name'               => 'admin.users.index',
            'level'              => 'admin',
            'tier'               => 'admin',
            'methods'            => ['GET', 'HEAD'],
            'uri'                => 'admin/users',
            'parameters'         => [],
            'parameter_defaults' => [],
            'alias_of'           => null,
        ], $overrides);
    }

    public function test_empty_target_levels_are_preserved(): void
    {
        $out = $this->gen->collectTargets([$this->row()], ['admin', 'client']);

        $this->assertSame(['admin', 'client'], array_keys($out));
        $this->assertSame([], $out['client']);
    }

    public function test_head_is_filtered_and_first_method_wins(): void
    {
        $out = $this->gen->collectTargets([
            $this->row(['methods' => ['GET', 'HEAD']]),
            $this->row(['name' => 'a', 'methods' => ['POST']]),
            $this->row(['name' => 'b', 'methods' => []]), // 空方法回退 GET
        ], ['admin']);

        $this->assertSame('GET', $out['admin']['admin.users.index']['method']);
        $this->assertSame('POST', $out['admin']['a']['method']);
        $this->assertSame('GET', $out['admin']['b']['method']);
    }

    public function test_has_body_only_for_body_methods(): void
    {
        $out = $this->gen->collectTargets([
            $this->row(['name' => 'get', 'methods' => ['GET']]),
            $this->row(['name' => 'del', 'methods' => ['DELETE']]),
            $this->row(['name' => 'post', 'methods' => ['POST']]),
            $this->row(['name' => 'put', 'methods' => ['PUT']]),
            $this->row(['name' => 'patch', 'methods' => ['PATCH']]),
        ], ['admin']);

        $this->assertFalse($out['admin']['get']['hasBody']);
        $this->assertFalse($out['admin']['del']['hasBody']);
        $this->assertTrue($out['admin']['post']['hasBody']);
        $this->assertTrue($out['admin']['put']['hasBody']);
        $this->assertTrue($out['admin']['patch']['hasBody']);
    }

    public function test_unassigned_and_out_of_target_rows_are_skipped(): void
    {
        $out = $this->gen->collectTargets([
            $this->row(['name' => 'loose', 'tier' => null, 'level' => 'unassigned']),
            $this->row(['name' => 'other', 'level' => 'public', 'tier' => 'public']),
        ], ['admin']);

        $this->assertArrayNotHasKey('loose', $out['admin']);
        $this->assertArrayNotHasKey('other', $out['admin']);
        $this->assertSame([], $out['admin']);
    }

    public function test_optional_params_extracted_from_uri_template(): void
    {
        // params 来自 analyzer row（真实场景 = Route::parameterNames()），
        // 可选参数来自 URI 模板的 {param?} 语法，两者独立
        $out = $this->gen->collectTargets([
            $this->row([
                'parameters' => ['user'],
                'uri'        => 'admin/users/{user}/posts/{post?}/comments/{comment?}',
            ]),
        ], ['admin']);

        $entry = $out['admin']['admin.users.index'];
        $this->assertSame(['user'], $entry['params']);
        $this->assertSame(['post', 'comment'], $entry['optionalParams']);
    }

    public function test_defaults_and_response_passthrough(): void
    {
        $out = $this->gen->collectTargets([
            $this->row(['parameter_defaults' => ['user' => 1]]),
        ], ['admin']);

        $entry = $out['admin']['admin.users.index'];
        $this->assertSame(['user' => 1], $entry['defaults']);
        $this->assertSame('unknown', $entry['response']);
    }

    public function test_alias_rows_follow_target_level(): void
    {
        $out = $this->gen->collectTargets([
            $this->row(),
            $this->row(['name' => 'admin.users.all', 'alias_of' => 'admin.users.index']),
        ], ['admin']);

        $this->assertArrayHasKey('admin.users.all', $out['admin']);
        $this->assertSame(
            $out['admin']['admin.users.index']['method'],
            $out['admin']['admin.users.all']['method'],
        );
    }
}
