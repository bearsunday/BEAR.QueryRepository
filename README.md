# BEAR.QueryRepository

[![Continuous Integration](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/bearsunday/BEAR.QueryRepository/branch/1.x/graph/badge.svg?token=eh3c9AF4Mr)](https://codecov.io/gh/koriym/BEAR.QueryRepository)

**The repository of the query side, not a cache: writes keep it current, rather than letting it expire.**

A [CQRS](http://martinfowler.com/bliki/CQRS.html)-style read side for BEAR.Sunday: the result of each GET is kept as a read model, and the commands that change it refresh or purge it. A resource declares this with attributes and AOP does the rest - storing, answering conditional requests, and invalidating. By default an entry lives until a write invalidates it; a TTL is available for data that may simply go stale.

- **The resource graph is the dependency graph**: a cached child taken in with `#[Embed]` becomes a dependency of the parent.
- **Writes invalidate**: a command (`onPut`, `onPatch`, ...) invalidates what it changes with `#[Purge]`, `#[Refresh]` or `#[RefreshCache]`, rather than waiting for expiry.
- **One tag crosses every layer**: the origin cache, the ETag pool behind `304 Not Modified`, and the CDN's `Surrogate-Key` are invalidated by the same tags.
- **Three uses of a child**: a per-user hole (`#[DonutCache]`), a hole rebuilt on events (`#[CacheableResponse]`), and material the page reads.
- **Verifiable**: the semantic log records each store, hit, dependency and invalidation.

A cache attribute declares the response shared with every visitor: put per-user values in a non-cacheable `#[DonutCache]` hole.

<img src="https://user-images.githubusercontent.com/529021/137062427-c733c832-0631-4a43-a6ee-4204e6be007c.png" alt="Client, CDN and origin caches, refreshed and purged by writes" width="480">

## Documentation

- Cache manual: [English](https://bearsunday.github.io/manuals/1.0/en/cache.html) / [日本語](https://bearsunday.github.io/manuals/1.0/ja/cache.html)
- Reading the Log ([en](docs/reading-the-log.md) / [ja](docs/reading-the-log.ja.md)): every word the log can contain, and how to read a session
- Why the Log Records Everything ([en](docs/why-the-log-records-everything.md) / [ja](docs/why-the-log-records-everything.ja.md)): the design rationale, the cost measurements, and why recording is off by default
- What the Log Proves ([en](docs/what-the-log-proves.md) / [ja](docs/what-the-log-proves.ja.md)): the questions the log answers, and its declared boundaries
- [LLM documentation](https://bearsunday.github.io/BEAR.QueryRepository/llms-full.txt)
- Agent skills, via `/plugin install bear-skills`: [bear-cache-log](https://github.com/bearsunday/BEAR.Skills/blob/1.x/skills/bear-cache-log/SKILL.md) and [bear-cache-gate](https://github.com/bearsunday/BEAR.Skills/blob/1.x/skills/bear-cache-gate/SKILL.md)
