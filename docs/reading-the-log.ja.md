# ログの読み方

ログに現れうる語の全部と、セッション 1 本の読み方です。契約は
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

## 形

セッションは 1 リクエスト = 1 ツリーです。入れ子は時系列ではなく、**仕事の構造**です:

```text
get page://self/html/blog-posting          ← スコープ: open されて close される
  get page://self/html/comment             ← 埋め込みの子。親の中に入れ子になる
    save_value {tags, ttl, saved}          ← イベント: このスコープの中で起きたこと
    cache_miss {layer: resource}           ← close: スコープがどう終わったか
  put_donut {ttl, sMaxAge}
  cache_hit {layer: donut-view}
```

ノードは 3 種類で、この区別がそのまま文法です:

| 種類 | 意味 | 読み方 |
|---|---|---|
| **open** | 入ったスコープ | 「この仕事が始まった」— 子はその中で起きたこと |
| **event** | 有効なスコープの中で記録された事実 | 「これが起き、結果はこうだった」 |
| **close** | スコープの終わり方 | そのスコープの判定。一語で出る |

## スコープ (open/close)

| 型 | 何が open するか | close する語 |
|---|---|---|
| `get` (`uri`) | キャッシュ経由のリソース GET | `cache_hit` / `cache_miss` |
| `command` (`method`, `annotations`, `source`) | 書き込み (`onPut`/`onPatch`/`onDelete`) | `command_result` (`code`) |
| `conditional_request` (`ifNoneMatch`) | 転送境界での `If-None-Match` 判定 | `layer: etag` の `cache_hit` / `cache_miss` |
| `manual_store` (`uri`) | 直接の `put()` / `putStatic()` / `putDonut()` | `manual_store_result` (`stored` \| `failed`) |
| `manual_purge` (`uri`) | 直接の `purge()` | `manual_purge_result` (`purged` \| `failed`) |
| `manual_invalidate` (`tags`) | 直接の `invalidateTags()` | `manual_invalidate_result` (`invalidated` \| `failed`) |

`manual_*` は「**アプリが起点**」を意味します — その呼び出しの外側にフレームワークのスコープが無かった、
ということです。同じ操作が GET やコマンドの中で起きた場合は、そこの通常のイベントになります。

## イベント

| 型 | フィールド | 何が分かるか |
|---|---|---|
| `save_value` | `uri`, `tags`, `ttl`, `saved` | body をプールに渡した |
| `save_view` | `uri`, `tags`, `ttl`, `saved` | body + レンダリング済み view を渡した |
| `save_etag` | `uri`, `etag`, `tags`, `ttl`, `saved` | 検証子を ETag プールに渡した |
| `save_donut` | `uri`, `tags`, `ttl`, `saved` | donut テンプレートを渡した |
| `save_donut_view` | `uri`, `tags`, `ttl`, `saved` | 再合成した donut view を渡した |
| `put_donut` | `uri`, `ttl`, `sMaxAge` | donut 書き込みを要求した。要求した lifetime つき |
| `refresh_donut` | `uri` | キャッシュ済み donut をそのまま返さず再合成した |
| `cdn_headers` | `uri`, `headers`, `surrogateKeys` | 応答が実際に持っていた CDN 向けヘッダ |
| `depends_on` | `parent`, `child`, `childTags` | 依存の辺 1 本。子のタグが親に加わった |
| `pre_write_cleanup` | `uri` | 書き手がこれから書き換えるエントリを消す直前 |
| `invalidate` | `tags`, `roPool`, `etagPool`, `cdn`, `durationMs` | タグを無効化した。対象ごとの結果つき |
| `purge` | `uri` | URI 指定の破棄を要求した |
| `put_skipped` | `uri`, `reason`, `code` | miss の後に書き込みを**しなかった**、とその理由 |
| `cache_error` | `uri`, `operation`, `error`, `exceptionClass` | キャッシュ経路が throw した |
| `semantic_logger_error` | `kind`, `message`, … | ロガー自体の誤用(コア側の診断で、このパッケージの語彙ではない) |

## 結果を運ぶ語

結果は必ず自己記述的な語です。bool は `saved` だけ:

| フィールド | 値 | 読み方 |
|---|---|---|
| `layer` | `resource` \| `donut` \| `donut-view` \| `etag` | どのストアが答えたか |
| `saved` | `true` \| `false` | **`false` = プールが書き込みを拒否した。** これを記録するものは他に無い |
| `roPool` / `etagPool` | `invalidated` \| `failed` | プールごとの無効化結果 |
| `cdn` | `purged` \| `failed` \| `skipped` | `skipped` は purger 未設定 (`NullPurger`)。「やることが無かった」ではない |
| `operation` | `read` \| `write` | キャッシュのどちら側が throw したか |
| `reason` (`put_skipped`) | `etag-present` \| `error-code` \| `not-cacheable` | 書き込みが起きなかった理由。`error-code` は応答の `code` を伴い、閾値は経路で違う: `#[Cacheable]` は 200 以外すべて(`203` もここに出る)、donut は 4xx 以上 |
| `result` (`manual_*`) | `stored`/`purged`/`invalidated` \| `failed` | 直接呼び出しの結果 |
| `ttl` | 秒 | `31536000` は `never` の慣習値。`0`/`null` は期限未設定 |

## 読解規則

フィールド名からは推測できないものだけを挙げます。

**`save_*` が無い miss は、失われた書き込みではありません。** `put_skipped` を見てください —
書かなかったことが意図的だったと、理由つきで記録されています。

**`cache_error` + `cache_miss` は縮退であって、コールドではありません。** `cache_miss` 単独は
エントリが無かったこと。組で出ていればプールが失敗し、それでもリソースが走ったことを意味します。

**`invalidate` が pre-write cleanup であるのは、同じスコープの直前のイベントが `pre_write_cleanup`
マーカーであるとき、かつそのときだけです。** 書き手はこれから書き換えるエントリを消すので、本物の破棄と
見た目が同じになります。マーカーは発生源で記録されるため、タグの相関からの推測は一切ありません。
マーカーの無い `invalidate` は本物の無効化です。

**依存が正しいかは集合の交差で決まります。** `save_*` の `tags` と、後の `invalidate` の `tags` を
突き合わせます。交差していなければ、その書き込みはそのエントリを破棄していません — これが内側から見た
「stale を配信している」状態です。

**`cdn_headers` は応答が実際に持っていたものを示します。** CDN モジュールの暗黙の既定値も含みます。
lifetime ヘッダがマップに無い = その応答は CDN に lifetime 指示を与えなかった、という意味です。
`surrogateKeys` と `invalidate` の `tags` を突き合わせると、パージがエッジの保持物に届き得たかが分かります。

**`cache_hit{layer: etag}` で閉じる `conditional_request` が 304 です** — リソースを走らせずに
ETag プールだけでリクエスト全体に答えた、ということ。`get` スコープではこれを示せません。

**donut の `cache_hit` は、キャッシュから返したのか再合成したのかを区別しません。** close は最終層の
結果だけを報告します。スコープ内の `refresh_donut` を見てください。

## 実例

`demo/run-dependency.php` の出力そのままです — 他の 2 リソースが埋め込んでいるリソースへの PUT:

```text
command {"method": "onPut", "annotations": [], "source": "CommandInterceptor"}
  get {"uri": "page://self/dep/level-three"}
    pre_write_cleanup {"uri": "page://self/dep/level-three"}
    invalidate {"tags": ["_dep_level-three_"], "roPool": "invalidated", "etagPool": "invalidated", "cdn": "skipped"}
    save_etag {"uri": "page://self/dep/level-three", "tags": ["_dep_level-three_"], "ttl": 31536000, "saved": true}
    save_value {"uri": "page://self/dep/level-three", "tags": ["_dep_level-three_"], "ttl": 31536000, "saved": true}
    cache_miss {"layer": "resource"}
  purge {"uri": "page://self/dep/level-three"}
  invalidate {"tags": ["_dep_level-three_"], "roPool": "invalidated", "etagPool": "invalidated", "cdn": "skipped"}
  command_result {"code": 200}
```

上から順に読みます:

1. 書き込みが走った: `onPut`、駆動は `CommandInterceptor`。`annotations` が空なので
   `#[Refresh]`/`#[Purge]` 属性が対象を選んだのではなく、コマンドが自分の URI を refresh した。
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

## どこへ書かれるか

| | 出力先 | ポリシー |
|---|---|---|
| `DevQueryRepositoryLogModule` | 1 セッション = 1 ファイル + `latest.json` | 全部 |
| `ProdQueryRepositoryLogModule` | 1 セッション = 1 行の JSON を `php://stdout` またはファイルへ | mutation、起きなかった効果、任意のサンプル |
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
