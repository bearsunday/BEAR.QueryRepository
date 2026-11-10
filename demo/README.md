# Cache log demos

Each script runs a set of requests and prints the cache log they produced as a tree, then validates the log against `docs/schemas/context`.

| Script | What it shows |
|---|---|
| `run.php` | A `#[Cacheable]` resource: store, hit, refresh after its own write, 304 |
| `run-kinds.php` | `#[Cacheable]` value and view, `#[CacheableResponse]`, `#[DonutCache]`: store, hit, 304; `Cache-Control: max-age=0`; a body that cannot be stored |
| `run-write.php` | `#[Refresh]` and `#[Purge]` with a list for an exploded variable, an empty list, a snake_case write query, a `Stringable` query value |
| `run-dependency.php` | Embedded children (`depends_on`) and the cascade a write or purge causes |
| `run-donut.php` | Donut pages: holes (`dependency_skipped`), refresh, `depends()` while rendering |
| `run-degraded.php` | Refusals and failures: unstorable responses, a pool or CDN that fails |

```bash
php demo/run-kinds.php
```

## Snapshots

`demo/logs/<script>.log` is each script's output, kept as a snapshot. `tests/DemoSnapshotTest.php` runs every script and compares its output with the snapshot, so a change in what the cache does shows up as a failing test.

After an intended change, rewrite the snapshots and review their diff:

```bash
composer demo
git diff demo/logs
```

The `durationMs`, ETag and `Last-Modified` values change from run to run, so `demo/snapshot.php` replaces them before comparing.
