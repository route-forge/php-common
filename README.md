# Route Forge — Common

**框架无关的 Route Forge 核心库。** 承载 tier 分层解析、路由别名、统一路由元信息仓库、TS 类型生成、摘要内嵌渲染与配置生成等全部业务逻辑，不依赖任何框架（Laravel / ThinkPHP / Symfony 均只通过适配层接入）。

## 定位

```
php-common ← 本仓库：业务逻辑 + 契约（框架无关，零框架依赖）
php-laravel ← Laravel 适配层（依赖本包）
route-forge-thinkphp ← ThinkPHP 适配层（依赖本包，规划中）
route-forge-symfony  ← Symfony Bundle 适配层（依赖本包，规划中）
```

一套前端 SDK（`@route-forge/core` / `@route-forge/vue` / `@route-forge/react`）对接多个 PHP 后端框架，HTTP 元信息端点、TS 类型产物、配置结构完全一致。

## 核心契约

| 契约 | 说明 |
|---|---|
| `Contract\RouteNormalizerInterface` | 框架路由 → `Dto\RouteInfo` 的转换器；各框架适配层实现 |
| `Contract\CacheInterface` | 缓存桥接；`get` / `put(key, value, ?seconds)` / `forget`，`null` 秒=永久 |
| `Contract\ForgeExceptionContract` | 全部业务异常统一契约（`code()` / `httpStatus()`） |
| `Dto\RouteInfo` | 统一路由信息（name / uri / methods / parameters / parameter_defaults / middleware / tier / forge_aliases / source） |

## 模块

- `Tier\TierResolver` — 五级优先级层级解析（显式标注 > classifier 回调 > 配置 match > unassigned 兜底；`middleware_match` 支持 any / all / DNF）
- `Alias\AliasResolver` — 路由别名合并（宏声明 + 配置表；撞车 / 悬空 fail-fast）
- `Repository\RouteRepository` — 层级元信息 / 摘要 / unassigned / 全量索引（带缓存）；`normalizeEndpointPrefix()` 为端点前缀唯一规范化实现
- `Analyzer\RouteAnalyzer` — 命令层共用的全量路由分析（含别名行与警告）；`analyzeRoutes()` 便捷入口、`filterRows()` / `listPayload()` 组装 `list --json` 契约（SPEC §3.2）
- `Type\TypeGenerator` — TS d.ts 与 JSON 类型产物生成；`collectTargets()` 负责 rows → 类型输入映射（空层级预置 / HEAD 过滤 / body 方法判定）
- `Summary\SummaryRenderer` — 首页内嵌摘要脚本渲染（`__ROUTE_FORGE__`）
- `Config\ConfigFileGenerator` — 管理器配置文件的 PHP 源码生成（防注入转义）
- `Cache\RouteCache` — 层级缓存 + keys 索引（`clear()` 不依赖通配符）；`SUMMARY_LEVEL` 常量与 `forgetLevel()` 封装「层级失效必同步失效摘要」不变量
- `Filter\RouteNameFilter` — 路由名排除（forge 自身端点 + 框架内部路由）

## 使用

普通用户**不应直接安装本包**，请按所用框架安装对应适配包：

```bash
# Laravel
composer require route-forge/laravel
# ThinkPHP / Symfony 适配包发布后同理
```

框架适配包通过 path / vcs repository 依赖本包（`route-forge/common:^1.0`），并实现 `RouteNormalizerInterface` 与 `CacheInterface` 完成接入。**请勿在本包中引入任何框架类**。

## 开发

```bash
composer install
composer test
```

## 许可证

MIT
