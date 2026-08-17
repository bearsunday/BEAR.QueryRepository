# このログは何を証明し、何を証明しないか

セマンティックキャッシュログの主張は一つです。**このパッケージのソースコードを持たない、ログだけの読者が、以下の運用上の問いに答えられる。** 本ドキュメントは、それぞれの問い、答えとなるイベント、そしてその答えを正直に保つ機構を述べます。最後に、宣言された境界 — ログが記録しないこと、そしてその理由 — を示します。

ここでいう「証明する」とは、答えが真になるその場所で記録され、公開された JSON Schema に対して検証され、テストスイートがそれなしにはパスしない実行可能なデモによって示され、記録を取り除くか意味を反転させると失敗するテスト（ミューテーションテストで検証済み）によって守られている、ということです。

## ログが答える問い

| # | 運用上の問い | 答えとなるもの | 正直に保つ機構 |
|---|---|---|---|
| 1 | このレスポンスは保存されたか — どの有効期間で、どのキーの下に、そして書き込みは成功したか？ | `save_value` / `save_view` / `save_etag` / `save_donut` / `save_donut_view` `{tags, ttl, saved}` | スキーマが `ttl` ≥ 0 を宣言。各ストアは負の有効期間をクランプし、テストが 5 つすべてを走査。`saved` の両方の結果がデモに現れなければならない |
| 2 | CDN は何を、どれだけの期間キャッシュせよと告げられたか？ | すべての donut 書き込みとリフレッシュ時の `cdn_headers` `{headers, surrogateKeys}` — 確定後に読み戻された、実際のレスポンスヘッダそのもの | 各フレーバーの暗黙のデフォルト（generic は `max-age=10`、Fastly/Akamai は `max-age=31536000`）がピン留めされている。これはフレームワークの規則で誤記ではない — タグで無効化できる CDN には 1 年、できない CDN には 10 秒（[キャッシュマニュアル](https://bearsunday.github.io/manuals/1.0/ja/cache.html)）。Akamai の `Surrogate-Key` → `Edge-Cache-Tag` へのリネームも同様にピン留めされている。有効期間ヘッダが記録されていない = そのレスポンスは CDN に有効期間の指示を与えなかった（`putDonut` の種類） |
| 3 | CDN に purge を指示したか — 正確に何を、そしてそれは効いたか？ | `invalidate` `{tags, roPool, etagPool, cdn, durationMs}`。purger は記録されたタグをそのまま受け取る | `cdn` は三状態（`purged` / `failed` / `skipped`）で fail-closed。まずローカルプール、結果を記録、その後に purge の例外が伝播する。プールが 1 つ拒否すれば失敗として報告される |
| 4 | 条件付きリクエストはエッジで再検証されたか？ | layer `etag` で `cache_hit`/`cache_miss` を閉じる `conditional_request` `{ifNoneMatch}` — リソースが 1 つも走る前に下される 304 の判定 | `HttpCacheInterface` の両実装が記録する。どちらかの記録を落とすか結果を入れ替えるとスイートが失敗する |
| 5 | なぜエントリがないのか — 何も保存されていなかったのか(コールド)、それともストアが読めなかったのか(縮退: フレームワークがキャッシュ無しとして振る舞い、リソースを走らせた)？ | `put_skipped` `{reason, code}`、`cache_error` `{operation, exceptionClass}` — `operation: read` が、それでも閉じる `cache_miss` と対になっているものが縮退した読み取り | スキップ理由、失敗した側（`read`/`write`）、throwable のクラスがピン留めされている。`cache_error{read}` + `cache_miss` = 縮退した読み取り、`cache_miss` 単独 = cold |
| 6 | この書き込みまたは無効化を始めたのは誰か — フレームワークか、アプリケーションか？ | `command` スコープは生成元のインターセプター（`source`）を名指す。直接呼び出しは `manual_store` / `manual_purge` / `manual_invalidate` を根とし、結果は close 側に載る。`pre_write_cleanup` は writer 自身のクリーンアップを示す | 例外を投げる書き込みは `manual_store_result{failed}` で閉じる。呼び出し側が例外を捕まえているのにスコープが `stored` で閉じるのはログが嘘をついている状態であり、テストがそれを禁じる |
| 7 | このエントリは期限切れになる設計か、それとも何かが無効化するまで生きる設計か? | `cache_policy` `{expiry, expirySecond, expiryAt, resolvedTtl}` — `#[Cacheable]` の宣言を読む場所で記録 | 3 つの宣言のうち non-null は 1 つだけ、それが決めたもの。TTL ではこれに答えられない — `never` プリセットはアプリが再束縛できる有限の数値に解決するので、イベント駆動のエントリと意図的な 1 年 TTL が同じ寿命として記録される |
| 8 | ストアは応答しているのか、それとも miss はすべて障害なのか? | `pool_error` `{key, operation, error, exceptionClass}` — 拒んだバックエンドについてのアダプタ自身の報告 | `symfony/cache` はアプリに throw しないので、プールにキャッシュログを渡している。死んだストアからの read は他と同じ miss であり、隣の `pool_error` が「落ちている」と「冷たい」を分ける。実際の Redis アダプタを閉じたポートに向けて実演 |

## 強制の層

1. **スキーマ** — すべての context type に公開された JSON Schema がある
   （`docs/schemas/context/`）。テストの flush はすべてそれらに対して検証され、
   diagnostics は失敗として扱われます。ロガーが決して例外を投げなくても、
   ロギングプロトコルの退行はスイートを失敗させます。
2. **自己検証するデモ** — `demo/run.php`、`run-donut.php`、`run-dependency.php`、
   `run-degraded.php` はセッションツリーを出力し、オフラインで検証して、
   違反があれば非ゼロで終了します。
3. **語彙の閉包** — `tests/DemoLogCoverageTest.php` は、すべての context クラス、
   すべてのスキーマの `enum` 値、`saved` の両方の結果、`command.source` の 3 つの
   producer すべてがデモ出力に現れない限り失敗します。存在するがデモされない
   context は出荷できません。
4. **順序のピン留め** — donut 書き込みの正確なイベント順序がアサートされているため、
   emission の追加や削除は意識的でレビューされた変更になります。
5. **ミューテーションによる検証** — 上のピンは、変更されたソースに対してミューテーション
   テストを走らせ、意味のある生存者を殺すことで選ばれました。emission 箇所の削除、
   結果を表す語の反転、デフォルトの変更、境界の拡大。行カバレッジだけでは
   これらを 1 つも捕まえられませんでした。
6. **発生源で記録する** — 効果はそれが確定する場所で記録され、読者が推論することは
   ありません。有効期間はログを取る場所でクランプされ、CDN ヘッダは setter が走った
   後にレスポンスから読み戻され、クリーンアップはそれを実行する writer が記録します。

## ログが記録しないこと

- **告げられたことを CDN が実際にどう扱ったか。** ログが記録するのは、送られたヘッダと
  purger が報告した purge の結果です。エッジへの伝播遅延、eviction、リージョンごとの
  挙動は CDN のものであり、Fastly/Akamai の HTTP API はこのリポジトリのテストでは
  fake で実行されています。
- **`#[HttpCache]` の静的なヘッダ設定。** ランタイム状態を持たないクラス属性で、
  すべてのレスポンスに同一に出力され、repository には触れません。ログが記録するのは
  ランタイムに決まる事実です。
- **独自の CDN ヘッダ名。** `cdn_headers` が扱うのは、このパッケージの setter が管理する
  ヘッダ（`CDN-Cache-Control`、`Surrogate-Control`、`Akamai-Cache-Control`、
  `Surrogate-Key`、`Edge-Cache-Tag`）です。独自のヘッダ名を使うカスタムの
  `CdnCacheControlHeaderSetterInterface` はログの知識の外にあります。
- **実時間での期限切れ。** TTL の計算（クランプ、残り有効期間、`Age`）は経過時間の
  テストではなく、構成上のものとして検証されています。記録された時刻に実際に eviction が
  起きるかはキャッシュバックエンドの契約です。
- **並行するセッション。** ロガーは injector ごとに 1 つのセッションを保持します。sink が
  ホストが並行だと証明できる場合 — RoadRunner のワーカー、あるいは Swoole のコルーチン内
  — sink は `arm` を拒否し、記録もそこで止まります。セッションを drain するものが何もない
  からです。そうしたホストはリクエストスコープの `LogSinkInterface` をバインドするか、
  log モジュールを外します（#179）。検出できないホスト（ロガーが起動時に構築される Swoole
  ワーカー、FrankenPHP の worker モード、ReactPHP、Amp、長命の CLI コンシューマ）は
  運用者の判断です。
- **条件付きリクエストの外での `ResourceStorage::hasEtag()` 呼び出し。** セマンティックな
  イベントは「条件付きリクエストに応答した」ことであり、転送境界（`HttpCache` /
  `CliHttpCache`）が所有します。ストレージへの問い合わせ自体は無言です。
- **保持ポリシーが落としたセッション。** `DevQueryRepositoryLogModule` はすべてのセッションを
  書き出します。`ProdQueryRepositoryLogModule` は他の何ものも説明できないものだけを残すため、
  本番では正常な読み取りは意図的に存在せず、失敗した読み取りも同じです — 読み取りの失敗は
  どれもアプリケーション自身の警告チャンネルに届いているからです（両インターセプタは縮退の前に
  `trigger_error()` を呼びます）。したがって、miss がコールドか縮退かの切り分けは開発時の読みです。

## 読むための手がかり

- 語彙、context ごとに 1 行: [llms-full.txt](https://bearsunday.github.io/BEAR.QueryRepository/llms-full.txt) / [tests/CACHE_DEPENDENCY_TESTS.md](../tests/CACHE_DEPENDENCY_TESTS.md)
- 設計根拠: [why-the-log-records-everything.ja.md](why-the-log-records-everything.ja.md)
- スキーマ: [docs/schemas/context/](schemas/context/)
- 描画: `vendor/bin/stree`（デモがツリー形式を示します）
