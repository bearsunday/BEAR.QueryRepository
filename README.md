# BEAR.QueryRepository

**BEAR.QueryRepository** segregates reads(GET) and writes(POST, UPDATE..) into two separate repository.

Updating cache entry is not trigger by TTL but non-GET request like `PATCH` or `DELETE`.
`@QueryRepository` annotated class resource works as cache using `QueryRepository` on `GET` request.

`QueryRepository` resource has meta information in the header just like HTTP cache.

クラスに`@QueryRepository`とアノテートしたリソースは`GET`リクエストに読み込み専用のレポジトリ(`QueryRepository`)が使われるようになりキャッシュとして機能します。
`QueryRepository`のエントリーは指定時間で更新が行われるのではなく、`PATCH`や`DELETE`など**GET**以外のリソースアクセス時に更新が行われます。

`QueryRepository`リソースはコンテンツ更新時にメタ情報が以下のようにHTTPキャッシュと同じフォーマットでヘッダーに記録されます。

 * Etag: 2296077071
 * Last-Modified: Mon, 29 Dec 2014 04:51:43 GMT

### Module install

```php

use BEAR\QueryRepository\QueryRepositoryModule;
use Ray\Di\AbstractModule;

class AppModule extends AbstractModule
{
    protected function configure()
    {
        $this->install(new QueryRepositoryModule('VendorWorld\DemoApp'); // for query storage namespace
    }
}

```
### Usage

## QueryRepository direct access

```php

use BEAR\QueryRepository\QueryRepository

$repository = new QueryRepository(new FilesystemCache($tmpDir));

// save
$repository->put($resourceObject);

// delete
$repository->delete($resourceObject->uri);
$repository->delete(new Uri('app://self/user'));

// load
list($code, $headers, $body) = $repository->get(new Uri('app://self/user'));

```

## Annotate class with @QueryRepository

```php

use BEAR\RepositoryModule\Annotation\QueryRepository;
use BEAR\Resource\ResourceObject;
 
/**
 * @QueryRepository
 */
class User extends ResourceObject
{
    public function onGet($id)
    {
        // invoke on 'GET' or 'PATCH'
    }

     public function onPatch($id, $name)
    {
        // update resource, automatically re-generate the entry in QueryRepository 
    }
}
```

### Demo

    $ php docs/demo/run.php
    
    GET (onGet)  // entry in query repository created.
    *** onGet invoked !
    {"name":"bear","time":1419826028.32}
    
    GET (Repository) // entry in query repository loaded. (no invocation of onGet method)
    {"name":"bear","time":1419826028.32}
    
    UPDATE (Repository entry reloaded) // entry in query repository re-loaded by *UPDATE request*
    *** onGet invoked !
    
    GET (Repository)  // updated entry in query repository loaded. (no invocation of onGet method)
    {"name":"kuma","time":1419826028.3213}!

## Requirements

 * PHP 5.5+
 * bear/resource:~1.0
 
