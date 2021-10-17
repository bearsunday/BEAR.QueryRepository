## 前提


* 1つのURIはバージョンの違う複数のETagを持つ
* ETagにはURIの依存性が与えられる
  * ETagはURIのサロゲートキーで保存される
  * URIの内容変更があればURIのサロゲートキーで消去する
* 消去はURIタグでのみ行う
* ドーナッツは自身のタグが無効化された時だけ無効になる。ドーナッツに依存性はない。

## Case 1 (item)

`/a` が `/b`を含む場合

サロゲートキー


| URI | Etag | SurrogateKeys |
|---|----|---|
|/a | /a-etag |  \_b_ |
|/b | /b-etag |   |


タグと消去対象

| タグ | 消去対象 |
| --- | --- |
| /a | /a-view /a-etag |
| /b | /b-view /b-etag /a-view /a-etag |

| 消去対象 | タグ  |
| --- | --- | 
| /a-view  | /a  /b |
| /a-etag  | /a  /b |
| /b-view | /b |
| /b-etag | /b |

## Case 2 (list)

`/blogPosting` が `/comments`を含む場合。`/comments`は複数で不定の`/comment{?id}`を含む

| URI | Etag | SurrogateKeys |
|---|----|---|
|/blogPosting | /blogPosting-etag |  \_comments\_ |
|/comments | /comments-etag |  \_comments\_id\_1  \_comments\_id\_2 ....  |
|/comment?id={n} | /comment\_id\_{n}-etag |   |

タグと消去対象

| タグ | 消去対象 |
| --- | --- |
| /blogPosting | /blogPosting-view /blogPosting-etag |
| /comments | /comments-view /comments-etag /blogPosting-view /blogPosting-etag |
| /comment?d=1 |   /comment?d=1-view  /comment?d=1-etag /comments-view /comments-etag /blogPosting-view /blogPosting-etag |

