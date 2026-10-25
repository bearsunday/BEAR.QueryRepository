# BEAR.QueryRepository

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/badges/quality-score.png?b=1.x)](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/?branch=1.x)
[![codecov](https://codecov.io/gh/bearsunday/BEAR.QueryRepository/branch/1.x/graph/badge.svg?token=eh3c9AF4Mr)](https://codecov.io/gh/koriym/BEAR.QueryRepository)
[![Type Coverage](https://shepherd.dev/github/bearsunday/BEAR.QueryRepository/coverage.svg)](https://shepherd.dev/github/bearsunday/BEAR.QueryRepository)
[![Coding Standards](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/coding-standards.yml)
[![Static Analysis](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/static-analysis.yml)
[![Continuous Integration](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/continuous-integration.yml)

**BEAR.QueryRepository** is a distributed caching framework for BEAR.Resource applications, inspired by [CQRS](http://martinfowler.com/bliki/CQRS.html). It segregates reads and writes into separate repositories to optimize performance and resource utilization.

## Key Features

- **Event-Driven Cache Invalidation**: Automatically invalidates cache when data changes, ensuring consistency.
- **Dependency Resolution**: Resolves dependencies between resources and updates related caches automatically.
- **Donut Caching**: Combines dynamic and static content for efficient partial caching.
- **CDN Integration**: Seamlessly integrates with modern CDNs (e.g., Fastly, Akamai) for shared cache management.
- **Conditional Requests with ETag Support**: Reduces network overhead by leveraging `ETag` and `304 Not Modified` responses.
- **Distributed Cache Support**: Works with server-side caches (e.g., Redis, APC), shared caches (e.g., CDNs), and client-side caches.
- **Unstorable-Response Guard**: A producer of a per-user value can mark the current response unstorable via `UncacheableMarkInterface::mark()`, so it is never written to the shared cache ([#222](https://github.com/bearsunday/BEAR.QueryRepository/issues/222)).
  - `mark()` is the only method an application's own resources need, and the only method the object it is handed has — enforced at runtime, not just declared by the type.
  - Every resource whose own output carries a per-user/request-specific value must call `mark()` itself, even when it got that value indirectly from a shared service or from a value another resource already marked. A mark covers only the marking resource and whatever embeds it, never an unrelated sibling that reads the same value.
  - The mark is per-request state (`UncacheableMarkScopeInterface`, `Scope::SINGLETON`). A long-running worker (Swoole, RoadRunner) must build each request's own Injector/object graph to get a fresh instance, since `$module->override()` has no effect on an already-built `Injector` — the same requirement `ServerContextInterface` already has. `UncacheableMarkInterface` itself needs nothing done for it — never bind it to a stateful instance of its own.
  - This guard only stops *new* writes, so deploy a new `CacheVersionModule` version alongside it to start this release from a fresh cache namespace. `CacheVersionModule` only namespaces pools built through this package's own `Storage*Module`s or a `#[CacheNamespace]`-aware adapter; an app binding its own cache adapter directly must clear those pools instead.

## Documentation

- [BEAR.Sunday cache manual](http://bearsunday.github.io/manuals/1.0/ja/cache.html)
- [LLM Documentation](https://bearsunday.github.io/BEAR.QueryRepository/llms-full.txt)
- [Why the QueryRepository Log Records Everything](docs/why-the-log-records-everything.md) — design rationale, the cost measurements, and why recording is off by default
- [Reading the Log](docs/reading-the-log.md) — every word the log can contain, and how to read a session
- [What the Cache Log Proves — and What It Does Not](docs/what-the-log-proves.md) — the questions the log answers, the mechanisms that keep the answers honest, and the declared boundaries
- Agent skills: [bear-cache-log](https://github.com/bearsunday/BEAR.Skills/blob/1.x/skills/bear-cache-log/SKILL.md) (install, read and verify one session against the published schemas) and [bear-cache-gate](https://github.com/bearsunday/BEAR.Skills/blob/1.x/skills/bear-cache-gate/SKILL.md) (install a persistent oracle that proves store/hit/invalidation/cost per flow) — both via `/plugin install bear-skills`. For broader single-request tracing beyond caching, see [bear-observe](https://github.com/bearsunday/BEAR.EventSourcing/blob/1.x/skills/bear-observe/SKILL.md).
