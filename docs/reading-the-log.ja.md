# ログの読み方

ログに現れうる語の全部と、セッション 1 本の読み方です。読者は BEAR.Sunday アプリを運用しており、
本パッケージを導入済みであることを前提にします — キャッシュのモデル自体(`#[Cacheable]`、donut
caching、イベント駆動の無効化)は
[キャッシュマニュアル](https://bearsunday.github.io/manuals/1.0/ja/cache.html)が扱います。契約は
[docs/schemas/context/](schemas/context/) の per-context JSON Schema であり、この文書はその読み手向けの
案内です。`docs/llms.txt` と `docs/llms-full.txt` は同じ語彙をエージェント向けの参照表として持ちます
— context を変えたときは両方を更新してください。

記録は既定でオフです。セッションを出すにはログモジュールを install します
([理由](why-the-log-records-everything.ja.md)):

```php
$this->install(new DevQueryRepositoryLogModule($appDir . '/var/log/query-repository', module: new QueryRepositoryModule()));
```

```bash
vendor/bin/stree var/log/query-repository/latest.json   # 直前に走ったリクエストをツリーで表示
```

## 用語

概念そのもの(条件付きリクエスト、ETag、donut caching、サロゲートキー)は
[キャッシュマニュアルの Terminology](https://bearsunday.github.io/manuals/1.0/ja/cache.html) にあります。
この表が扱うのはもっと狭い範囲です — **このログが自分の部品を呼ぶときの名前**です。エントリで見かけた
識別子が、あなたのアプリのどこに対応するかが分かります。

| ログ上の名前 | 何か | アプリのどこで出会うか |
|---|---|---|
| `roPool` | **R**esource **O**bject プール: キャッシュされた body・view・donut テンプレートを保持するストア | `#[ResourceObjectPool]` で束縛するアダプタ |
| `etagPool` | 検証子を ETag をキーに保持するストア | `#[EtagPool]` で束縛するアダプタ |
| `tags` | エントリが保存される無効化の名前空間 — サロゲートキー | `Header::SURROGATE_KEY`、URI をタグ化するなら `UriTagInterface` |
| URI タグ | あるリソースの URI をタグにしたもの。同時にその親が持つサロゲートキーでもある | `($this->uriTag)(new Uri('app://self/foo'))` |
| `etag` | 表現の entity-tag。**キーではなく検証子** | `ETag` レスポンスヘッダ |
| `layer` | どのストアが照会に答えたか。どのプールに書いたかではない | —(ログ固有の軸) |
| donut / donut-view | 穴のあるキャッシュ可能な外殻 / 再合成されたページ | `#[CacheableResponse]`、`#[DonutCache]` |
| `sMaxAge` | その書き込みが CDN に要求した共有キャッシュの寿命 | `DonutRepositoryInterface::put($ro, ttl: …, sMaxAge: …)` |
| スコープ / イベント / close | ツリーの 3 種類のノード。次節で説明 | —(ログ固有の区別) |

`roPool` と `etagPool` は**プールの名前**、`layer` は**照会に答えたストア**です —
`cache_hit{layer: resource}` で閉じる `get` は「リソースストアが答えた」を意味します。`roPool: invalidated`
を含む `invalidate` は「そのストアがタグを落とした」です。前者は無効化の対象、後者は照会の結果です。

## 形

セッションは 1 リクエスト = 1 ツリーです。入れ子は時系列ではなく、**仕事の構造**です:

```text
get page://self/html/blog-posting          ← スコープ: open されて close される
  get page://self/html/comment             ← 埋め込みの子。親の中に入れ子になる
    save_value {tags, requestedTtl, saved} ← イベント: このスコープの中で起きたこと
    cache_miss {layer: resource}           ← close: スコープがどう終わったか
  put_donut {requestedTtl, sMaxAge}
  cache_hit {layer: donut-view}
```

ノードは 3 種類で、この区別がそのまま文法です:

| 種類 | 意味 | 読み方 |
|---|---|---|
| **open** | 入ったスコープ | 「この仕事が始まった」— 子はその中で起きたこと |
| **event** | 有効なスコープの中で記録された事実 | 「これが起き、結果はこうだった」 |
| **close** | スコープの終わり方 | そのスコープの判定。一語で出る |

同じ型が位置を変えて出ることがあり、どちらかは `layer` を見れば分かります。`cache_hit`/`cache_miss` が
**イベント**なら内側の照会(`donut` — donut テンプレートを `DonutRepository` が引いた)、**close** なら
そのスコープ自身の答え(`#[Cacheable]` 経路なら `resource`、donut ページなら `donut-view`、条件付き
リクエストなら `etag`)です。つまり 1 つのスコープが miss を含みつつ、別の層の miss で閉じることがあります。

## スコープ (open/close)

| 型 | 何が open するか | close する語 |
|---|---|---|
| `get` (`uri`) | キャッシュ経由のリソース GET | `cache_hit` / `cache_miss` |
| `command` (`method`, `annotations`, `source`) | 書き込み (`onPut`/`onPatch`/`onDelete`) | `command_result` (`code`) |
| `conditional_request` (`ifNoneMatch`) | 転送境界での `If-None-Match` 判定 | `layer: etag` の `cache_hit` / `cache_miss` |
| `manual_store` (`uri`) | 直接の `put()` / `putStatic()` / `putDonut()` | `manual_store_result` (`stored` \| `failed`) |
| `manual_purge` (`uri`) | 直接の `purge()` | `manual_purge_result` (`purged` \| `failed`) |
| `manual_invalidate` (`tags`) | 直接の `invalidateTags()` | `manual_invalidate_result` (`invalidated` \| `failed`) |

`manual_*` は「**アプリが起点**」を意味します。その呼び出しの外側に、フレームワークのスコープが
ありませんでした。同じ操作が GET やコマンドの中で起きた場合は、そこの通常のイベントになります。

## イベント

| 型 | フィールド | 何が分かるか |
|---|---|---|
| `cache_policy` | `uri`, `expiry`, `expirySecond`, `expiryAt`, `resolvedTtl` | リソースが宣言した寿命と、それが解決した値 |
| `save_value` | `uri`, `tags`, `requestedTtl`, `saved` | body をプールに渡した |
| `save_view` | `uri`, `tags`, `requestedTtl`, `saved` | body + レンダリング済み view を渡した |
| `save_etag` | `uri`, `etag`, `tags`, `requestedTtl`, `saved` | 検証子を ETag プールに渡した |
| `save_donut` | `uri`, `tags`, `requestedTtl`, `saved` | donut テンプレートを渡した |
| `save_donut_view` | `uri`, `tags`, `requestedTtl`, `saved` | 再合成した donut view を渡した |
| `put_donut` | `uri`, `requestedTtl`, `sMaxAge` | donut の書き込みを要求した。要求時の lifetime つき |
| `refresh_donut` | `uri` | キャッシュ済み donut をそのまま返さず再合成した |
| `cdn_headers` | `uri`, `headers`, `surrogateKeys` | 応答に実際に付いた CDN 向けヘッダ |
| `depends_on` | `parent`, `child`, `childTags` | 依存の辺 1 本。子のタグが親に加わった |
| `pre_write_cleanup` | `uri` | 書き込み側が、上書きするエントリを消す直前 |
| `invalidate` | `tags`, `roPool`, `etagPool`, `cdn`, `durationMs` | タグを無効化した。対象ごとの結果つき |
| `purge` | `uri` | URI 指定の破棄を要求した |
| `put_skipped` | `uri`, `reason`, `code` | miss の後に書き込みを**しなかった**ことと、その理由 |
| `cache_hit` / `cache_miss` | `layer`, `durationMs` | 内側の照会。close context では `resource`、`donut-view`、`etag` のいずれかで `durationMs` が入る。`layer: donut` の inner event では `durationMs` は null — donut テンプレートがあったかの判定だけ |
| `cache_error` | `uri`, `operation`, `error`, `exceptionClass` | キャッシュ経路が throw した |
| `pool_error` | `key`, `operation`, `error`, `exceptionClass` | バックエンドが操作を拒み、アダプタが握り潰した |
| `semantic_logger_error` | `kind`, `message`, … | ロガー自体の誤用(コア側の診断で、このパッケージの語彙ではない) |

## 結果が入るフィールド

結果はいずれも、語だけで意味が分かります。bool は `saved` だけ:

| フィールド | 値 | 読み方 |
|---|---|---|
| `layer` | `resource` \| `donut` \| `donut-view` \| `etag` | どのストアに尋ねたか。`resource` = `#[Cacheable]` の値/ビューストア、`donut` = donut テンプレート(イベントとしてのみ出る)、`donut-view` = 再合成された donut ページ、`etag` = 条件付きリクエストが引く ETag プール |
| `saved` | `true` \| `false` | **`false` = プールが書き込みを拒否した。** これを記録するものは他に無い |
| `roPool` / `etagPool` | `invalidated` \| `failed` | プールごとの無効化結果 |
| `cdn` | `purged` \| `failed` \| `skipped` | `skipped` は purger 未設定 (`NullPurger`)。「やることが無かった」ではない |
| `operation` | `read` \| `write` | キャッシュのどちら側が throw したか |
| `reason` (`put_skipped`) | `etag-present` \| `error-code` \| `not-cacheable` | 書き込みが起きなかった理由。`etag-present` = リソースが既に ETag を持っていたので donut 層は手を出さなかった、`not-cacheable` = テンプレートから再描画された donut ページ(ページとしては保存しない)、`error-code` は応答の `code` を伴い閾値は経路で違う: `#[Cacheable]` は 200 以外すべて(`203` もここに出る)、donut は 4xx 以上 |
| `result` (`manual_*`) | `stored`/`purged`/`invalidated` \| `failed` | 直接呼び出しの結果 |
| `requestedTtl` | 秒 | このパッケージがストアに要求した保持時間。`31536000` は `never` の慣習値、`0`/`null` は「期限を要求しなかった」— バックエンドが上書きし得る(下の規則) |
| `sMaxAge` (`put_donut`) | 秒 | その書き込みが要求した共有キャッシュ(CDN)の寿命 — `DonutRepositoryInterface::put($ro, ttl: …, sMaxAge: …)` に渡すのと同じ引数で、エントリ自身の `requestedTtl` とは別物。`null` は未要求で、`putDonut` は常に `null` を記録する |
| `code` (`put_skipped`) | HTTP ステータス | `reason` が `error-code` のときだけ入る。他の 2 理由では `null` |

## 読解規則

フィールド名からは推測できないものだけを挙げます。

**miss の後に書き込みが無いときは、`put_skipped` にその理由が書かれています。** 保存を禁じる
規則が働いたからです。その経路がキャッシュしない応答コード、既に立っている検証子、テンプレートから
返した donut ページ — どれだったかがイベントに入っています。

**非200のdonut書き込みには `save_etag` が無く、`cdn_headers` に寿命も入りません。** donutの
閾値は2xxと3xxを通しますが、検証子は200にしか作られないため、保存された `204` や `301` には
保存すべきETagがありません。200では `save_etag` に続いて出る `save_donut_view` と `save_donut` が、
非200ではその `save_etag` 抜きで並びます。CDNの寿命も同じ規則で、与えられるのは200だけです。
保存された非200の `cdn_headers` にはパージキーだけが載り、`CDN-Cache-Control` は載りません。
どちらの欠落も書き込みの失敗ではありません。

**`cache_miss` は「照会がエントリを得られなかった」を示し、原因は 4 通りあります。** miss 自身は
どの原因かを示しません:

- **何も保存されていなかった** — コールド。正常な状態で、自然に治ります(次のリクエストは hit)
- **ストアが読めなかった** — 同じスコープに `cache_error{operation: read}` が出ます。
  読み取りが throw し、フレームワークはリソースを走らせて応答を作りました。これが本ページでの
  **縮退**の意味です — キャッシュが無いものとして振る舞った
- **書き込みが失敗した** — 同じスコープの `cache_error{operation: write}`。リソースは走り、
  書き込みの試みが throw した
- **規則が書き込みを禁じた** — `put_skipped` に理由が出ます(エラーコード、既存の ETag、
  キャッシュしない応答)

`durationMs` に書き込みが含まれるのは、コールド(単独)の miss と書き込み失敗の miss だけです。
縮退 miss はリソース実行のみ、put_skipped はリソース実行と非 200 が引き起こす purge を測ります。

コールドと縮退は挙動が正反対なので、分ける価値があります。コールドは自然に治りますが、読めない状態は
プールが壊れている間ずっと**全リクエスト**が origin の費用を払い、しかも応答は常に正しいので、
遅くなること以外に症状が出ません。

**この切り分けは開発時に使えます。** 本番では保持ポリシーが読み取りだけのセッションを —
コールドでも失敗でも — 落とします。縮退した miss を数えるなら、アプリの警告チャンネル
(`trigger_error`)を見てください — 読み取りの失敗はそこに届いています。別の理由で残ったセッション
(コマンド、起きなかった効果)の中に入れ子になった miss は、本番でも見えます。

**`invalidate` が pre-write cleanup であるのは、同じスコープの直前のイベントが `pre_write_cleanup`
マーカーであるとき、かつそのときだけです。** 書き込み側は上書きするエントリを先に消すので、本物の破棄と
見た目が同じになります。マーカーは発生源で記録されるため、タグの相関からの推測は一切ありません。
マーカーの無い `invalidate` は本物の無効化です。

**依存が正しいかは集合の交差で決まります。** `save_*` の `tags` と、後の `invalidate` の `tags` を
突き合わせます。交差しないタグは、その書き込みがそのエントリを残したことを意味します — これが
内側から見た「stale を配信している」状態です。

**エントリが期限切れになる設計かどうかは、TTL ではなく `cache_policy.expiry` を読みます。**
`expiry: "never"` は「無効化が届くまで」という意図です。解決した数値は保険であり、アプリが `Expiry` を
どう束縛したかで変わります — 既定のインストールでは `never` が 31536000 秒になり、意図的な 1 年 TTL と
まったく同じに見えます。`expirySecond` か `expiryAt` が non-null 側なら、そのエントリは期限切れになり、
どの宣言が決めたかも分かります。

**`requestedTtl` は要求した値で、ストアがどうしたかではありません。** `0`/`null` は「このパッケージは
期限を設定しなかった」— つまり無効化が届くまで生きるはず、という意図です。それが可能かはバックエンドが
決めます。`symfony/cache` の `RedisTagAwareAdapter` は期限なしのタグ付きエントリに 8640000 秒(100 日)を
与えます — Redis はタグ集合を期限切れにできないからです。実効寿命はデプロイ側の事実なので、ストアで
読んでください(Redis なら `TTL <key>`)。

**コマンド注釈が届くのは URI で、タグではありません。** `#[Refresh]` と `#[Purge]` が持つのは URI です。
エントリ群が 1 つの無効化ハンドル(エントリを生んだクエリ文字列すべてに渡る corpus タグ)を共有している
リソースは `invalidateTags()` を呼んで無効化し、それは `command` スコープの中ではなく `manual_invalidate`
として現れます。リソースメソッドの外で起きる書き込みにはインターセプタが一切かかりません。その形では
直接呼び出しが唯一の道であり、manual スコープがそのイベントを見える場所に留めています。

**close の `durationMs` は「その答えにかかった時間」で、hit と miss の対だけが「このキャッシュに価値が
あるか」を言えます。** hit の close はプールから配る時間、miss の close はリソース実行とそれが起こした
書き込みの時間です。つまり `miss - hit` は「節約できた量」ではありません(fill を含む)。意味があるのは
符号です — **hit が miss より速くないキャッシュは、金を払って何も買っていない**。大きなエントリに対する
圧縮 marshaller、遅いタグ検索、ネットワーク越しのプールは、内側からはこう見えます。これは計測値であって
契約ではありません(マシン・プール・ペイロードで動きます)。スコープを持たない event は測る区間がないので
null です。

**`pool_error` はストアそのもの、`cache_error` はこのパッケージが捕まえた例外です。**
`symfony/cache` のアダプタはアプリに向けて throw しません。到達できないストアは read には miss、
write には `false` を返すので、`cache_error` を生む `catch` には何も届きません。アダプタは代わりに
失敗を PSR-3 ロガーへ報告し、プールにはキャッシュログが渡されています — だからストアが落ちているとき、
miss の隣に `pool_error` が並びます(沈黙にはなりません)。そこで分かるのはプールのキーだけで、
リソース URI ではありません。

**`cdn_headers` に出るのは、応答に実際に付いたヘッダです。** CDN モジュールの暗黙の既定値も含みます。
lifetime ヘッダの無いマップは、CDN に lifetime 指示を与えなかった応答です。`surrogateKeys` と
`invalidate` の `tags` を突き合わせると、パージがエッジの保持物に届き得たかが分かります。

**`cache_hit{layer: etag}` で閉じる `conditional_request` が 304 です** — リソースを走らせずに
ETag プールだけでリクエスト全体に答えています。304 が現れるのはここだけです。
アプリがどこで判定したかで、問いが変わります。`isNotModified($server)` はルーティング前に走るので
「その検証子がどこかで生きているか」しか訊けません — 別 URI で受け取った検証子でも通ります。
`isNotModifiedFor($uri, $server)` は「要求されたリソースに対して発行された検証子か」を訊きます
(RFC 9110 §13.1.2 の問い)。先にルーティングすれば手に入り、その代価はパス照合であってリソース実行
ではありません。

**donut の `cache_hit` に出るのは最終層です。** ページがキャッシュから来たのか、出力の途中で
再合成されたのかはスコープの中にあります — `refresh_donut` イベントがあれば再合成です。

## 実例

`demo/run-dependency.php` の出力そのままです — 他の 2 リソースが埋め込んでいるリソースへの PUT:

```text
command {"method": "onPut", "annotations": [], "source": "CommandInterceptor"}
  get {"uri": "page://self/dep/level-three"}
    pre_write_cleanup {"uri": "page://self/dep/level-three"}
    invalidate {"tags": ["_dep_level-three_"], "roPool": "invalidated", "etagPool": "invalidated", "cdn": "skipped"}
    save_etag {"uri": "page://self/dep/level-three", "tags": ["_dep_level-three_"], "requestedTtl": 31536000, "saved": true}
    save_value {"uri": "page://self/dep/level-three", "tags": ["_dep_level-three_"], "requestedTtl": 31536000, "saved": true}
    cache_miss {"layer": "resource"}
  purge {"uri": "page://self/dep/level-three"}
  invalidate {"tags": ["_dep_level-three_"], "roPool": "invalidated", "etagPool": "invalidated", "cdn": "skipped"}
  command_result {"code": 200}
```

上から順に読みます:

1. 書き込みが走った: `onPut`、駆動は `CommandInterceptor`。`annotations` が空なので
   `#[Refresh]`/`#[Purge]` 属性が対象を選んだのではなく、コマンドが自分の URI を refresh した。
   属性が対象を選んでいる場合、各要素は
   `{"class": "…\\Annotation\\Refresh", "uri": "app://self/refresh-dest{?id}"}` の形になる —
   class がどの属性か、uri がその対象で、`{?id}` はコマンド自身の引数から埋まる URI テンプレート。
2. 入れ子の `get` がその refresh で、それ自体が 1 つのスコープ。リソースは実際に走り
   (`cache_miss{layer: resource}`)、保存された(`saved: true` が 2 つ — body と検証子)。
3. **内側**の `invalidate` は同じ URI の `pre_write_cleanup` の直後にある。これは書き手が
   これから書き換えるエントリを消しているだけで、破棄ではない。
4. **外側**の `invalidate` には直前のマーカーが無いので、本物の無効化。そのタグは子の URI タグであり、
   それは同時に 2 つの親が保存されたときの surrogate key でもある — **この 1 イベントが、埋め込んでいる
   側を stale にしている**。
5. `cdn: skipped` が 2 つ: ここでは purger が未設定。CDN 配下のアプリなら `purged` と出て、
   `failed` ならローカルのプールは消えたがエッジは消えていないことを意味する。
6. `command_result{code: 200}` で閉じる。ここが 4xx で上に `invalidate` が無ければ、
   失敗した書き込みが正しく何も破棄していない記録になる。

## セッションを特定する

セッションは**時刻も request id も持ちません**。`LogJson` は `$schema` / `open` / `close` / `events` /
`links` だけです。1 人の顧客のリクエストとセッションを突き合わせるのは、ホストの仕事です:

| | セッションを特定するもの |
|---|---|
| `DevQueryRepositoryLogModule` | ファイル名(UTC、マイクロ秒まで: `20260816-120000-123456.json`)と、直前のリクエストを指す `latest.json` |
| `ProdQueryRepositoryLogModule` | 行の中には何も無い。収集基盤が行に付ける時刻が唯一の時計で、join できる request id は無い |
| request id が必要なら | `LogWriterInterface` を decorate して行に足す — マスキングや流量制限と同じ seam |

**本番のログに入っているのは、保持ポリシーが残したものだけです。** 正常な読み取りと read 側の障害は
落とされるので、存在しないセッションは「落とされた」のか「起きなかった」のか区別できません。言えるのは
残ったカテゴリについての肯定形だけです — 「このタグを無効化した」は主張できますが、「無効化は走らなかった」
は主張できません。開発時は全セッションが書かれるので、そこでは「無い」は「コードが動かないと決めた」を
意味します — 「沈黙する経路を作らない」が成立するのはそちらです。

## どこへ書かれるか

| | 出力先 | ポリシー |
|---|---|---|
| `DevQueryRepositoryLogModule` | 1 セッション = 1 ファイル + `latest.json` | 全部 |
| `ProdQueryRepositoryLogModule` | 1 セッション = 1 行の JSON を `php://stdout` またはファイルへ | mutation、起きなかった効果、任意のサンプル |
| | `sampleRate: N` は正常セッションを N 本に 1 本残す(`0` で無効)。保持されるセッションの実測は中央値 3.9 KB、最大 21 KB(デモ全体)。純ヒットは 698 B | |
| | PHP-FPM では `php://stdout` はプールの出力に届くのに `catch_workers_output = yes` が必要。コンテナならそのまま収集基盤へ。転送・ローテーション・保管期間はホストの責務 | |
| `PsrLogWriter` | アプリの PSR-3 ロガー。ツリーは `context['log']` に入る | 包んだ writer のポリシー |

セッションは URI(**クエリ文字列込み**)、クライアントの `If-None-Match`、キャッシュタグ、CDN ヘッダの値、
生の例外文を含みます。書き出されたセッションはアプリケーションログと同じ扱いにしてください:
`LogFileWriter` はディレクトリを 0700、ファイルを 0600 で作ります。マスキングや流量制限が必要なら
`LogWriterInterface` を decorate します — それがその seam であり、プール障害時(全リクエストが
`cache_error` を出し、保持率が上がる)への答えでもあります。

## 参照

- 保証とその境界: [what-the-log-proves.ja.md](what-the-log-proves.ja.md)
- なぜ全部記録するのか、コストはいくらか: [why-the-log-records-everything.ja.md](why-the-log-records-everything.ja.md)
- per-context スキーマ: [schemas/context/](schemas/context/)
- 自己検証デモ: `demo/run.php`, `demo/run-donut.php`, `demo/run-dependency.php`, `demo/run-degraded.php`
