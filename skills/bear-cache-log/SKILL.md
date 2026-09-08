---
user-invocable: true
name: bear-cache-log
description: BEAR.Sunday のキャッシュ挙動をセマンティックログで観測し、宣言した意図どおりに動いているかを自分で確かめ、食い違えばアプリかライブラリかを切り分けて報告する。Use when user says "キャッシュログ", "cache log", "キャッシュが効いてるか確認", "purgeが効かない", "304が返らない", "キャッシュが古い", "アプリかライブラリか切り分けて", or asks to observe, verify, or debug BEAR.Sunday caching behaviour.
---

# セマンティックキャッシュログ — 観測し、意図と照合し、切り分ける

キャッシュの誤りは沈黙する。**一度も効いていないリソースも、正しい答えを返し続ける**。
テストは緑のままで、遅いだけ、あるいは古いだけになる。見るのはコードではなくログで、順番は決まっている。

```text
1. 導入      記録を on にして、配線が生きていることを実証する
2. 読む      運用の問い ↔ イベントの対応
3. 照合      宣言した意図は、どのイベント列として現れるはずか
4. 切り分け  食い違ったら、アプリの問題かライブラリの問題か
5. 報告      根拠つきで、どちらかを言う
```

**前提**: `bear/query-repository` がセマンティックログを含む版であること
([PR #178](https://github.com/bearsunday/BEAR.QueryRepository/pull/178) 以降)。
未リリースの間は `composer require bear/query-repository:"1.x-dev#<sha>"`。

このファイル自体の導入(コーディングエージェントに読ませる)。vendor から取ると、アプリが使っている版の
スキルが入る:

```bash
mkdir -p .claude/skills && cp -r vendor/bear/query-repository/skills/bear-cache-log .claude/skills/
```

まだ `composer require` していない、あるいは GitHub で読んでいるなら:

```bash
mkdir -p ~/.claude/skills/bear-cache-log && curl -fsSL \
  https://raw.githubusercontent.com/bearsunday/BEAR.QueryRepository/1.x/skills/bear-cache-log/SKILL.md \
  -o ~/.claude/skills/bear-cache-log/SKILL.md
```

## 正典ドキュメント(推測で補完しない)

`docs/` と `demo/` は `.gitattributes` で `export-ignore` — 通常の `composer require` の vendor には**入らない**。
GitHub で読むか `composer reinstall bear/query-repository --prefer-source` で取る。

| 知りたいこと | どこ |
|---|---|
| **語彙全部と読み方(まずこれ)** | [docs/reading-the-log.md](https://github.com/bearsunday/BEAR.QueryRepository/blob/1.x/docs/reading-the-log.md) |
| ログが答える問いと保証の境界 | [docs/what-the-log-proves.md](https://github.com/bearsunday/BEAR.QueryRepository/blob/1.x/docs/what-the-log-proves.md) |
| 設計根拠・コスト実測・既定オフの理由 | [docs/why-the-log-records-everything.md](https://github.com/bearsunday/BEAR.QueryRepository/blob/1.x/docs/why-the-log-records-everything.md) |
| 各 context のスキーマ(28 種) | [docs/schemas/context/](https://github.com/bearsunday/BEAR.QueryRepository/tree/1.x/docs/schemas/context) — 各イベントの `schemaUrl` が正典 |
| 動く実例(自己検証つき) | [demo/](https://github.com/bearsunday/BEAR.QueryRepository/tree/1.x/demo) |

## 1. 導入(記録は既定でオフ)

`QueryRepositoryModule` は `SemanticLoggerInterface` に `NullSemanticLogger` を束縛する。
**何も記録されず、蓄積もせず、flush 責務も発生しない。** 記録するにはモジュールを install する:

```php
// 開発 — 1 リクエスト = 1 ファイル + latest.json
$this->install(new DevQueryRepositoryLogModule($appDir . '/var/log/query-repository', module: new QueryRepositoryModule()));

// 本番 — セッションを積んで flush 時に判定(mutation / 失敗 / サンプルだけ残す)
// 出力先に何が流れるかは、下の最終項(セッションが含む値)を先に読む
$this->install(new ProdQueryRepositoryLogModule('php://stdout', sampleRate: 1000, module: new QueryRepositoryModule()));
```

- **`module:` で包むのが必須**。`install()` は既存束縛を上書きしない(Ray.Di の `Container::merge()` は `+=`)。
  `QueryRepositoryModule` を別に install すると **null のまま無音**になる
- **包んだモジュールは「足す」のではなく「置き換える」**。アプリが既に `QueryRepositoryModule` を持つ
  (BEAR.Package 経由など)のに、上から `module: new QueryRepositoryModule()` を足すと **Ray.Aop の
  pointcut が累積してインターセプタが 2 回走る** — 1 リクエストで参照 2 回・書き込み 2 回、ログには
  同じ URI が自分の中に入れ子で現れる。既存グラフに記録だけ足すなら、包まずに
  `bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)` の 1 本だけを差し替える
- **flush はモジュールの仕事**。`LogSinkInterface`(`ShutdownFlush`)がプロセスごとに 1 回仕込む。
  アプリに書く flush は **0 行**。304 の早期 `exit()` でも未捕捉例外でも記録は残る
- 書き込み先が壊れていても例外にはならない。`error_log()` に報告して黙る(ログは side channel)
- **並行ランタイム**: RoadRunner(`RR_MODE`)や Swoole コルーチン内では sink が arm を拒否し、**記録ごと
  止まる**(理由は `error_log`)。ワーカー常駐の CLI 消費者は検出できないので install しない
- セッションは URI(クエリ文字列込み)・client validator・生の例外文を含む。`LogFileWriter` は 0700/0600
  で作る。scrub や流量制限は `LogWriterInterface` を decorate する

ツリー表示: `vendor/bin/stree var/log/query-repository/latest.json`(ファイル引数。オプションは `--help`)。

**hit と miss は `stree` では読めない。** hit/miss はスコープの**閉じ方**(close の型)で、`stree` は close 行に
context のフィールドしか出さない — 2 つの `get` は `durationMs` でしか違わず、それは判定に使ってはいけない値だ
(§2)。判定は JSON を直接見る:

```bash
jq -r '[.. | objects | select(.type? == "get")] | .[] |
  "\(.context.uri)  ->  \(.close.type)"' var/log/query-repository/latest.json
# app://self/user?id=1  ->  cache_miss
# app://self/user?id=1  ->  cache_hit
```

**空の結果そのものが一次診断になる。** ただしこれが言うのは「この JSON に `get` スコープが無い」までだ。
`#[Cacheable]` なリソースを実際に GET したセッションを見ている、と確かめてはじめて、1 行も返らないことが
インターセプタが織られていない証拠になる(§2)。「ログが読めない」ではなく「宣言が届いていない」と読む。
見ているセッションが違うなら次項。

**ファイルが無い現場もある。** テストやオラクルは `DevQueryRepositoryLogModule` を使わず、
`SemanticLoggerInterface` `#[CacheLog]` に `SafeSemanticLogger`(sink 無し)を束縛して、自分で
`flush()` を受け取るのが普通だ。この形では `var/log/query-repository` は一度も作られない。
観測する側が sink になるので、読み方はファイルではなく戻り値:

```php
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
$resource->get($uri);                       // 操作
$log = $logger->flush();                    // その場で受け取る(以降は次のセッション)
```

`flush()` は 1 回で使い切る。操作ごとに呼べばステップ単位で見られるが、**呼んだ後に前のイベントは
残らない**。`latest.json` を探して「ログが無い」と結論する前に、対象のテストやスクリプトが自分で
束縛していないかを読む。

### 他のパッケージのログと 1 本の木にする

BEAR.EventSourcing の `resource_request` の内側にキャッシュのスコープを入れたい、のような場合は
**同じ logger を 2 つの束縛キー(素のキーと `#[CacheLog]`)が指す**必要がある。Ray.Di の Scope は
束縛キー単位で、`to()` は対象クラスのスコープを継がない — 具象を Singleton にしても、`to()` した
インターフェイスキーは毎回別のインスタンスになる。だから `to()` も `toConstructor()` ではこれはできない。
provider で素のキーの Singleton をそのまま返す:

```php
$this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)
    ->toProvider(SharedCacheLogProvider::class)->in(Scope::SINGLETON);
```

**サービスを `toInstance` で渡さない。** DI が構築を握らなくなるうえ、束縛にオブジェクトが載るので
closure を持つものを渡すと `serialize($injector)` が落ちる(コンパイル済み injector を使い回す構成で止まる)。
点検は型ではなく同一性で:

```php
$injector->getInstance(SemanticLoggerInterface::class)
    === $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
```

**これが `false` だと、キャッシュのイベントだけが静かに消える**(実測: `to()` で束縛したら
`resource_request` は出続け、`get`/`save_value`/`cache_hit` が 1 件も出なくなった。例外は出ない)。
観測系のモジュールは対象より**先に** install する — 後にすると、そのログだけが無音で消える。

依存の伝播はこの 2 本を突き合わせる。`depends_on` の子タグが `save_*` の `tags` に現れていなければ、
そこで途切れている:

```bash
jq -r '[.. | objects | select(.type? | test("^(depends_on|save_value|save_etag|invalidate)$"))] | .[] |
  "\(.type)\t\(.context.uri // "")\t\((.context.tags // .context.childTags // []) | join(","))"' log.json
# depends_on   page://self/dep/parent-a   _dep_child-c_
# save_value   page://self/dep/parent-a   _dep_parent-a_,_dep_child-c_   <- 子タグが乗っている
```

**読むのは今回の実行が書いたものか。** `latest.json` はリクエストごとに入れ替わる。リポジトリに commit
されたサンプル出力(このライブラリなら `demo/logs/*.log`)を判定に使うと、**自分の変更が反映されない結果を
測ることになる**。実行直後の mtime を見る、または実行の stdout をそのまま読む。

スキーマ検証: `vendor/bin/validate-semantic-log.php <log.json> <schema-dir>`
(`--prefer-source` なら `vendor/bear/query-repository/docs/schemas/context`。dist ならスキーマを
アプリ側にコピーしてリポジトリ管理する — スキーマは公開契約なので固定して持つことに意味がある)。

### 配線を点検する

記録が既定でオフなので、**「ログが無い」と「モジュールが入っていない」は出力から区別できない**。
間違った入れ方も無音。だから点検は束縛から解き、最後に 1 リクエストで実証する。
文脈(dev / prod / test / CLI)ごとに injector を作り、ファイル名から推測せずに取る:

```php
$injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);  // Null なら記録なし
$injector->getInstance(LogWriterInterface::class);                         // 実効の書き先
$injector->getInstance(LogSinkInterface::class);                           // flush 境界
```

| 点検 | 捕まえる失敗 |
|---|---|
| `#[CacheLog]` の logger が `SafeSemanticLogger` か | `NullSemanticLogger` のまま = `module:` で包んでいない、または別 install で無音 |
| dev は `LogFileWriter`、prod は `LogStreamWriter`(または `PsrLogWriter`) | 本番に dev 形: 全セッション書き出し・保持方針なし |
| `LogFileWriter` のディレクトリが**専用**か | `keep` を超えると `YYYYMMDD-HHMMSS-*.json` 形の古いファイルを消す。アプリの `var/log` を共有すると同形の無関係ファイルが消える |
| `LogStreamWriter` の先が `php://stdout`/`stderr`/`output` か、プロセスが書けるパスか | wrapper 文字列は構築時に拒否されるが、書けないパスは実行時に `error_log` に出るだけ |
| ホストが 1 プロセス 1 リクエストか | RoadRunner / Swoole コルーチンは sink が拒否。FrankenPHP worker・ReactPHP・Amp・常駐 CLI は**検出されない** — 分類できないホストには勧めず、聞く |
| `SemanticLoggerInterface` が他所で `Scope::SINGLETON` 無しに束縛されていないか | 2 つ目の logger が同じ sink を arm すると flush されない(`error_log` に "a second logger armed an already-armed sink") |
| injector をシリアライズする文脈(compiled app)に `PsrLogWriter` が無いか | closure を持つ Monolog を抱え、コンパイル済みグラフが unserialize できなくなる |

**実証**: 配線の主張で終わらせない。1 リクエスト流して到達を見る。

```bash
php public/index.php get /            # dev: latest.json が更新され get スコープが入る
vendor/bin/stree var/log/query-repository/latest.json
```

到達しなければ `error_log`(FPM なら SAPI のエラーログ)を見る。sink の拒否・二重 arm・書けない先は全部そこ。

## 2. 観測の前提(ここを外すと、測っているものが別物になる)

- **プールは既定で `NullAdapter`**。文脈が何も束縛していなければ 1 件も保存されない。テストで観測するなら
  override module で `AdapterInterface` を `#[ResourceObjectPool]` 付きで `ArrayAdapter` に束縛する(Redis 不要)
- **ETag プールも既定で未束縛**(`TagAwareAdapterInterface` `#[EtagPool]` → `toInstance(null)`)。
  束縛されていなければ `save_etag` は一度も出ず、**304 も返らない**。「304 が返らない」の最初の点検はここ
- **qualifier は `BEAR\RepositoryModule\Annotation\*`**(`CacheLog`, `ResourceObjectPool`, `TagsPool`, `EtagPool`)。
  `BEAR\QueryRepository\Annotation\` にも同名クラスがあり、**間違えても例外は出ない** — 自分の
  `getInstance()` が同じ間違った key で答えるので「束縛は効いている」ように見える。プールの中身を数えて確かめる
  (`ArrayAdapter::getValues()`)
- **override には module を install しない**、`bind` だけにする。storage module を入れると
  `QueryRepositoryModule` が二重になり pointcut が積まれる
- **`final class` はインターセプタを受け取れない**。Ray.Aop はクラスを継承して織るので、`final` なリソースの
  `#[Cacheable]` は静かに無効になり、**ログは miss すら出さず空**になる。検査:

  ```php
  get_class($injector->getInstance($class)) !== $class;  // false なら織られていない
  $injector->getInstance($class)->bindings;               // メソッド名 => 付いたインターセプタ
  ```

- **weave が変わったら `composer clean`**(コンパイル済み DI が残っていると直した後も織られない)
- **時間差で判定しない**。プロセス内では refill も 1ms 台で hit と区別できない。判定は必ずイベントで行う
- **プリセットの実数**は `Expiry`: `short` 60 / `medium` 3600 / `long` 86400 / `never` 31536000 秒。
  アプリが `StorageExpiryModule` で上書きするので、数値を書き写さず injector から `Expiry` を取って
  `getTime()` で解決する

## 3. 読む — 運用の問い ↔ イベント

| 問い | 見るイベント |
|---|---|
| **キャッシュは効いたか** | `get` スコープの **close の型**(`cache_hit` / `cache_miss`)。`stree` は出さないので上の `jq` で見る |
| このレスポンスは保存されたか、何秒、どのキーで | `save_value` / `save_view` / `save_etag` / `save_donut` / `save_donut_view` の `{tags, ttl, saved}` |
| CDN に何を何秒キャッシュさせたか | `cdn_headers` の `headers`(応答の literal ヘッダ。**setter の既定値もここに出る**)。lifetime ヘッダが無い = CDN はキャッシュしない |
| purge は何を対象にし、効いたか | `invalidate` の `{tags, roPool, etagPool, cdn}`。`cdn` は `purged`/`failed`/`skipped` の三値、fail-closed |
| エッジの再検証(304)はヒットしたか | `conditional_request` スコープが `cache_hit{layer: etag}` で閉じる = リソースを走らせず 304 |
| なぜ保存されていないのか | `put_skipped` の `{reason, code}`(`etag-present` / `error-code` / `not-cacheable`) |
| miss は空だったのか、店が読めなかったのか | 同じスコープに `cache_error{operation: read}` があれば縮退。無ければコールド。**本番では読み取りだけのセッションは残らない**ので、この切り分けは開発時のもの |
| 誰が書いた/消したか | `command` スコープの `source`。直接呼び出しは `manual_store`/`manual_purge`/`manual_invalidate`。`pre_write_cleanup` 直後の `invalidate` は**書き込み前掃除であり実無効化ではない** |
| 依存の伝播 | `depends_on` と、`save_*` の `tags` ↔ `invalidate` の `tags` の突き合わせ |
| hit/miss はどの層か | close の `layer`: `resource`(値キャッシュ) / `donut` / `donut-view` / `etag`(304 判定)の 4 値 |
| `put_skipped` が出ている | **close が `cache_hit` のスコープの `put_skipped` は正常**(既に保存済みのものを再保存しない)。欠陥として読むのは `cache_miss` で閉じたスコープに出たときだけ |

## 4. 意図と実際を照合する

宣言は意図の表明でしかない。**効いている証拠はイベント列**。下表は `1.x`(`5abe573`)で `demo/run*.php` と
`tests/Fake/fake-app/src/Resource/Page/Mx` の fixture を実行して得た列 = **その版で期待される形**。
手元の出力がこれと違えば、それが所見であって表の誤りではない。

| 宣言 | コールド read | 2 回目 | 自分への書き込み |
|---|---|---|---|
| `#[Cacheable]` | close `cache_miss`。`cache_policy{expiry, resolvedTtl}` → `save_etag` → `save_value` | close `cache_hit`、イベント 0 件 | `command{source: CommandInterceptor}` に `purge` → `invalidate`、続いて**入れ子の `get` で再生成**(`RefreshSameCommand`)。close は `command_result` |
| `#[CacheableResponse]` | close `cache_miss{layer: donut}`。`put_donut` → `cdn_headers` → **`save_etag`** → `save_donut_view` → `save_donut`(全体を保存し ETag を持つ = 304 が返る) | close `cache_hit`、イベント 0 件。埋め込んだ子が無効化された回だけ `refresh_donut` → `save_etag` → `save_donut_view` | `#[Purge]`/`#[Refresh]`/`#[RefreshCache]` のいずれでも `command_result [purge, invalidate]`、次の read は `cache_miss`。**1.16.2 以前は `#[RefreshCache]` だけ書き込みが実行されない**(下の注) |
| `#[DonutCache]` | close `cache_miss`。`put_donut` → `cdn_headers` → `save_donut`。**`save_etag` は出ない** — 全体を保存しないので ETag も無く、304 は返らない | close `cache_hit` + `refresh_donut` → `put_skipped{not-cacheable}`。毎回 donut を組み直すのが正常 | 書き込み側に `#[Purge]`/`#[Refresh]`/`#[RefreshCache]` を書かないと、`command` も `invalidate` も出ない |
| `#[Embed]` した子を持つ親 | `depends_on` が出て、`save_*` の `tags` に子の URI タグが入る | close `cache_hit` | 子を purge → 親の次の read が `cache_miss` |
| `#[Refresh]` / `#[Purge]` | — | — | `command` スコープに `purge` + `invalidate`。`pre_write_cleanup` 隣接**だけ**なら誰にも告げていない |
| `#[HttpCache]` | `cdn_headers` に literal ヘッダ | 同じ | — |

**1.16.2 以前は、書き込みメソッドに `#[RefreshCache]` を付けるとキャッシュが温まっている間その書き込みが
実行されない。** `DonutCacheModule` が `#[RefreshCache]` に **query 用の `DonutCacheInterceptor`** を束縛し、
それは `donutRepository->get()` が当たった時点で `proceed()` を呼ばずにキャッシュ済み表現を返す(HTTP
メソッドを見ていない)。実測: 先に GET してから DELETE すると `onDelete` の本体は **0 回**、応答は宣言した
204 ではなく **200 + キャッシュ済みの表現**。GET せずに DELETE すれば 1 回走る。

[#215](https://github.com/bearsunday/BEAR.QueryRepository/pull/215) で修正済み(次のリリースに入る)。
古い版を使っているなら書き込みは `#[Purge]` / `#[Refresh]` で宣言する。なお `#[Cacheable]` クラスでは
`#[RefreshCache]` は何も足さない — `CommandInterceptor` が既に purge して再生成する。

読み間違えやすい 3 点(いずれも実測):

1. **コールドの `get` にも `pre_write_cleanup` → `invalidate` が出る。** 保存の前に自分の古いエントリを
   消しているだけで、無効化ではない
2. **`cache_miss` は close の型としてもイベントとしても現れる**(donut は内側の層でも出す)。
   「miss のイベントがある」は「そのスコープが miss で閉じた」ではない
3. **`#[Cacheable]` は自分の書き込みでは落ちるが、他所の書き込みでは落ちない。** 他のリソースが自分の
   タグを announce しない限り、TTL が唯一の eviction になる

食い違いの読み方: **期待した `save_*` が無い**なら宣言が届いていない(§2 の 3 点を先に潰す)。
**`invalidate` があるのに `cache_miss` にならない**ならタグが交わっていない(下記)。

### 依存が効いているかを確かめる手順

「親が子を埋め込んでいるから、子を purge すれば親も落ちる」は**コードを読んでも分からない**:

1. 親をコールドで読む。`save_*` の `tags` に**子の URI タグが入っている**ことを見る(入っていなければ依存は
   記録されていない — `#[Embed]` ではなく値をコピーしている疑い)
2. 子の URI を purge する(`QueryRepositoryInterface::purge(new Uri(...))`)
3. 親をもう一度読む。**`cache_miss` で閉じれば依存は生きている**。`cache_hit` なら親は古い子を抱えている

`depends_on` は 1 の裏付け、2→3 が実証。効果だけ(値が変わった)で判定すると、TTL で落ちただけの場合と
区別できない。

**入れ子の `get` スコープは依存の証拠にならない。** 親のスコープの中に子のスコープが現れるのは、
親の実行中に子を読んだという事実だけを言う。`ResourceInterface` を注入して手で読んでも同じ形になり、
`depends_on` もタグ伝播も起きない。見るのは入れ子ではなく `depends_on` と `tags`。

**`#[Embed]` を付けただけでは足りない。** 依存を登録するのは `QueryRepository::setCacheDependency()` で、
保存時に **`$ro->body` に `AbstractRequest` のインスタンスが残っているものだけ**を辿る。埋め込んだ値を
スカラーに解決して body を作り直すと、その時点で Request は body から消えており、依存は登録されない。
body に Request(または ResourceObject)を残すか、announce 側で解決する。

### タグと TTL のどちらが要るかは、ログでは決まらない

タグだけ宣言しても、**そのタグを announce する書き込み経路がある値**しか新しく保てない。body に他所の値を
コピーしていて announce 経路が無ければ、タグは届かず TTL だけが床になる。コードに問う:

1. この body に入る値ごとに、**それを変える書き込み経路**は何か
2. その経路は invalidate を呼ぶか(呼ばないなら TTL が唯一の eviction)
3. 同じデータの**別の複製**があるなら、複製の寿命は原本以下か(長いと、原本が捨てた値を複製が返す)

3 は attribute から機械的に検査できる。実例:
[BeMart `tests/Resource/AgentCorpusCacheTest.php`](https://github.com/be-framework/BeMart/blob/1.x/tests/Resource/AgentCorpusCacheTest.php)

## 5. 切り分け — アプリの問題か、ライブラリの問題か

ログは「何が起きたか」を言うが、「誰の落ち度か」は言わない。**先に観測で候補を 1 つに絞り、
それから該当行の変数を見る**。順番を逆にすると、写しを覗くだけで終わる。

| 観測 | アプリ側 | ライブラリ側 | 決め手 |
|---|---|---|---|
| ログが空(miss すら無い) | `final`(`ReflectionClass::isFinal()` が true)、属性そのものが無い、文脈が店を束縛していない | sink が arm を拒否 | 織られたか(`get_class`)→ 真なら `isFinal()` と属性の有無で二分 / `error_log` |
| 期待した `save_*` が無い | `put_skipped` の `reason` がアプリ由来(自前 ETag・非 200) | `put_skipped` も無いのに保存されない | `put_skipped` の有無と `reason` |
| `save_*` の `tags` に子が無い | 値をコピーしている(`#[Embed]` でない) | `depends_on` はあるのにタグが乗らない = 伝播の欠陥(`CacheDependency::depends()` が親の `Surrogate-Key` に子タグを積む) | `depends_on` イベントの有無 |
| 書き込みが `invalidate` を出さない | 書き込み経路に `#[Refresh]`/`#[Purge]` が無い | 属性はあるのにマッチャがそのメソッドを拾わない | 織られたオブジェクトの `bindings` にそのメソッドがあるか |
| `invalidate` は出るが親が hit のまま | タグの選び方が違う(URI タグと共有サロゲートキーの混同) | タグ集合の交差計算の欠陥 | 2 つのタグ集合を並べて交わりを見る |
| `invalidate` のタグがどのリソースの宣言とも一致しない | **手書きの `invalidateTags()` が定数からドリフトしている** — リソースは `SURROGATE_KEY` 定数を宣言し、無効化側は生文字列を持ったまま取り残された | — | `invalidate` の `tags` を、リソースが宣言する定数の実値と 1 文字ずつ突き合わせる。docblock だけ正しいことがある |
| 値が古い | TTL が床(設計判断) | — | `save_*` の `ttl` |
| `cache_error` / `pool_error` | 店の設定・接続 | 縮退の扱い | 例外か縮退かは `docs/what-the-log-proves.md` |

**「ライブラリ側」に振った候補が 1 つ残ったときだけ**、該当クラスの分岐を観測する。道具は
`koriym/xdebug-mcp`(`composer global require koriym/xdebug-mcp`)。**php 本体に Xdebug が入っていなくてよい**
— ラッパーが自分で読み込む。**vendor は既定で記録から除外される**ので、明示して入れる:

```bash
# 1) どの分岐まで届いているか(--include-vendor が無いとライブラリのフレームは空)
xtrace --include-vendor="bear/query-repository" --context="tags absent from parent save" -- php repro.php

# 2) 判断している行を名前で探す(行番号は版ごとに動く)
grep -n "SURROGATE_KEY" vendor/bear/query-repository/src/CacheDependency.php

# 3) その行で実引数を見る
xstep --break="vendor/bear/query-repository/src/CacheDependency.php:<line>" \
  --steps=20 --context="what tags arrive" -- php repro.php
```

`xtrace` はスクリプトの stdout をそのまま流し、**トレース自体は `/tmp/trace.*.xt` に書いて**末尾に場所と
規模を出す(実測: 1 リクエストで 63688 行 / 534 関数)。読むのはそのファイルで、全部は読まない —
`--context` に何を見たいかを書き、ファイルは目的の関数名で `grep` する。

**`xstep` の前に、ライブラリの既存テストを 1 本走らせる。** 同じシナリオを守るテストが既に赤なら、
切り分けはそこで終わる(実測: `--filter testMultipleParentsDependOnSameChild tests/CacheDependencyTest.php`
が伝播の欠陥をそのまま赤で再現した)。スイート全体は走らせない。

判断点の在処: `CacheInterceptor`(hit/miss と保存)、`CommandInterceptor`(書き込み後の無効化)、
`QueryRepository`(put/get/purge)、`ResourceStorage`(タグと 2 プール)、`CacheDependency`(依存の伝播)、
`DonutRepository`(donut)、`HttpCache` / `CliHttpCache`(304 判定)、`EtagSetter`(ETag 生成)。

**`var_dump` / `printf` を仕込まない。** 使い捨てスクリプトに同じロジックを書き写して覗くのは、対象ではなく
写しを見ている(写し間違いに気づけない)。`bin/repro.php` は**対象を呼ぶだけ**にする。

## 6. 報告

切り分けの結論は、次の 6 つで書く。1 つでも欠けたら、まだ報告できていない:

1. **どちらの問題か** — アプリ / ライブラリ / 設計判断のどれか
2. **観測の根拠** — イベント列の抜粋(`save_value{tags: [...]}` → `invalidate{tags: [...]}` の形)と、
   ライブラリを疑うなら `xstep` で見た実引数。「トレースした」と書くのは実際にツールを使ったときだけ
3. **最小再現** — 対象を呼ぶだけのスクリプトかテスト 1 本
4. **ユーザーが何を感じるか** — 「在庫が最大 60 秒古い」のように、外から見える言葉で
5. **修正の証明** — 直したなら、**どのイベントがどう変わったかをログで示す**。テストが緑になったことは
   証明ではない(テストは同じ盲点を持ち得る)
6. **既存の検証はこれを検出できたか** — テスト・オラクル・CI を実際に走らせて答える。緑だったなら
   **その検証がアプリのコードパスを通っているか**を確かめる。ライブラリの同じ能力を直接叩いて
   代替検証しているだけなら、アプリ側の誤りは原理的に見えない(実例: オラクルが `purgeTags` を
   `ResourceStorageInterface::invalidateTags()` に直接渡すと、アプリの無効化サービスを一度も通らない)

**ライブラリだと言うなら、ライブラリの fixture で落ちるテストを添える。添えられないなら、
まだアプリ側の疑いに戻す。**

## 7. トラブルシュート定型

- **「キャッシュが効いていない」**: 対象 URI の `get` スコープ。`cache_hit` で閉じていれば効いている。
  `put_skipped{etag-present}` なら自前 ETag、`put_skipped{error-code}` なら非 200
- **「purge したのに古い」**: `invalidate` の `tags` に対象キーがあるか。`cdn: skipped` は purger 未設定
  (ローカルだけ消えて CDN に残る)、`cdn: failed` なら例外が伝播しているはず(fail-closed)
- **「CDN が長く持ちすぎる」**: `cdn_headers.headers` の lifetime ヘッダ。`sMaxAge` 未指定の既定値
  (generic 10 秒 / Fastly・Akamai 31536000 秒)が原因のことが多い
- **「304 が返らない」**: `conditional_request` が `cache_miss{layer: etag}` で閉じていれば検証子が古い。
  スコープ自体が無ければ `If-None-Match` が届いていない(プロキシ・FW を疑う)
- **「何も効いていない(毎回リソースが走り、ETag も `Cache-Control` も付かない)」**: 部分的な失敗ではない。
  `get` スコープが 0 件なら織られていない(§2)。属性を消したのと同じ状態で、キャッシュ以外は正常に動く

## 範囲外(ログに聞いても無駄)

CDN 側の実挙動(伝播遅延・エッジ側 eviction)、`#[HttpCache]` の静的ヘッダ設定、独自ヘッダ名のカスタム
setter、実時間での期限消滅。詳細は `what-the-log-proves.md` の "What the log does not record"。
