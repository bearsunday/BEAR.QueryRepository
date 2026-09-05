# キャッシュ追跡可能性レポート — 次期リリース（1.16.2 → Unreleased）

2026-08-19 にマージされた 7 PR（#191, #192, #193, #194, #195, #196, #198）を含む Unreleased セクション全体について、キャッシュの「追跡可能性（traceability）」と「明示性（explicitness）」がどれだけ高まったかをまとめる。

## 1. 前版（1.16.2）で観測不可能だったもの

旧ログはフラットな op-string 形式（`RepositoryLoggerInterface`）で、以下の故障・判断クラスが記録されず、ログ読者には区別が付かなかった。

| # | 沈黙していた事態 | 前版での見え方 |
|---|---|---|
| 1 | キャッシュバックエンドの停止（Redis/Memcached 不接続） | 普通のミスの連続と同一（symfony/cache アダプタは投げずに miss/false を返す） |
| 2 | プールが書き込みを拒否（save が false を返した） | 成功した save と同一 |
| 3 | CDN パージが未設定（NullPurger） | 実際にパージされた `purged` と同一 |
| 4 | CDN パージの失敗 | ローカル無効化だけが起き、CDN は stale のまま・記録なし |
| 5 | value エントリの保存がレンダリングに依存し、renderer が例外を投げると保存が警告に劣化（#193） | キャッシュが空のまま・理由の記録なし |
| 6 | `Surrogate-Key` 非宣言ページの donut テンプレートが無タグで保存され、どの `invalidateTags()` も届かない（#194） | purge が content と validator を落として不死の shell を残す・記録なし |
| 7 | 独自 `Surrogate-Key` 宣言で埋め込み依存の追跡が失われる 1.16.0 退行（#195） | purge 済みの子を配信し続ける・記録なし |
| 8 | ログが記録した TTL と実際に保存された TTL の不一致（負の TTL をそのまま記録）、`sMaxAge` という誤ラベル | ログがストアの事実と矛盾 |
| 9 | リソースが宣言した寿命（preset / expirySecond / expiryAt のどれが決めたか）（#196） | 解決後の数値だけでは `never`（無効化まで生きる意図）と意図的な 1 年 TTL が区別できない |
| 10 | 304 判断そのもの（ETag プールだけでリクエスト全体に回答） | どの `get` スコープにも現れない |
| 11 | 書き込み・無効化の発起者がフレームワークかアプリか | 区別不可 |
| 12 | アプリ自身の `SemanticLoggerInterface` 束縛の奪取（#191） | install 順次第で静かに入れ替わる |

## 2. 後版（次期リリース）での可視化

| # | 事態 | 記録するコンテキスト |
|---|---|---|
| 1 | ストア停止 | `pool_error {key, operation, error, exceptionClass}` — アダプタ自身の報告をそのまま運ぶ |
| 2 | 書き込み拒否 | 全 5 save コンテキストの `saved` フィールド（accept/reject） |
| 3 | CDN 未設定 | `invalidate.cdn = skipped`（tri-state: `purged` / `failed` / `skipped`） |
| 4 | CDN パージ失敗 | `invalidate {cdn: failed}` + fail-closed（ローカル無効化 → 記録 → 送出。コマンドや直接呼び出しでは呼び出し元に届く） |
| 5 | レンダリングなし保存 | 修正済み。value パスは `$ro->view === null` で body にフォールバックし、ETag は body を追う |
| 6 | 無タグテンプレート | 修正済み。テンプレートは自 URI タグで保存され `purge($uri)` が届く |
| 7 | 依存追跡の喪失 | 修正済み。宣言キーと埋め込み依存が併存し、`depends_on` で追跡が記録される |
| 8 | TTL の矛盾 | 修正済み。要求値は記録時点でクランプ、フィールド名は `requestedTtl`（要求した値であり、ストアの実効寿命ではない） |
| 9 | 寿命の宣言 | `cache_policy {expiry, expirySecond, expiryAt, resolvedTtl}` — 3 宣言のうち決め手だけが記録され、`resolvedTtl` と読み比べられる |
| 10 | 304 判断 | `conditional_request {ifNoneMatch}` が `cache_hit/cache_miss{layer: etag}` で閉じる |
| 11 | 発起者 | `command.source`（interceptor 名）と `manual_store` / `manual_purge` / `manual_invalidate` スコープ（結果は close に） |
| 12 | 束縛の奪取 | `#[CacheLog]` 修飾子で分離。アプリの束縛は無修飾のまま生きる |

## 3. 明示性を支える機構（量）

- **28 の型付きコンテキスト**（`src/Log/Context/`）、それぞれに公開 JSON Schema（`docs/schemas/context/`、28 ファイル、うち 9 が enum を持つ）
- **木構造 = 依存構造**: open/event/close の入れ子がそのまま embed/依存の構造。親の子は親の下にぶら下がる
- **ソース記録の原則**: 効果が確定した場所で記録する（lifetime はクランプした場所で、CDN ヘッダは setter 適用後に読み戻し、cleanup は実行者が `pre_write_cleanup` でマーク）
- **unknown ≠ absent**: 判別できないものは推測せず `unknown` と記録（`operation` のフォールバック等）

## 4. 主張を守る強制レイヤ

1. **スキーマ検証**: テストの全 flush が公開スキーマに照合され、diagnostics も fail 扱い（`failOnDiagnostics`）。ロガーが投げなくてもプロトコル退行はテストが落とす
2. **自己検証デモ**: 4 スクリプト（`run.php` / `run-dependency.php` / `run-donut.php` / `run-degraded.php`）がセッションツリーと JSON を出力し、オフライン照合で違反時は非ゼロ終了
3. **語彙閉包**: `DemoLogCoverageTest`（6 tests / 22 assertions）が、全コンテキストクラス・全スキーマ enum 値・全 save コンテキストの `saved` 両結果・全 `command.source` 生成元がデモ出力に現れることを要求。デモされないコンテキストは出荷できない
4. **シーケンス pin**: donut 書き込みのイベント順序そのものを assert。発火の増減はレビュー対象の変更になる
5. **ミューテーション検証**: 発火地点の削除・結果語の反転・既定値の変更を殺す pin を mutation testing で選定

## 5. 実測エビデンス

- 全スイート **283 tests / 812 assertions 緑**（1.x 先端 `283badd`）
- 修正系 PR の pin テストは旧コードで FAIL を確認済み（バグの再現性を検証してから修正）:
  - #193: 旧コードで `FakeThrowingRenderer` に到達し warning 化、ETag が body を追わない
  - #194: 旧コードで `save_donut` が `"tags":[]` を記録（無タグ = 無効化不能をイベントレベルで実測）
  - #195: 旧コードで宣言キーと埋め込み依存の併存が崩れる 2 テストが FAIL
  - #191: 旧コードでアプリの logger 束縛が奪われる FAIL、`__unserialize` 復元後の全書き込みが uninitialized property で Error
- デモログを `demo/logs/` に収録（4 ファイル計 5,602 行、全デモ exit 0・オフラインスキーマ照合通過）
- マルチ環境: ext-redis / Predis、POSIX / Windows でのメッセージ差異をポータブルな契約 assert に整理

## 6. 宣言する境界（ログが記録しないもの）

- CDN 自体の挙動（エッジが実際に保持・パージしたか）はログの外。記録するのは「CDN に何を送ったか」まで
- Memcached の `pool_error` は実機サーバなしの閉ポートテストでは Redis のみで検証。配線は symfony の共通機構
- 記録は既定で off（`NullSemanticLogger`）。健全なセッションに故障エントリは含まれないことを実測済み

## 7. まとめ

前版では「キャッシュが効かない」「古いコンテンツが残る」とき、ログには原因と無関係なミスの列しかなかった。次期リリースでは、保存・参照・無効化・304・CDN・障害の各判断が、それが確定した場所で型付きイベントとして記録され、公開スキーマ・デモ・ミューテーション選定の pin で「ログが嘘をつかない」こと自体がテストされる。沈黙していた 12 の故障・判断クラス（§1）のすべてに記録経路か修正が入った。
