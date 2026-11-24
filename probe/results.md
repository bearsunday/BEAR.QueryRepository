# Redis adapter と CDN ヘッダーの調べ（2026-11-24、upstream/1.x 7a76681e、symfony/cache 8.1.11、Redis 8.10.3 ローカル）

このブランチは調べの記録で、merge しない。`upstream/1.x` の 7a76681e に、`probe/`（スクリプトとこの記録）と、確かめ用の page（`tests/Fake/fake-app/src/Resource/Page/Html/Probe*.php` とテンプレート）を足しただけで、`src/` は変えていない（4 節の試作は `probe/prototype-http-cache-interceptor.diff` に差分だけ置いた）。

動かし方: `composer install` の後、`php probe/<名前>.php`。引数を取るスクリプトは、頭のコメントに usage がある。使うのは手元の Redis（6379。cluster は 7001〜7003）と MySQL（3407 の `cache` データベース）。数字はどれも 1 台の手元の機械で、ネットワークの往復を含まない。

**注意:** Redis か MySQL に触るスクリプトは、Redis を `flushAll` したり、その設定（maxmemory など）を変えたり、MySQL の表を作り直したりする。捨ててよい Redis と MySQL を立ててから、`PROBE_ALLOW_FLUSH=1 php probe/<名前>.php` で実行する。付けないと `probe/guard.php` が止める。

条件: 1000 page、1 page に tag 100 本（共有 200 本から 20 本、行 5000 本から 80 本）、本文 50KB。スクリプトは `probe/adapters.php <rta|ta> <scenario>`。

<a id="s1"></a>

## 1. RedisTagAwareAdapter (rta) と TagAwareAdapter + RedisAdapter (ta)

| 項目 | rta | ta |
|---|---|---|
| namespace 1 回の切り替えで残る TTL なしのキー | 5,200（tag の SET） | 5,200（tag の版） |
| その量 | 約 2.1MB | 約 0.42MB |
| 1 回の読み（新しい adapter） | 1 コマンド、0.14ms | 2 コマンド、0.45ms |
| 共有 tag 200 本を purge した後のメモリ | 68.7MB → 2.9MB（項目も消える） | 67.3MB のまま（項目は TTL か追い出しまで残る） |
| allkeys-lru での保存 | `save()` が false。何も入らない（例外は外に出ない） | 動く |
| 30MB に押し込み、hot 50 page を読み続ける（miss なら保存し直す） | volatile-lru: hit 91%、残った page はすべて hit | allkeys-lru: hit 30%、残った 455 page のうち 400 が miss |
| 同じく、古い namespace に TTL なしの 400 page を残した状態 | hit 89% | hit 73%、225 page のうち 28 が miss |

- ta では tag の版のキーも TTL なし。volatile-* では追い出されず、rta と同じく溜まる。溜まらないのは allkeys-* のときだけ。
- allkeys-lru では小さな tag の版のキーが追い出され、その tag を持つ page がまとめて miss になる。古い内容を返したことはない（stale 0）。

<a id="s2"></a>

## 2. CDN 向けのヘッダー

`#[Embed]` を 2 本持つ page の応答（`tests/Fake/.../Page/Html/Probe*.php`、`probe/headers.php`）。

| page | 出たヘッダー |
|---|---|
| `#[HttpCache(sMaxAge: 600)]` だけ | `Cache-Control: s-maxage=600` だけ。`Surrogate-Key` は出ない |
| `#[CacheableResponse]` + `#[HttpCache(sMaxAge: 600)]` | `Cache-Control: s-maxage=600, max-age=0`、`Surrogate-Key`、`CDN-Cache-Control: max-age=10, stale-while-revalidate=10`、ETag、Last-Modified |

- CDN の寿命のヘッダーと `Surrogate-Key` を書くのは `DonutRepository`（`HeaderSetter::page()`）だけ。
- 2 行目の `CDN-Cache-Control: max-age=10` は、`DonutCacheableResponseInterceptor::put()` が `putStatic($ro, null, null)` と sMaxAge に null を渡し、`CdnCacheControlHeaderSetter` が `$sMaxAge ??= 10` で補ったもの。`#[HttpCache(sMaxAge)]` は `Cache-Control` にしか入らない。CDN の寿命の入口は `putStatic()` の sMaxAge だけで、既定 10 秒は manual の「Default TTL value」のとおり。宣言した s-maxage と食い違う場合の扱いは、manual にも llms-full.txt にも書かれていない。
- 2 行目の `Surrogate-Key` に子の tag が入らないのは、置いた子が依存の条件を満たさないため。`probe/children.php` のログでは、2 本とも `dependency_skipped`（`no_body`）だった。`app://self/code` は 203 で本文がない。`app://self/no-http-cache-control` も本文がない。本文を持つ子を onGet で読む既存の page（`cacheable-response-with-material`）では、子の tag（`_html_mutable-comment_ grp-… mutable-comment`）が入る。

<a id="s3"></a>

## 3. allkeys-* での保存の失敗はログに出るか（`probe/pool-error.php`）

`StorageRedisDsnModule('redis://127.0.0.1:6379')` で `app://self/user?id=1` を 2 回 GET した。

| maxmemory-policy | ログ | Redis のキー |
|---|---|---|
| allkeys-lru | 毎回 `pool_error`（`operation: write`、`LogicException`: `maxmemory-policy setting "allkeys-lru" is *not* supported…`）と `save_value`（`saved: false`） | 0 |
| volatile-lru | 1 回目に `save_value`（`saved: true`）、2 回目は hit | 3 |

- `PoolErrorLogger` は `StorageRedisDsnModule` が `setLogger()` で渡しており、失敗を記録する。`ProdQueryRepositoryLogModule` の `KeepMutationsAndFailures` は `saved: false` を含むセッションを残す。
- 黙って失うのは、log module を入れない既定（`NullSemanticLogger`）と、adapter を自分で束縛して `setLogger()` を渡さない場合。
- Redis の maxmemory-policy の条件（`noeviction` か `volatile-*`）は、llms-full.txt にも `StorageRedisDsnModule` にも書かれていない。

<a id="s4"></a>

## 4. origin に保存しない page の Surrogate-Key（試作、`probe/cdn-only.php`）

`src/HttpCacheInterceptor.php` を一時的に書き換えて測った（差分は `probe/prototype-http-cache-interceptor.diff`。src は元に戻した）。保存の属性がなく、`#[HttpCache]` の sMaxAge が正の 200 で、`CacheDependencyCollector::collect()` と `CacheTags::tags()` を当て、CDN の setter に宣言した sMaxAge を渡す。

page `ProbeCdnOnly`: `#[HttpCache(sMaxAge: 600)]`、`#[Embed]` が 2 本（`#[Cacheable]` の `mutable-comment`、キャッシュの属性のない `probe-plain-child`）、`#[DependsOn('app://self/taxonomy?id={id}')]`。

```
Cache-Control: s-maxage=600
Surrogate-Key: _taxonomy_id=7 _html_mutable-comment_ grp-_html_mutable-comment_ mutable-comment _html_probe-plain-child_ grp-_html_probe-plain-child_ _html_probe-cdn-only_id=7 grp-_html_probe-cdn-only_
CDN-Cache-Control: max-age=600, stale-while-revalidate=10
```

| purge した URI | purger に渡った tag が page の Surrogate-Key に当たるか |
|---|---|
| `page://self/html/mutable-comment` | 当たる |
| `page://self/html/probe-plain-child` | 当たる |
| `app://self/taxonomy?id=7` | 当たる |
| `page://self/html/probe-cdn-only` | 当たる（group tag） |
| `page://self/html/probe-cdn-only?id=7` | 当たる |
| `app://self/taxonomy?id=8` | 当たらない |

- 書き込み `PATCH app://self/user?id=1`（200）で、purger に `_user_id=1 grp-_user_id=1` が渡った。CommandInterceptor から CDN の purge までは、origin に保存していなくても動く。
- キャッシュの属性のない子（`probe-plain-child`）も、本文があれば tag が入る（`Dependencies::tag()` の条件どおり）。この repo の `docs/llms-full.txt`（「Cache Dependencies」と `dependency_skipped` の行）も同じことを書いている。食い違うのは bearsunday.github.io の英語の manual（ https://github.com/bearsunday/bearsunday.github.io/blob/master/manuals/1.0/en/cache.md 「Tag-based Cache Invalidation」）で、"This dependency is registered only when the resource embedded with `#[Embed]` is itself made cacheable, for example with `#[Cacheable]`, or declares `#[DependsOn]` (see below). A resource with neither, read in `onGet()`, is not a dependency" と書いている（11/24 に raw を読んで確かめた。日本語版は読んでいない）。

<a id="s5"></a>

## 5. PdoAdapter に page を置く（`probe/pdo.php`、`probe/redis-deflate.php`）

条件は 1 節と同じ（1000 page、tag 100 本）。本文だけ、実在の HTML（ https://bearsunday.github.io/manuals/1.0/en/cache.html 、`probe/sample.html`）を 50KB に切ったものにした。MySQL 26.10 は手元に別に立てた（port 3407、binlog なし、測定後に datadir ごと消した）。どれも同じ機械の上で、ネットワークの往復を含まない。

| 構成 | 1000 page の量 | 保存 1 回 | 読み 1 回 | 読み 1 回の往復 |
|---|---|---|---|---|
| `RedisTagAwareAdapter` | 64.6MB（used_memory） | 0.46ms | 0.14ms | Redis 1 |
| `RedisTagAwareAdapter` + deflate | 13.6MB | 1.13ms | 0.18ms | Redis 1 |
| `TagAwareAdapter`（本体・tag とも MySQL の PdoAdapter） | 52.5MB（`item_data` の合計。InnoDB の data_length は 2000 行で 126MB） | 2.62ms | 0.65ms | SQL 2 |
| 同上 + deflate | 11.3MB | 2.85ms | 0.72ms | SQL 2 |
| 本体は MySQL、tag の版は Redis | 52.5MB + Redis 5,200 キー | 1.80ms | 0.46ms | SQL 1 + Redis 1 |
| 本体・tag とも SQLite（参考） | 52.5MB / deflate 11.3MB | 4.28ms / 9.07ms | 0.40ms / 0.42ms | — |

- 上の Redis の 2 行は、`probe/redis-deflate.php` が自分で組んだ adapter の値。`StorageRedisDsnModule` 経由でも測った（`probe/redis-module.php`、injector から `ResourceObjectPool` の pool を取り、同じ 1000 page を保存）。`marshallingOptions` なしで 64.3MB、`['enabled' => true, 'type' => 'deflate']` で 13.2MB。pool はどちらも `RedisTagAwareAdapter`。
- 1 page あたりにすると、モジュール経由の deflate で約 13KB、deflate なしで約 64KB（1000 page の値を 1000 で割った値。ほかの page 数では測っていない）。
- purge（共有 tag 200 本）は tag の版を消すだけ。本体の 1000 行は残り、読むとすべて miss（stale 0）。保存し直すと同じ行を上書きし、行数は 1000 のまま（URI の数で上限）。
- namespace を替えて保存すると 2000 行になる。`prune()` は期限切れしか消さず、寿命 1 年（`#[Cacheable]` の既定 `never` は `requestedTtl: 31536000`）の行は残る。PdoAdapter には追い出しがないので、古い namespace の行は手で消すまで残る。
- 行の形: `item_id` は `ns1:ro-page0`（BQR では `ro-<K>`、K は URI のハッシュ）、`item_data` は PHP の serialize（先頭 `O:1:"©"`）。URI・tag・ETag は列にない。保存は UPSERT なので、前の内容は残らない。
- BQR の pool は 1 つ（`ResourceStorage::$roPool`）。PdoAdapter に束縛すると、`#[Cacheable]` の値も page も DB に入る。
- BQR の保存のログ（`save_value` など）が持つのは `uri`・`tags`・`requestedTtl`・`saved`。ETag（内容のハッシュ）は持たない。

<a id="s6"></a>

## 6. default と deflate、本体と tag の内訳（`probe/redis-breakdown.php`）

`RedisTagAwareAdapter` を `StorageRedisDsnModule` と同じ引数（marshaller は `MarshallerProvider` から）で組んだ。1000 page、tag 100 本、本文は 5 節と同じ HTML。量は鍵ごとの `MEMORY USAGE … SAMPLES 0` の合計。

| | 本体（TTL 1 年） | tag の SET（TTL なし） | used_memory | 保存 1 回 | 読み 1 回 |
|---|---|---|---|---|---|
| default | 1000 本 62.5MB | 5,200 本 1.7MB | 65.7MB | 0.64ms | 0.17ms |
| deflate | 1000 本 11.8MB | 5,200 本 1.7MB | 14.9MB | 1.32ms | 0.18ms |

- deflate で本体は約 1/5.3（−81%）。tag の SET は圧縮されず、どちらも 1.7MB。
- namespace を ns1 → ns2 に替えると、ns1 の本体と tag がそのまま残り、量が倍になる（default 130.2MB、deflate 28.7MB）。volatile-* で追い出せるのは TTL のある本体だけで、tag の SET は残り続ける。
- `(new RedisTagAwareAdapter($redis, 'ns1', …))->clear()` で、ns1 の本体も tag も 0 本になり、ns2 は残った（used_memory 65.7MB / 15.0MB に戻る）。`clear()` は `ns1:*` を SCAN して消す（`RedisTrait::doClear()`）。namespace が空文字だと `flushDb()` になる（同 621 行）。
- 1 節の「5,200 本、約 2.1MB」は used_memory の差で測った値。ここでは鍵ごとの MEMORY USAGE の合計で 1.7MB。

<a id="s7"></a>

## 7. 単体の Redis と 3 台の cluster（`probe/cluster.php`）

どちらも `StorageRedisDsnModule`（deflate）が組む pool で、1 回ごとに injector から pool を取り直した。cluster の DSN は `redis:?host[127.0.0.1:7001]&host[…:7002]&host[…:7003]&redis_cluster=1`。どちらも手元で、replica なし、volatile-lru。ネットワークの往復は入っていない。

| | 単体 | cluster |
|---|---|---|
| pool | `RedisTagAwareAdapter` on `Redis` | `RedisTagAwareAdapter` on `RedisCluster` |
| 保存 1 回 | 1.15ms | 11.66ms |
| 読み 1 回（injector から毎回取る） | 0.46ms | 0.73ms |
| 読み 1 回（同じ pool を使い回す、`probe/roundtrips.php`） | 0.23ms | 0.17ms |
| 行の tag 1 本の無効化 | 0.72ms | 2.49ms |
| 共有 tag 200 本の無効化 | 24.9ms | 1,571ms |
| 量 | 14.5MB | 7.2 + 7.1 + 7.3MB（鍵はほぼ 3 等分） |

- 正しさは同じ。行の tag 1 本の無効化で、miss になったのはその tag を持つ 14 page だけで、期待と一致した。`clear()` の後に残った鍵は 0。
- 往復の数（`probe/roundtrips.php`、`INFO stats` の `total_reads_processed` の差。50 回の平均）:

  | | 単体 | cluster |
  |---|---|---|
  | 保存 1 回 | 102 コマンド（SETEX 1、SADD 100、MGET 1）を **2 往復** | 同じ 102 コマンドを **102 往復** |
  | 読み 1 回（injector から毎回取る） | SELECT 1 + MGET 1、3 往復 | CLUSTER SLOTS 1 + MGET 1、4 往復 |
  | 読み 1 回（同じ pool を使い回す） | MGET 1、1 往復 | MGET 1、1 往復 |

- cluster の保存では、page 1 つの 102 コマンドがそれぞれ別の往復になった。`RedisTrait::pipeline()` は `RedisCluster` のとき、コマンドを 1 つずつ送る分岐に入る（745〜752 行）。
- 表の「injector から毎回取る」読みには、毎回の接続の確立が入っている。injector から取るたびに、単体では SELECT、cluster では CLUSTER SLOTS が 1 回ずつ増えた。`StorageRedisDsnModule` の `redis` と pool の束縛には scope がない。接続を使い回すと、読みは単体と cluster でほぼ同じになる（0.23ms と 0.17ms）。

<a id="s8"></a>

## 8. 同時に打ったときの全体の回数（`probe/throughput.php`）

N 個のプロセスを fork し、3 秒間、ランダムな page の読み（または保存）を続けた。各プロセスは接続を 1 本作って使い回す。page は 1000 件で deflate、tag は 100 本。機械は論理 CPU 14 個で、PHP のプロセスと Redis が同じ CPU を取り合う。

| workers | 単体 読み | cluster 読み | 単体 保存 | cluster 保存 |
|---|---|---|---|---|
| 1 | 6,241/秒 | 6,148/秒 | 743/秒 | 56/秒 |
| 4 | 17,484/秒 | 19,670/秒 | 3,088/秒 | 253/秒 |
| 8 | 32,103/秒 | 29,122/秒 | 4,975/秒 | 359/秒 |
| 12 | 41,283/秒 | 39,353/秒 | 6,320/秒 | 457/秒 |

- 読みは、12 workers まで単体と cluster でほぼ同じ。この機械では、単体の Redis が頭打ちになる点に届いていない。それより先は、PHP と Redis が CPU を取り合うので測れない。
- 保存は、cluster が単体の約 1/14。cluster の 3 台は、12 workers で `INFO cpu` の差が 92〜94% だった（単体は 79%）。
- `INFO cpu` の差で見た単体の読みの CPU は、4 workers から 99〜100% だった。それなのに回数は 41,283/秒まで伸び続けた。この理由は確かめていない。
- 手元の 3 台は同じ CPU 14 個を分け合っていて、ネットワークの往復もない。台数が増えて CPU が増える、という cluster の利点はここでは効かない。この節で分かるのは、この並列度では単体が頭打ちにならず、分散で速くなる条件がまだ来ていない、ということまで。本番で単体が頭打ちになるかは、本番の `INFO stats` の `instantaneous_ops_per_sec` と `INFO cpu` で見る。
- 一方、保存 1 回の往復の数（単体 2、cluster 102。7 節）は機械によらない。本番では 1 往復の時間を掛けた分だけ差が開く。たとえば 1 往復 0.5ms なら、単体 約 1ms、cluster 約 51ms（計算。測っていない）。

<a id="s9"></a>

## 9. 大きな古い namespace に clear() を当てる（`probe/clear-impact.php`）

古い namespace（`old`）に page を積み、今の namespace（`cur`、1000 page）を別プロセスが読み続ける中で、`old` に `clear()` を当てた。deflate、tag 100 本。手元の Redis で、ネットワークの往復はない。

| | 30,000 page（41,400 鍵、507MB） | 30,000 page + 古い版のサーバー | 100,000 page（111,400 鍵、1,701MB） |
|---|---|---|---|
| `clear()` にかかった時間 | 0.06 秒 | 0.05 秒 | 0.18 秒 |
| 今の namespace の読み（clear の前） | p50 0.159ms / p99 0.253ms / max 0.7ms | p50 0.165 / p99 0.226 / max 0.5 | p50 0.157 / p99 0.208 / max 0.6 |
| 同（clear の最中） | p50 0.363ms / p99 1.088ms / max 6.3ms（112 回） | p50 0.331 / p99 0.952 / max 1.0（110 回） | p50 0.458 / p99 1.291 / max 15.7ms（249 回） |
| 終わって 2 秒後の `old` の鍵 | 0 | **6,754** | 0 |
| used_memory（2 秒後） | 15MB | 37MB | 15MB |

- 古い版のサーバーは、`old` を読み、miss なら保存し直すプロセス。clear の後は miss になり、1,554 page を保存し直して、`old` に鍵が 6,754 本戻った。例外は 0、古い内容を返したこともない。古い版のサーバーが残っている間に消すと、消した分がまた溜まる。
- clear の最中は、今の namespace の読みが遅れた（p99 で 0.2ms → 1ms 前後、最大 15.7ms）。続いたのは clear の 0.05〜0.18 秒だけ。
- `clear()` は 1,000 鍵ずつ SCAN し、UNLINK（phpredis では `unlink`、`RedisTrait.php` 677 行）で消す。メモリは UNLINK の後で非同期に返る。
- ネットワーク越しの本番では、SCAN と UNLINK のたびに往復が入るので、もっと時間がかかるはず（推測。測っていない）。

<a id="s10"></a>

## 10. 1 つの namespace の中でも tag の SET は溜まるか（`probe/tag-drift.php`）

一覧 page 100 件。各 page は共有 tag `list` と、新しい 1000 行の中からランダムな 50 行の tag を持つ。1 回ごとに 200 行が増え、全 page を保存し直す。古い行への書き込みはない。`RedisTagAwareAdapter`、deflate、namespace は 1 つのまま。保存し直す前に `list` を無効化する場合（invalidate。一覧への書き込みの後の miss からの保存）と、無効化せず生きている項目に上書きする場合（overwrite。hit からの保存）を比べた。

| 回 | 行の数 | 本体 | tag の SET（invalidate） | 要素（invalidate） | tag の量（invalidate） | tag の SET（overwrite） | 要素（overwrite） |
|---|---|---|---|---|---|---|---|
| 1 | 1,200 | 100 | 997 | 5,100 | 0.11MB | 996 | 5,100 |
| 10 | 3,000 | 100 | 2,800 | 46,251 | 0.72MB | 996 | 5,100 |
| 25 | 6,000 | 100 | 5,799 | 114,096 | 1.74MB | 996 | 5,100 |
| 50 | 11,000 | 100 | 10,801 | 227,172 | 3.44MB | 995 | 5,100 |

- invalidate では、本体は 100 本のままなのに、tag の SET は、これまでに載った行の数だけ増え続けた。50 回目には、一覧から外れた行（どの page の今の tag でもない行）の SET が 9,800 本、その要素が 213,056 あった。例: `row3875` の SET の要素は `ns:ro-list17` など。どれも項目は今もあるが、その tag は今は持っていない。
- overwrite では増えなかった。`AbstractTagAwareAdapter` は保存のとき、取り出したときの tag（`$item->metadata[METADATA_TAGS]`）にあって今はない tag の SET から id を外す（122〜128 行）。miss から作った項目には取り出したときの tag がないので、外すものがない。要素がなくなった SET は Redis が消す。
- BQR の書き込みは、purge で項目を消し、次の GET の miss から保存し直す。つまり invalidate の側に当たる。期限切れや追い出しの後の保存も miss からになる。
- それまで「同じ namespace の中では URI の数で上限がある」と見ていたが、成り立つのは page の tag が変わらないときだけだった。
- 生きている namespace の中でこの分を減らせるのは、その行への書き込み（tag の無効化）か、namespace 全体を消すことだけ。デプロイで namespace を替えた後に古い namespace へ `clear()` を当てれば、この分も含めて消える（6 節・9 節）。

<a id="s11"></a>

## 11. DSN は 1 台、redis_cluster は options で渡す形で deflate と clear()（`probe/clear-namespace.php`）

`StorageRedisDsnModule($dsn, ['redis_cluster' => 0 か 1], marshallingOptions: deflate)` の形。DSN は `redis://127.0.0.1:6379`（単体）か `redis://127.0.0.1:7001`（cluster）。fake app の `CacheNamespace` は空文字なので、clear() を確かめる namespace はスクリプトで明示した（`oldns`、`curns`）。

| | 単体（`Redis`） | cluster（`RedisCluster`） |
|---|---|---|
| `StorageRedisDsnModule` が組む pool | `RedisTagAwareAdapter` on `Redis` | `RedisTagAwareAdapter` on `RedisCluster` |
| 本文 50,000 バイトの項目 1 本の MEMORY USAGE（deflate） | 10,281 バイト | 10,281 バイト |
| clear() の前 | oldns 201 鍵、curns 201 鍵 | oldns 201 鍵、curns 201 鍵 |
| marshaller なしの adapter で oldns に clear() の後 | curns 201 鍵だけ | curns 201 鍵だけ |

- 鍵の数は、単体では 6379、cluster では 7001・7002・7003 をそれぞれ SCAN して、namespace ごとに足した合計。ノードごとの内訳は出していない。
- namespace が空文字のまま clear() を当てると `flushDb()` になる（6 節）。最初に fake app の空文字の namespace で試した版は、そのために検証にならなかったので捨てた。

<a id="s12"></a>

## 12. BQR の page を、pool を MySQL だけにして GET する（`probe/bqr-pdo.php`）

fake app の page を、pool を `RedisTagAwareAdapter`（Redis）にした場合と、`TagAwareAdapter(PdoAdapter, PdoAdapter)`（本体も tag の版も MySQL）にした場合で比べた。どちらも deflate。hit の GET を 200 回繰り返し、page は描画まで含めた。MySQL は port 3407 に別に立て、測った後に datadir ごと消した。手元なので、ネットワークの往復は入っていない。`TagAwareAdapter` の tag の版の使い回し（既定 0.15 秒）は、リクエストをまたいで効かないよう 0 にした。

| hit の 1 リクエスト | Redis | MySQL だけ |
|---|---|---|
| `#[Cacheable]` の app resource（`app://self/user?id=1`） | 0.21ms、コマンド 1 | 0.26〜0.31ms、SQL 2 |
| `#[CacheableResponse]` の page（`blog-posting`、穴 1 つ） | 0.16ms、コマンド 1 | 0.19〜0.22ms、SQL 2 |
| `#[DonutCache]` の page（`blog-posting-donut`、キャッシュする穴 1 つ） | 0.45〜0.51ms、コマンド 3 | 0.51〜0.55ms、SQL 6 |
| `#[DonutCache]` の page（`counter-donut`、キャッシュしない穴 1 つ。穴は毎回走る） | 0.30ms、コマンド 2 | 0.34ms、SQL 4 |
| 接続して 1 往復 | 0.25〜0.27ms | 0.20〜0.22ms |

- 2〜4 回流した範囲（`counter-donut` は 2 回）。手元では、どの page でも差は 0.03〜0.07ms だった。
- 往復の数は、MySQL だけにすると倍になった（項目の SELECT と tag の版の SELECT）。`#[DonutCache]` の page は、キャッシュする穴 1 つで Redis 3 回・MySQL 6 回、キャッシュしない穴 1 つで Redis 2 回・MySQL 4 回。コードから見た内訳は、view の読み（`DonutRepository::get()`）、テンプレートの読み（`refreshDonut()`）、キャッシュする穴があればその読み。内訳ごとには数えていない。
- 本番では、1 往復あたりのネットワークの時間 × 増えた往復の数だけ遅くなる（計算。測っていない）。
- 「接続して 1 往復」は、1 つのプロセスの中で、接続を張って 1 往復して閉じることを繰り返した値。本番の PHP-FPM で 1 リクエストごとに新しく接続する費用（ネットワークの往復、TLS、認証）と同じとは限らない。
- 最初の版では、html のテンプレートのない app resource を `(string)` で描画して例外が出ていた。page だけ描画するように直して測り直した。

<a id="s13"></a>

## 13. BQR の既存の束縛だけで DB を pool にする（`probe/pdo-library.php`、SQLite）

`QueryRepositoryModule` は、`AdapterInterface #[ResourceObjectPool]`（本体）と `AdapterInterface #[TagsPool]`（tag の版。null なら本体と同じ pool）から `TagAwareAdapter` を組む。`StorageMemcachedModule` も同じ形。ここでは `AdapterInterface #[ResourceObjectPool]` を `PdoAdapter`（SQLite、namespace `app`）に束縛しただけで、ほかは既定のまま。

- injector から取った pool は `TagAwareAdapter`。`page://self/html/blog-posting` は 2 回目が hit（`Age: 0`、view は 1 回目と同じ）。`counter-donut` も 2 回目は保存したテンプレートから組んだ（`Age` なし）。view が 1 回目と違うのは想定どおりで、穴の `app://self/counter` は毎回走り、呼ばれた回数（`call:1` → `call:2`）を出す。既存の `DonutBodyTest::testAHitReadsTheHoleAgainWithoutRunningThePage` も、hit で `counter.call` が 2 になり、page の `read` は 1 のまま（page の onGet は走らない）ことを確かめている。本体 4 行、tag の版 10 行が同じ表に入った。
- tag が移る一覧（10 節と同じ流れ。`TagAwareAdapter(PdoAdapter)`、namespace `drift`）:

  | 回 | 行の数 | 本体 | tag の版の行 | tag の版の item_data 合計 |
  |---|---|---|---|---|
  | 1 | 1,200 | 100 | 994 | 12,922 バイト |
  | 10 | 3,000 | 100 | 2,798 | 36,374 バイト |
  | 25 | 6,000 | 100 | 5,798 | 75,374 バイト |
  | 50 | 11,000 | 100 | 10,798 | 140,374 バイト |

  tag の版の行は、10 節の Redis の SET と同じく「これまでに載った行の数」だけ増えた。違うのは 1 本の中身で、版の値（約 13 バイト）だけを持ち、page の id の一覧を持たない。値の合計は 50 回目で 140KB（Redis の SET は 3.44MB）。行の id や表の索引の分は数えていない。
- `TagAwareAdapter(new PdoAdapter($pdo, 'drift'))->clear()` で、`drift` の本体 100 行と tag の版 10,798 行が消え、`app` の 4 行と 10 行は残った（`PdoAdapter::doClear()` は `item_id LIKE 'drift%'` で消す）。
