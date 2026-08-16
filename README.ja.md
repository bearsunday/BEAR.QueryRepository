# BEAR.QueryRepository

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/badges/quality-score.png?b=1.x)](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/?branch=1.x)
[![codecov](https://codecov.io/gh/bearsunday/BEAR.QueryRepository/branch/1.x/graph/badge.svg?token=eh3c9AF4Mr)](https://codecov.io/gh/koriym/BEAR.QueryRepository)
[![Type Coverage](https://shepherd.dev/github/bearsunday/BEAR.QueryRepository/coverage.svg)](https://shepherd.dev/github/bearsunday/BEAR.QueryRepository)
[![Coding Standards](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/coding-standards.yml)
[![Static Analysis](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/static-analysis.yml)
[![Continuous Integration](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/bearsunday/BEAR.QueryRepository/actions/workflows/continuous-integration.yml)

**BEAR.QueryRepository** は、[CQRS](http://martinfowler.com/bliki/CQRS.html) にインスパイアされた分散キャッシュフレームワークで、BEAR.Resource アプリケーションのパフォーマンスとリソース利用効率を最適化します。読み取り（クエリ）と書き込み（コマンド）を分離することで、効率的なキャッシュ管理を実現します。

## 主な機能

- **イベント駆動型キャッシュ無効化**: データ変更時にキャッシュを自動的に無効化し、一貫性を保ちます。
- **依存解決**: リソース間の依存関係を解決し、関連するキャッシュを自動的に更新します。
- **ドーナッツキャッシュ**: 動的コンテンツと静的コンテンツを組み合わせた部分キャッシュを実現します。
- **CDN統合**: FastlyやAkamaiなどのモダンなCDNと連携し、共有キャッシュを効率的に管理します。
- **ETagを利用した条件付きリクエスト**: `304 Not Modified` レスポンスでネットワークリソースを節約します。
- **分散キャッシュサポート**: サーバーサイドキャッシュ（例: Redis、APC）、共有キャッシュ（例: CDN）、クライアントサイドキャッシュに対応します。

## ドキュメント

詳細は [BEAR.Sunday キャッシュマニュアル](http://bearsunday.github.io/manuals/1.0/ja/cache.html) をご覧ください。

- [ログの読み方](docs/reading-the-log.ja.md) — ログに現れる全ての語と、セッションの読み方
- [QueryRepository ログはなぜ全てを記録するのか](docs/why-the-log-records-everything.ja.md) — 設計根拠、コスト実測、既定オフの判断
- [このログは何を証明し、何を証明しないか](docs/what-the-log-proves.ja.md) — ログが答える問い、その答えを正直に保つ機構、そして宣言された境界
