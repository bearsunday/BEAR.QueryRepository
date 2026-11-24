# probe/ — キャッシュの保存先の調べ

BEAR.QueryRepository（BQR）のキャッシュを、どこに、どう置くのがよいかを手元で測った記録。この枝（`study-cache-storage-backends`）は merge しない。`upstream/1.x` の 7a76681e の上に、この `probe/` と確かめ用の page を足しただけで、`src/` は変えていない。

- 数字と結論: [`results.md`](results.md)（1〜13 節）
- 測ったスクリプト: この directory の `*.php`。1 本が results.md の 1 つの節に対応する（下の表）

## 問い

1. page 全体の HTML を Redis に持てるか。必要な量はどれだけか
2. `RedisTagAwareAdapter`（今の `StorageRedisDsnModule`）と `TagAwareAdapter` のどちらがよいか
3. Redis cluster は要るか。分散で速くなるか
4. デプロイのたびに溜まる鍵をどう片付けるか
5. Redis の代わりに DB（`PdoAdapter`）を pool にできるか。donut cache で DB がネックにならないか
6. origin に保存しない page にも `Surrogate-Key` を付け、書き込みから CDN を purge できるか

## 結論の要点

- 量は「page の数 × 約 13KB」（deflate、tag 100 本）。deflate なしでは約 64KB。`StorageRedisDsnModule` の `marshallingOptions: ['enabled' => true, 'type' => 'deflate']` で本体が約 1/5 になる（[5](results.md#s5)・[6](results.md#s6)）
- 溜まり続ける主な原因は、namespace を替えた後に残る古い namespace。`RedisTagAwareAdapter` の tag の SET は TTL がなく、volatile-* では追い出されない。古い namespace に `clear()` を当てれば消える。消すのは、古い版のサーバーがいなくなった後（[6](results.md#s6)・[9](results.md#s9)）
- 同じ namespace の中でも、tag が新しい行へ移る page（新着の一覧など）では、tag の SET が「これまでに載った行の数」だけ増える（[10](results.md#s10)）
- cluster は容量の面では要らない。性能の面で効くのは、単体の Redis の CPU か処理の回数が頭打ちのときだけ。読みは速くならず（どちらも 1 往復）、保存は 1 回 102 往復になる（単体は 2 往復）（[7](results.md#s7)・[8](results.md#s8)）
- `AdapterInterface #[ResourceObjectPool]` を `PdoAdapter` に束縛するだけで、DB だけで動く。新しい型は要らない。読みの往復は倍になるが、手元の差は 1 リクエスト 0.03〜0.07ms（[12](results.md#s12)・[13](results.md#s13)）
- origin に保存しない page に `Surrogate-Key` を付ける試作は効いた（[4](results.md#s4)）。実装はまだしていない

## スクリプトと節の対応

| スクリプト | 節 | 使うもの |
|---|---|---|
| `adapters.php` | [1](results.md#s1) | Redis 6379 |
| `headers.php`、`children.php` | [2](results.md#s2) | なし（テスト用の `ModuleFactory` が束縛する `ArrayAdapter`。プロセスの中だけ） |
| `pool-error.php` | [3](results.md#s3) | Redis 6379。maxmemory-policy を allkeys-lru と volatile-lru に替えて 2 回流した（替えるのはスクリプトの外で `redis-cli config set`） |
| `cdn-only.php` | [4](results.md#s4) | なし（`ArrayAdapter`）。先に試作の差分を当てる（下） |
| `pdo.php`、`redis-deflate.php`、`redis-module.php` | [5](results.md#s5) | MySQL 3407 か SQLite、Redis 6379 |
| `redis-breakdown.php` | [6](results.md#s6) | Redis 6379 |
| `cluster.php`、`roundtrips.php` | [7](results.md#s7) | Redis 6379 と cluster 7001〜7003 |
| `throughput.php` | [8](results.md#s8) | Redis 6379 と cluster 7001〜7003 |
| `clear-impact.php` | [9](results.md#s9) | Redis 6379 |
| `tag-drift.php` | [10](results.md#s10) | Redis 6379 |
| `clear-namespace.php` | [11](results.md#s11) | Redis 6379 と cluster 7001〜7003 |
| `bqr-pdo.php` | [12](results.md#s12) | Redis 6379 と MySQL 3407 |
| `pdo-library.php` | [13](results.md#s13) | SQLite（自分で作って消すファイル） |

引数を取るスクリプトは、頭のコメントに usage がある。

## 動かし方

```bash
composer install
```

Redis か MySQL に触るスクリプトは、Redis を `flushAll` したり、その設定を変えたり、MySQL の表を作り直したりする。捨ててよい Redis と MySQL を立て、`PROBE_ALLOW_FLUSH=1` を付けて実行する。付けないと `guard.php` が止める。

```bash
# Redis（単体）
redis-server --port 6379 --save "" --appendonly no --maxmemory-policy volatile-lru --daemonize yes

# Redis cluster（3 台、replica なし）。作業用の directory で
for p in 7001 7002 7003; do mkdir -p cluster/$p && (cd cluster/$p && redis-server --port $p --cluster-enabled yes --cluster-config-file nodes.conf --save "" --appendonly no --maxmemory-policy volatile-lru --daemonize yes); done
echo yes | redis-cli --cluster create 127.0.0.1:7001 127.0.0.1:7002 127.0.0.1:7003 --cluster-replicas 0

# MySQL（3407。datadir は捨ててよい場所に）
mysqld --no-defaults --initialize-insecure --datadir="$PWD/mysql-data" --innodb-redo-log-capacity=8M
mysqld --no-defaults --datadir="$PWD/mysql-data" --port=3407 --bind-address=127.0.0.1 --socket="$PWD/mysql.sock" --mysqlx=OFF --skip-log-bin --innodb-redo-log-capacity=8M &
mysql -h 127.0.0.1 -P 3407 -u root -e "CREATE DATABASE cache"

# 例
PROBE_ALLOW_FLUSH=1 php probe/redis-breakdown.php
```

止めるときは `redis-cli -p <port> shutdown nosave`、`mysqladmin -h 127.0.0.1 -P 3407 -u root shutdown`。

### 4 節の試作（`cdn-only.php`）

`src/` には当てていない。測るときだけ当てて、終わったら戻す。interceptor のコンストラクタが変わるので、AOP の生成物も消す。

```bash
git apply probe/prototype-http-cache-interceptor.diff
rm -rf tests/tmp/*
php probe/cdn-only.php
git checkout -- src/HttpCacheInterceptor.php
rm -rf tests/tmp/*
```

確かめ用の page（`tests/Fake/fake-app/src/Resource/Page/Html/Probe*.php` とテンプレート）は 2 節と 4 節で使う。

## 読むときの注意

- 数字はどれも 1 台の手元の機械で測った。ネットワークの往復は入っておらず、cluster の 3 台も同じ CPU を分け合っている。そのため、ms や回数/秒そのものは本番の値ではない。本番にそのまま持っていけるのは、構成で決まる数と正しさのほう。たとえば保存 1 回の往復は、単体で 2、cluster で 102（7 節）。往復の数は機械によらないので、本番ではネットワークの往復の時間を掛けた分だけ差が開く
- 測っていないことは、results.md の各節に「測っていない」「推測」と書いてある
- 数字を足したり直したりするときは、その数字を出したスクリプトも同じ commit に入れる
