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
- **Runtime-Private Response Guard**: A `#[Cacheable]`/`#[CacheableResponse]`/`#[DonutCache]` resource whose own code sets `Cache-Control` to `private` or `no-store` at runtime is not written to the shared cache; its header is kept and CDN headers are removed. A value declared by `#[HttpCache]`/`#[NoHttpCache]` is still stored. The guard reads only the response's own header (see [Cache dependencies](#cache-dependencies)).
  - Deploy this version with a new `CacheVersionModule` version: entries written by earlier versions may hold per-user content, and a rolling deploy would otherwise show raw placeholders. An app that binds its own cache adapter directly must clear those pools instead.

## Cache dependencies

Declare a dependency with `#[Embed]` and never `unset()` or overwrite the slot - a cached `onGet()` must not fetch dependent data with `$resource->get()`. What a child becomes depends on how the page uses it:

A `#[Cacheable]`/`#[CacheableResponse]`/`#[DonutCache]` response is shared with every visitor, so a per-user value belongs in a non-cacheable `#[DonutCache]` hole, not in the cached response itself. QR inspects only the response's own header, so a per-user value that reaches the cached output any other way - a child's value read into the body in `onGet()`, a value received through `#[ResourceParam]`, or a value generated in the template (e.g. a Twig function) - is stored and served to every later visitor. In particular, do not receive a per-user value with `#[ResourceParam]` (e.g. `app://self/login#nickname`) in a cached resource - put it in a non-cacheable `#[DonutCache]` hole instead.

| Need | Use | Effect of a child purge |
|---|---|---|
| Per-user content (no shared cache) | `#[DonutCache]` hole (non-cacheable child) | that hole alone re-runs on every HIT |
| Shared content that changes on an event | `#[CacheableResponse]` hole (cacheable child, never read in `onGet()`) | the shell is recomposed from the stored template; the page's own `onGet()` does not re-run |
| A value the page's own `onGet()` reads | material (the child is tagged, whether or not it is also rendered as a hole) | the whole template is dropped; the next GET is a fresh miss |
| Data that is merely stale after a while | `#[Cacheable]` with a TTL | expires on its own, no purge needed |

A writer-side `#[Purge]`/`#[Refresh]` on the command that changes the data is the alternative to waiting on this chain.

## Documentation

- [BEAR.Sunday cache manual](http://bearsunday.github.io/manuals/1.0/ja/cache.html)
- [LLM Documentation](https://bearsunday.github.io/BEAR.QueryRepository/llms-full.txt)
- [Why the QueryRepository Log Records Everything](docs/why-the-log-records-everything.md) — design rationale, the cost measurements, and why recording is off by default
- [Reading the Log](docs/reading-the-log.md) — every word the log can contain, and how to read a session
- [What the Cache Log Proves — and What It Does Not](docs/what-the-log-proves.md) — the questions the log answers, the mechanisms that keep the answers honest, and the declared boundaries
- Agent skills: [bear-cache-log](https://github.com/bearsunday/BEAR.Skills/blob/1.x/skills/bear-cache-log/SKILL.md) (install, read and verify one session against the published schemas) and [bear-cache-gate](https://github.com/bearsunday/BEAR.Skills/blob/1.x/skills/bear-cache-gate/SKILL.md) (install a persistent oracle that proves store/hit/invalidation/cost per flow) — both via `/plugin install bear-skills`. For broader single-request tracing beyond caching, see [bear-observe](https://github.com/bearsunday/BEAR.EventSourcing/blob/1.x/skills/bear-observe/SKILL.md).
