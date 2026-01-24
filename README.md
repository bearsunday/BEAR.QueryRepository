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

## Documentation

- [BEAR.Sunday cache manual](http://bearsunday.github.io/manuals/1.0/ja/cache.html)
- [LLM Documentation](https://bearsunday.github.io/BEAR.QueryRepository/llms-full.txt)
