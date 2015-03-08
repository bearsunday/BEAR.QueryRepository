# BEAR.QueryRepository
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/badges/quality-score.png?b=develop)](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/?branch=develop)
[![Code Coverage](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/badges/coverage.png?b=develop)](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/?branch=develop)
[![Build Status](https://travis-ci.org/bearsunday/BEAR.QueryRepository.svg?branch=develop)](https://travis-ci.org/bearsunday/BEAR.QueryRepository)

**BEAR.QueryRepository** segregates reads and writes into two separate repository.

**@QueryRepository** annotated class resource works as cache using `query-only-repository` on GET request. But updating cache entry is triggered by NOT TTL but non-GET request.

Meta information will be add in the header just like HTTP cache.

 * Etag: 2296077071
 * Last-Modified: Mon, 29 Dec 2014 04:51:43 GMT

### Composer install

    $ composer require bear/query-repository:~1.0@dev
 
## Module install

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
## Usage

### Direct access

```php

use BEAR\QueryRepository\QueryRepository

$repository = new QueryRepository(new FilesystemCache($tmpDir));

// save
$repository->put($resourceObject);

// delete
$repository->purge($resourceObject->uri);
$repository->purge(new Uri('app://self/user'));

// load
list($code, $headers, $body) = $repository->get(new Uri('app://self/user'));

```

### @QueryRepository annotation

```php

use BEAR\QueryRepository\Annotation\QueryRepository;
use BEAR\Resource\ResourceObject;
 
/**
 * @QueryRepository
 */
class User extends ResourceObject
{
    public function onGet($id)
    {
        // invoke not only 'GET' but also 'PATCH'
    }

     public function onPatch($id, $name)
    {
        // automatically re-generate the entry in query repository.
    }
}
```

### @Purge / @Reload annotation

```php

use BEAR\QueryRepository\Annotation\Purge;
use BEAR\QueryRepository\Annotation\Reload;

class User extends ResourceObject
{
     /**
     * @Purge(uri="app://self/user/friend?user_id={id}")
     * @Reload(uri="app://self/user/profile?user_id={id}")
     */
     public function onPatch($id, $name)
    {
        // "app://self/user/friend?user_id={id}" entry will be purged.
        // "app://self/user/profile?user_id={id}" entry will be regenerated.
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
 
