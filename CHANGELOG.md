# Changelog

本文件记录 `route-forge/common` 的版本变更，格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

## [1.1.1] - 2026-09-10

### 修复

- `match.prefix` / `match.middleware` 传单值字符串（如 `'prefix' => 'admin'`）不再 `TypeError: count(): Argument #1 must be of type Countable|array, string given`——在 `TierResolver::matchConfig` 入口统一按 `(array)` 归一化，等价于只含该值的单元素数组；`middleware_match` 传入非 string/array 类型时回落 `'any'` 并记录 warning（此前同样直接崩溃）。空字符串、空数组、缺 `match` 键等空值边界仍不命中任何层级，语义与 SPEC 一致。

## [1.1.0] - 2026-09-09

### 修复

- `JsSafeEncoder` 补 `JSON_UNESCAPED_UNICODE`，与 `Illuminate\Js` 输出逐位对齐。
- 别名跟随 target 的每个解析层级铺开；同名跨层级重复注册给出警告。

## [1.0.0] - 2026-09-08

- 框架无关核心首版：层级解析、别名、统一路由仓库、缓存、TS 类型生成、摘要内嵌、配置文件生成与异常契约。
