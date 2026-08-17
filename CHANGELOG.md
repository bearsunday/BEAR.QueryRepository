# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Cache observability is now built on [Koriym.SemanticLogger](https://github.com/koriym/Koriym.SemanticLogger): an open/event/close tree whose nesting **is** the embed/dependency structure (a parent's embedded children nest under it). Typed `AbstractContext` subclasses live in `src/Log/Context/` with per-context JSON Schemas in `docs/schemas/context/`.
- `SafeSemanticLogger` tracks open-scope depth and keeps a live session from crossing a serialized injector; never breaking the host is the core logger's own guarantee since koriym/semantic-logger 0.9. `SafeSemanticLoggerProvider` binds `SafeSemanticLogger(new SemanticLogger())` in whichever log module the app installs; without one, `QueryRepositoryModule` binds the core `Koriym\SemanticLogger\NullSemanticLogger`.
- `invalidate` context records per-target outcomes as self-describing status words: `roPool`/`etagPool` (`invalidated`|`failed`), `cdn` (`purged`|`failed`), plus `durationMs`. A CDN purge failure is fail-closed: the local pools are invalidated first and the outcome is logged as `cdn: failed`, then the purge exception propagates so a write does not silently leave stale CDN content.
- `cdn_headers` context (`docs/schemas/context/cdn_headers.json`): every donut write and refresh records the CDN-facing headers the response ended up with, read back after they are final — the literal lifetime header (`CDN-Cache-Control` / `Surrogate-Control` / `Akamai-Cache-Control`, including the bound setter's default when no `sMaxAge` was requested) and the purge-key list (`Surrogate-Key`, or `Edge-Cache-Tag` under Akamai). No lifetime header recorded means the response gave the CDN no lifetime directive, which is how an un-cacheable donut page (`putDonut`) is meant to stay off the CDN; the CDN's own behavior is outside the log.
- `conditional_request` context (`docs/schemas/context/conditional_request.json`): the transfer-boundary `HttpCache`/`CliHttpCache` record a presented `If-None-Match` validator as a scope closing with the ETag pool's answer (`cache_hit`/`cache_miss` at the new layer `etag`). A hit is the 304 decision — the entire request served without running the resource — which no `get` scope can show.
- `docs/what-the-log-proves.md`: the questions the log answers, the mechanisms that keep the answers honest, and the declared boundaries — linked from both READMEs.
- Logs validate against their schemas in tests via `SemanticLogValidator` (`SemanticLogTreeTrait`) with `failOnDiagnostics` on, so a logging-protocol regression fails the suite even though the logger no longer throws; `vendor/bin/stree` renders the cache log as a readable tree (`demo/run-dependency.php`, `demo/run-donut.php`).
- Direct (non-AOP) top-level `put()`, `putStatic()`, `putDonut()`, `purge()` and `invalidateTags()` calls are rooted in `manual_store` / `manual_purge` / `manual_invalidate` scopes, so an application-initiated write, bust or invalidation reads differently from a framework-driven one and its outcome is recorded on the scope close (`manual_store_result` / `manual_purge_result` / `manual_invalidate_result`), with the `invalidate` detail staying a nested event so tag correlation also finds manual invalidations; a donut write's own pre-write cleanup invalidation nests inside its `manual_store` instead of rooting a scope of its own.
- `TopLevelAwareInterface` (`BEAR\QueryRepository\Log`): the "would the next open be top-level" capability that grants manual-scope rooting. Call sites check this interface instead of the concrete `SafeSemanticLogger`, so any custom `SemanticLoggerInterface` decorator can opt in to `manual_store` / `manual_purge` / `manual_invalidate` rooting.
- `pre_write_cleanup` context (`docs/schemas/context/pre_write_cleanup.json`): writer-side marker emitted right before a writer clears the entry it is about to rewrite, so cleanup-vs-invalidation is a recorded fact, not a reader inference.
- `docs/why-the-log-records-everything.md`: design rationale for the log — the silent-failure class it eliminates, the four principles (no silent paths, source-recorded facts, unknown ≠ absent, log-as-contract), its human/test/machine consumers, the measured cost and why recording is off by default. Linked from the README.
- `cache_error` context: emitted from the read/write interceptors when the cache path throws — the pool itself (cache server down) or the work the store performs (rendering a view, purging a CDN) — so a failed cache operation is distinguishable from a genuine cold-cache miss in the log.
- `saved` outcome field on the save contexts (`save_value` / `save_view` / `save_donut` / `save_donut_view` / `save_etag`): the cache pool's accept/reject result, so a silently failed store no longer looks like a successful save.
- `tags` (invalidation tags) on all five save contexts, so a save can be correlated with the `invalidate` events that later bust it.
- `put_skipped` context: emitted when a miss is not followed by a put, so a miss without save events reads as a recorded skip, not a lost write. `reason` is `etag-present`, `error-code` (with the actual response `code`, also emitted by `CacheInterceptor` on a non-200 GET), or `not-cacheable` (a donut page re-rendered from its template is never stored as a rendered page).
- `source` field on the `command` context naming the producing interceptor (`CommandInterceptor` / `DonutCommandInterceptor` / `RefreshInterceptor`).
- `operation` (`read` / `write`) and `exceptionClass` fields on `cache_error`, so the failing side and the throwable that failed it are both recorded — a pool outage no longer reads the same as a template that failed to render.
- `cdn` on `invalidate` is now tri-state: `purged` (a configured purger ran), `failed` (it threw), `skipped` (the bound purger is `NullPurger`, i.e. no CDN configured) — previously a no-op NullPurger was indistinguishable from a real purge (`purged`).
- `ttl` field on `save_etag`, completing the save contexts; all `ttl` descriptions state the convention that 31536000 is the `never` expiry placeholder and event-driven invalidation is the intended eviction path.
- Logging-protocol misuse (LIFO violations, sessions left unclosed at flush) surfaces as core `semantic_logger_error` diagnostics from koriym/semantic-logger 0.9, recorded in-band at the exact failure point with the tree preserved — "no records" is never misread as "no cache activity".
- Negative TTL clamping: a past `expiryAt` or a negative `expirySecond`/ttl argument is clamped to 0 at the `QueryRepository`/`ResourceStorage` boundary, matching the `"minimum": 0` the schemas declare.
- The demos verify themselves: every script prints the semantic log (tree + pretty JSON) and validates the flushed session offline against `docs/schemas/context`, printing a one-line verdict and exiting non-zero on any violation. `demo/run-dependency.php` scenario 3 is command-driven — a PUT on `LevelThree` opens a `command` scope whose purge cascades to level-two/level-one — alongside the manual `manual_purge` entry kind in scenario 6. `demo/run.php` binds real in-memory pools so its log shows genuine cache hits (the `QueryRepositoryModule` default `NullAdapter` made every GET miss).
- `demo/run-degraded.php`: the failure vocabulary the other demos never reach, in thirteen sessions — `put_skipped` (all three reasons), a 4xx `command_result` with no invalidation, `save_view`, `manual_store`/`manual_invalidate`, `cache_error` (`read` and `write`), `invalidate` with `cdn` `purged`/`failed` and `roPool`/`etagPool` `failed`, `saved: false` on every save context, `manual_store_result{failed}`, all three `command` producers, both built-in command annotations (`#[Refresh]` and the purge-only `#[Purge]` shape), the Akamai flavor's renamed CDN headers and a finite `ttl`. With it the demos emit every context type, every schema enum value and every command source the package can produce.
- `DemoLogCoverageTest`: runs the four demo scripts and fails unless their sessions still emit every context class, every schema `enum` value, both `saved` outcomes on all five save contexts, all three `command.source` producers and both built-in command annotations (`command.annotations[].class` is a free string the enum guard cannot see) — so the demos cannot quietly stop demonstrating part of the log.
- `DevQueryRepositoryLogModule`: turns recording on and writes each session whole, one file per request plus a fixed `latest.json` that `vendor/bin/stree` reads directly. Like `DevEtagModule`, it wraps the module it configures (`module: new QueryRepositoryModule()`), because an `install()` cannot replace a binding the installer already holds.
- `ProdQueryRepositoryLogModule`: buffers each session and decides at flush, writing one JSON line per kept session to `php://stdout` or a file. `RetentionPolicyInterface`/`KeepMutationsAndFailures` keeps what nothing else can account for rather than everything that looks like a failure: mutations (a `command` scope, a `manual_*` call, or an `invalidate` that is not marker-preceded cleanup), effects that were intended and did not happen (`saved: false`, `invalidate` with `roPool`/`etagPool`/`cdn` `failed`, a `manual_*` result of `failed`, `cache_error{operation: write}`, and `semantic_logger_error` because the records may then be incomplete), and an optional sample. Healthy reads are dropped, and so are `cache_error{operation: read}` - availability, which the pool's own monitoring sees first and which repeats on every request for the length of the incident it would otherwise flood the collector with - and every `put_skipped`, which records a store never attempted because a rule forbade it (a response code the path will not cache - `#[Cacheable]` skips any non-200, a donut skips 4xx and above - a validator already set, or a page served from its template): a decision, not a runtime failure. An app that wants full recording implements the interface and binds it after installing the module - the writer resolves the policy through DI.
- `LogSinkInterface`/`ShutdownFlush` and `LogWriterInterface`/`LogFileWriter`/`LogStreamWriter`: the log module owns flushing, so no app writes `flush()` in a bootstrap. The sink arms once per process - at construction and again at `SafeSemanticLogger::__unserialize()`, the only hook that runs on every request - and fires after output, after a 304's early `exit()` and after an uncaught error, which a hook on `ResourceObject::transfer()` cannot do (rendering happens inside it, and a 304 never reaches it). The flush is total: a destination that throws is reported to `error_log()` and swallowed, because from a shutdown callback it would otherwise surface as an uncaught fatal after the response was already written. An empty session is never written; `LogFileWriter` creates 0700/0600, names sessions in UTC with a microsecond field so pruning keeps the newest, renames `latest.json` into place so a reader never opens a half-written file, and reports a destination it cannot write instead of emitting warnings from a shutdown handler.
- Japanese versions of the three human-facing documents, beside their English originals under the convention the repository already uses for `README.ja.md`: `docs/reading-the-log.ja.md`, `docs/why-the-log-records-everything.ja.md`, `docs/what-the-log-proves.ja.md`. The published paths stay where they are - `docs/schemas/context/*.json` is embedded in every entry's `schemaUrl` and `docs/llms*.txt` is served at a well-known path, so neither moves for a language split. `ReadingGuideCoverageTest` now runs against both guides, so a new context type or enum value fails until it is explained in each.
- `docs/reading-the-log.md`: the reader's guide to the log - the tree grammar (open / event / close), every one of the 26 context types with its fields, every outcome word with what it means, the reading rules that cannot be guessed from a field name (marker-preceded cleanup, degraded vs cold, the 304 shape, tag intersection), a Terminology table that expands the names the log uses for its own parts (`roPool` is the pool bound with `#[ResourceObjectPool]`, `tags` are surrogate keys, `layer` is which store answered rather than which pool was written) and points at the manual for the concepts, and a worked session taken verbatim from a demo. `ReadingGuideCoverageTest` fails if a context type or a schema enum value is not accounted for there, so a published word cannot ship without an explanation. Linked from both READMEs; `docs/llms.txt`/`llms-full.txt` keep the same facts in lookup form and point at it.
- A `#[Cacheable]` value entry is stored without rendering the resource: the render was work the write did not need, and a resource with no renderer in that context (an app resource under an html renderer) failed on every store - degraded to a warning, so responses stayed correct while the cache stayed empty. The validator falls back from the view to the body, so ETag values change once for value entries, and a stored value entry no longer carries the renderer's `Content-Type`.
- `Exception\UnsupportedLogStream`: `LogStreamWriter` accepts a filesystem path or `php://stdout|stderr|output` and rejects any other wrapper, so a module argument cannot truncate an unrelated file through `php://filter/…` or ship every session to an `ftp://` host. Writes now take an exclusive lock (`flock`) instead of relying on `file_put_contents` flags, which the default `php://stdout` target silently dropped - concurrent workers could interleave half-lines in the JSONL a collector parses.
- `PsrLogWriter`: sends a kept session to the application's PSR-3 logger (already in every BEAR app's tree via bear/sunday and bear/resource), passing the tree as structured context under `log` rather than as a message. It is an adapter behind `LogWriterInterface`, not a replacement for it: PSR-3 carries strings, and its level would be a second filter that can drop what the retention policy kept.
- `ConcurrentRuntimeInterface`/`HostRuntime`: where the sink can prove the host is concurrent (`RR_MODE` set, or inside a Swoole coroutine) it refuses to arm, reports through `error_log()` and **recording stops with it** - `SafeSemanticLogger` falls back to the no-op logger, since an unarmed sink leaves nothing that would ever drain the session. Mode is consulted, never capability (a loaded ext-swoole proves nothing about how the app is served), and the two checks are explicitly not an exhaustive account: a Swoole worker whose logger is built outside a coroutine, FrankenPHP worker mode, ReactPHP, Amp and a long-lived CLI consumer are not detected and must bind their own implementation. Diagnostics use `error_log()` rather than `trigger_error()` because arming happens while the injector builds the logger, where a strict error handler would turn a warning into a boot failure on exactly those hosts.

### Deprecated
- `RepositoryLogger`, `RepositoryLoggerInterface`, `StructuredRepositoryLoggerInterface` and `NullRepositoryLogger`. Internal cache code now logs through `Koriym\SemanticLogger\SemanticLoggerInterface`; the legacy flat interface remains bound for BC but receives no internal events.

### Removed
- `docs/schemas/repository-log.json` (the flat op-string log format it described is gone; per-context schemas in `docs/schemas/context/` replace it).
- `BEAR\QueryRepository\Log\NullSemanticLogger`: koriym/semantic-logger 0.9 ships its own no-op logger whose `open()` returns a usable id instead of the empty-string sentinel this one returned.

### Changed
- Cache logging call sites (`QueryRepository`, `ResourceStorage`, `DonutRepository`, `CacheInterceptor`, `AbstractDonutCacheInterceptor`, `CommandInterceptor`, `RefreshInterceptor`) now emit typed contexts through `SemanticLoggerInterface` instead of `RepositoryLoggerInterface::log()`.
- `SaveDonutContext`/`SaveDonutViewContext`: the misleading `sMaxAge` field is renamed to `ttl` — the value is the cache entry TTL, never a CDN s-maxage.
- `SaveEtagContext`/`SaveDonutViewContext`: `surrogateKeys` renamed to `tags`; all save contexts now consistently report invalidation tags under `tags`.
- Command scopes are opened even for failed writes: a 4xx response closes with `command_result` (code 4xx) and no invalidation events, recording that the purge/refresh was correctly skipped instead of vanishing from the log.
- Removed the post-save `assert()` in `ResourceStorage::saveDonut()`: with assertions enabled it threw AFTER the `saved: false` event was logged, contradicting quiet-failure recording.
- Pre-write cleanup is recorded at the source instead of inferred: `QueryRepository::doPut()` and `DonutRepository::putStatic()`/`putDonut()` emit a `pre_write_cleanup` marker right before clearing the entry they are about to rewrite, and an `invalidate` is cleanup iff the event immediately preceding it in the same scope's event stream is that marker. This supersedes the earlier reader-side tag-correlation rule — and removes its undecidable donut case — in the schemas and guides.
- A donut refresh no longer appends an `r` marker to the ETag: an identical recomposition keeps its entity-tag, so a client's `If-None-Match` still revalidates to 304 instead of getting a full 200. The refresh is recorded as the `refresh_donut` log event.
- Added runtime dependency `koriym/semantic-logger`.
- Recording is off by default: `QueryRepositoryModule` binds `SemanticLoggerInterface` → `Koriym\SemanticLogger\NullSemanticLogger`, the same rule the package already applies to its cache engine, and the recording binding moves out of the internal `DonutCacheModule` into the two log modules. Nothing is recorded, nothing accumulates and no flush is owed until an app installs one — a healthy session was measured to contain no failure entries at all, against ~1.4 KB accumulated per request.

### Fixed
- An embedded child was materialized twice per parent store: the storage re-invoked the request instead of reusing the execution `setCacheDependency()` and the renderer already paid for, so a `type: "view"` entry could hold a body and a view from two different runs of a non-idempotent child.
- A donut refresh advanced `Last-Modified` even when the recomposed content was byte-identical, so an unchanged representation looked changed; the recorded content is compared and the original time carried over. `Age` is now the time since the state was stored (`storedAt`) instead of being derived from `Last-Modified`, which is the content's change time — entries stored before `storedAt` existed fall back to the old derivation.
- `putStatic()`/`putDonut()` recorded a negative lifetime verbatim while the storage clamped what it stored, so the log both contradicted its own save events and violated the published `put_donut` schema (`minimum: 0`). The requested lifetime is clamped where it is recorded.
- A donut write failure (pool outage or renderer error during `putStatic()`/`putDonut()` on the AOP path) propagated without any in-band record; it is now recorded as `cache_error{operation: write}` before the exception propagates, matching the read side and the plain `#[Cacheable]` write side.
- `ResourceStorage::saveEtag()` tagged entries with a hard-coded `new UriTag()` instead of the injected `UriTagInterface`, mistagging under a custom binding.

## [1.16.2] - 2026-06-29

### Fixed
- Remove unused ETag from invalidation tags: ETag was unnecessarily registered as a cache invalidation tag. No code path invalidates by ETag (only by URI tag and surrogate keys), so each content version produced a new, non-volatile tag Set that was never read or reclaimed. Under `volatile-*` eviction policies these Sets leaked memory indefinitely. (#180)

## [1.16.1] - 2026-06-01

### Fixed
- Fix multi-embed cache dependency: a `#[Cacheable]` resource embedding more than one child kept only the last child's dependency, so purging an earlier child failed to invalidate the parent (stale cache). `CacheDependency::depends()` now accumulates child tags instead of overwriting, and the erroneous assertion that a parent had no prior tags has been removed.

## [1.16.0] - 2026-05-16

### Fixed
- Resolve embed cache dependencies before HAL rendering: child resources' ETag headers are now collected before `HalRenderer` strips `Request` instances from the body, so the parent's `Surrogate-Key` reliably includes all embedded children (#174)
- Include async embed children in dependency resolution: walk by `AbstractRequest` instead of the concrete `Request`, so `AsyncRequest` (and other `AbstractRequest` subclasses) are no longer silently skipped

### Changed
- Cache dependency resolution moved from `EtagSetter` / `DevEtagSetter` into `QueryRepository::put()` (runs on HTTP 200 only, before persistence)
- `QueryRepository::__construct()` now requires `CacheDependencyInterface`
- `EtagSetter::__construct()` and `DevEtagSetter::__construct()` no longer accept `CacheDependencyInterface`
- DI users via `QueryRepositoryModule` are unaffected; only callers that directly `new` these classes need to update their constructor calls

### Added
- HAL embed cache dependency test (`tests/CacheDependencyTest.php`)
- Non-Cacheable embed child test fixtures and `continue` path coverage

## [1.15.0] - 2026-02-03

### Added
- Add `ServerContextInterface` for coroutine-safe request handling in Swoole/RoadRunner environments
- Add `GlobalServerContext` as default implementation using `$_SERVER` superglobal
- Add `RepositoryLoggerInterface` with `reset()` method for long-running process support

### Changed
- Bind `ServerContextInterface` to `GlobalServerContext` in `QueryRepositoryModule`
- Use `ServerContextInterface` in `ResourceStorage` instead of direct `$_SERVER` access

## [1.14.0] - 2025-01-24

### Added
- Add LLM documentation (`docs/llms.txt`, `docs/llms-full.txt`) for AI-assisted development
- Add JSON schema for RepositoryLogger output (`docs/schemas/repository-log.json`)
- Add cache dependency demos for AI log analysis (`demo/run-dependency.php`, `demo/run-donut.php`)
- Add cache dependency test coverage documentation (`tests/CACHE_DEPENDENCY_TESTS.md`)
- Add test resources for cache dependency patterns (ParentA, ParentB, ChildC)

### Changed
- Change RepositoryLogger output to JSON format for structured logging
- Update `.gitattributes` to exclude development files from release
- Require `ray/aop` ^2.19.1 and `ray/di` ^2.20 for PHP 8.5 compatibility
- Update copyright year to 2026

### Fixed
- Fix UriTagTest typo in documentation

## [1.13.0] - 2024-11-11

### Added
- **Migration Tools**: Added `rector-migrate.php` for automated annotation-to-attribute migration
- **Migration Guide**: Added `ANNOTATION_TO_ATTRIBUTE.md` with comprehensive migration instructions
- Add CLAUDE.md with comprehensive codebase architecture and development guide
- Add marshaller configuration support for Redis with compression options (deflate)
- Add `MarshallerType` enum for type-safe marshaller selection
- Add support for `RelayCluster` in `RedisDsnProvider`
- Add Japanese README (README.ja.md)
- Add `#[Override]` PHP attribute across all applicable methods and classes
- Add Memcached EtagPool module with TagAwareAdapter support
- Add Redis DSN module (`StorageRedisDsnModule`) with provider implementation
- Add TagsPool annotation and binding to QueryRepositoryModule
- Add validation to ensure FastlyPurgeModule is installed when used
- Add Dependabot configuration
- Add PHP 8.4 support to CI workflow
- Add PHP 8.5 support to CI workflow

### Changed
- **PHP 8 Attributes Migration**: Removed `doctrine/annotations` and `doctrine/cache` dependencies, migrated to native PHP 8 attributes
- **Minimum PHP Version**: Updated requirement from PHP 8.1 to PHP 8.2
- Update development tools: PHP_CodeSniffer to 4.0, Doctrine Coding Standard to 14.0, Slevomat Coding Standard to 8.24, PHPUnit to 11.5
- Optimize readonly class declarations for PHP 8.2 (class-level modifier)
- Improve cache attribute and interceptor documentation with usage examples
- Improve `rector-migrate.php` to support vendor installation by removing hardcoded paths
- Update `ANNOTATION_TO_ATTRIBUTE.md` migration guide following Ray.AuraSqlModule pattern
- Improve marshaller provider error handling with better exception messages
- Enhance Memcached module with TagAwareAdapter support
- Update Symfony Cache to support version ^7.3
- Update `symfony/polyfill-php83` dependency to `^v1.32.0`
- Update `mobiledetect/mobiledetectlib` to support version ^4.8
- Update composer dependencies (`madapaja/twig-module`, `phpunit/phpunit`, `predis/predis`, `twig/twig`, `symfony/process`)
- Update `vimeo/psalm` to version 6.12
- Update `ray/aop` dependency to ^2.16
- Update `ray/di` dependency to ^2.17.2
- Set `ResourceStorage` to singleton scope for better performance
- Refactor `ResourceStorage` with `ProviderInterface` for serialization support
- Improve UriTag test coverage for consistent key generation across parameter order
- Normalize URI separators in generated cache keys for cross-platform compatibility
- Simplify ETag and surrogate key generation logic
- Handle both forward slashes and backslashes in surrogate key generation
- Sanitize ETags to replace reserved characters
- Replace symlink with actual file for cross-platform compatibility
- Enable package sorting in composer.json
- Refactor CI workflow for expanded PHP version and OS coverage
- Update copyright year to 2025

### Removed
- Remove Sodium marshaller related code
- Remove `bear/fastly-module` from production dependencies (moved to dev dependencies)
- Remove unnecessary singleton scopes from bindings
- Remove unused PSR cache annotations and RedisAdapter binding
- Remove unused MemcachedAdapter bindings
- Remove redundant `assert` statements from codebase
- Remove unused dependencies from composer.json

### Deprecated
- Deprecate `StorageApcModuleTest`
- Deprecate `StorageRedisModuleTest` (use `StorageRedisDsnModule` instead)
- Deprecate `StorageRedisMemcachedModule`
- Deprecate `BcModule`
- Deprecate `NamespacedCacheProvider` class
- Deprecate `ResourceStorageCacheableTrait`

### Fixed
- Fix type casting in headers
- Fix path separator replacement for cross-platform compatibility (Windows/macOS/Linux)
- Fix typo in deprecated notice
- Fix README file extension issue

## [1.9.9] - 2024-XX-XX
(Previous releases not documented yet)