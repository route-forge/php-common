# AGENTS.md — Working with `route-forge/php-common`

Guidance for AI coding agents working on **route-forge/common** — the framework-agnostic core of the Route Forge PHP backend family.

## What this package is

`route-forge/common` (namespace `RouteForge\Common\`) carries **all framework-independent business logic**: tier resolution, route aliases, the unified route repository, caching, TS type generation, summary embedding, config-file generation, and the exception contract. Framework adapters (Laravel today; ThinkPHP / Symfony planned) implement the contracts in `Contract\` and delegate here.

- Language: PHP `^8.2`
- Dependencies: `psr/log` only — **no framework packages, ever**
- Package manager: Composer (this repo)

## The one rule agents get wrong

> **Zero framework references in `src/`.** No `Illuminate\*`, no ThinkPHP, no Symfony classes — not even in type hints or instanceof checks. Framework specifics (route objects, cache stores, framework-internal route prefixes like Laravel's `storage.*`) enter **only** through the contracts:
>
> - `Contract\RouteNormalizerInterface` — framework route → `Dto\RouteInfo`
> - `Contract\CacheInterface` — framework cache → `get/put/forget` (`?int $seconds`, `null` = forever)
>
> If you find yourself reaching for a framework class, the correct move is a new field on `RouteInfo` or a new contract method — not an import.

## Structure

- `Contract\` — seams for framework adapters (`RouteNormalizerInterface`, `CacheInterface`, `ForgeExceptionContract`)
- `Dto\RouteInfo` — the unified route DTO consumed by all business logic (`source` carries the raw framework object back to framework-side callbacks)
- `Tier\TierResolver` — 5-priority tier resolution (explicit > classifier > config match > unassigned fallback; `middleware_match` any/all/DNF)
- `Alias\AliasResolver` — alias merge from macro declarations + config (collision dropped with warning, dangling target fail-fast `RF_BE_008`)
- `Repository\RouteRepository` — per-level metadata / summary / unassigned / full index, cached
- `Analyzer\RouteAnalyzer` — shared analysis pipeline for framework commands: `analyzeRoutes()` (raw routes + normalizer → rows), `filterRows()`, `listPayload()` (assembles the SPEC §3.2 `list --json` contract), `withoutHead()`
- `Type\TypeGenerator` — TS d.ts / JSON output; `collectTargets()` maps analyzer rows to type input (empty levels preserved, HEAD filtered, body-method detection)
- `Summary\SummaryRenderer` + `Support\JsSafeEncoder` — `__ROUTE_FORGE__` one-shot embed; encoder semantics must stay equivalent to safe `JSON.parse('…')` embedding (`</script>` cannot escape — covered by adapter-side injection tests, do not weaken)
- `Config\ConfigFileGenerator` — PHP source generation for the manager's config editor (escape dynamic values; never interpolate raw strings)
- `Cache\RouteCache` — per-level cache + keys index (`clear()` without wildcard support); **`SUMMARY_LEVEL` + `forgetLevel()`**: invalidating a level MUST invalidate the summary — framework `clear --level` implementations must call `forgetLevel()`, never bare `forget()`
- `Filter\RouteNameFilter` — name exclusion (`forge.routes.*` / `forge.manager.*` always; framework-internal prefixes injected via `withExtraPrefixes()`)

The front/back contract (endpoint shapes, `schemeVersion`, error codes) is documented in the **laravel adapter's `.docs/SPEC.md`** — this repo intentionally has no copy; do not create one.

## Editing conventions

- Tests: `composer test` (PHPUnit, framework-free unit tests). Full suite must pass before any commit. New features/fixes ship with matching tests — pure logic is unit-tested here with `RouteInfo` fixtures (see `TierResolverTest` / `TypeGeneratorTest` / `RouteAnalyzerPayloadTest`); end-to-end behavior is covered by the adapter repos' Feature tests.
- Command-layer contracts (`listPayload` shape, `collectTargets` mapping) mirror SPEC §3.2 — changing their output shape is a contract change and must be coordinated with the frontend SDK and SPEC.
- Commit messages: `type(scope): 中文描述` (type ∈ feat/fix/test/docs/refactor/chore). Do **not** push without explicit instruction.
- Behavior changes that alter any HTTP payload or command output must be synced to the adapter repos' `.docs/SPEC.md` in the same change set.
