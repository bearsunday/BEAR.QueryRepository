# Redis の大きさの見積もり

BQR のキャッシュを Redis（`StorageRedisDsnModule`、`RedisTagAwareAdapter`）に置くときに、どれくらいの大きさが要るかの見積もり方。数字の根拠は [`results.md`](results.md) の各節。

```
Redis に入る page の数 ≈（データに使える量 − 追い出されない分）÷ page 1 つの本体
```

## 1. page 1 つの本体（追い出される）

| page の種類 | Redis に置くもの | 50KB の HTML のとき（圧縮あり / なし） |
|---|---|---|
| `#[CacheableResponse]` | テンプレートと、組み上がった page の 2 つ | 約 25KB / 約 130KB |
| `#[DonutCache]` | テンプレートだけ（応答に CDN の寿命と `ETag` は付かない） | 約 13KB / 約 67KB |

- 量は、page の HTML の大きさにほぼ比例する（[16 節](results.md#s16)）。圧縮は `StorageRedisDsnModule` の `marshallingOptions: ['enabled' => true, 'type' => 'deflate']`。
- page には期限が付く（`RedisTagAwareAdapter` の既定の 100 日）。`#[Cacheable]` の値は宣言どおり（既定の `never` は 1 年）。そのため `maxmemory-policy` が `volatile-lru` なら、使われない page から追い出される（[6 節](results.md#s6)）。
- 全ページを置かなくてよい。追い出しに任せれば、Redis に残るのはよく読まれる page で、hit 率はその数で決まる（[1 節](results.md#s1)）。

## 2. 追い出されない分

- **tag のメモ**: 一度でも保存された page の数 × page あたりの tag の数 × 約 30〜60 バイト。要素が 128 以下の SET は約 30 バイト、それより多い SET は約 55〜65 バイト（[17 節](results.md#s17)）。page が追い出されても要素は残るので、今 Redis にある page の数ではなく、一度でも保存された page の数で決まる。
- **古い置き場の残り**: namespace を替えると、前の置き場の page と tag のメモが丸ごと残る。古い版のサーバーがいなくなってから `clear()` を当てれば 0 になる（[6 節](results.md#s6)・[9 節](results.md#s9)）。
- **同じ Redis に置くほかのもの**（session など）。

## 3. ElastiCache の大きさと、入る page の数

`maxmemory` はノードの種類ごとの値（ https://docs.aws.amazon.com/AmazonElastiCache/latest/dg/ParameterGroups.Engine.html ）。同じ文書の `reserved-memory-percent`（既定 25）のとおり、データに使えるのは約 75%。page の数は `#[CacheableResponse]`、50KB の HTML、圧縮あり（25KB / page）で計算し、追い出されない分は引いていない。

| ノード | maxmemory | データに使える量（75%） | 入る page の数 |
|---|---|---|---|
| cache.t4g.micro | 0.5 GiB | 約 0.38 GiB | 約 1.6 万 |
| cache.t4g.small | 約 1.37 GiB | 約 1.03 GiB | 約 4.4 万 |
| cache.t4g.medium | 約 3.09 GiB | 約 2.32 GiB | 約 10 万 |
| cache.m7g.large | 約 6.38 GiB | 約 4.79 GiB | 約 20 万 |
| cache.r7g.large | 約 13.07 GiB | 約 9.80 GiB | 約 42 万 |
| cache.r7g.xlarge | 約 26.32 GiB | 約 19.74 GiB | 約 85 万 |
| cache.r7g.2xlarge | 約 52.82 GiB | 約 39.61 GiB | 約 170 万 |

cluster では、この大きさがシャード 1 つ分で、鍵はほぼ均等に分かれる。ただし保存は 1 回 102 往復になる（[7 節](results.md#s7)）。

## 4. 例（tag 20 本 / page）

| サイトの page 数 | page 本体（全部置くと） | tag のメモ | 全部を置くなら |
|---|---|---|---|
| 1,600 | 約 40MB | 約 1〜2MB | cache.t4g.micro |
| 10 万 | 約 2.5GB | 約 60〜120MB | cache.m7g.large |
| 100 万 | 約 25GB | 約 0.6〜1.2GB | cache.r7g.2xlarge |

全部を置かず追い出しに任せるなら、もっと小さくてよい。そのときは、追い出されない tag のメモが、データに使える量に収まっている必要がある。

## 5. 本番で確かめる

- `INFO memory` の `used_memory`、`maxmemory`、`maxmemory_policy`（`volatile-*` か `noeviction` でないと何も保存されない。[3 節](results.md#s3)）
- `INFO keyspace` の `keys − expires`（期限のない鍵、つまり tag のメモの数）
- `INFO stats` の `keyspace_hits` / `keyspace_misses`（hit 率）と `evicted_keys`（追い出しの回数）
- 置き場ごとの鍵の数（`redis-cli --scan | cut -d: -f1 | sort | uniq -c`。cluster なら各 master で）

## 注意

- 1〜4 の量は、手元で測った 1 page の値の掛け算。大きな規模では測っていない。
- page の HTML の大きさ、page あたりの tag の数、圧縮の効き方は、サイトによって違う。自分のサイトの値は 5 の方法で測る。
