# BEAR.QueryRepository
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/badges/quality-score.png?b=develop)](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/?branch=develop)
[![Code Coverage](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/badges/coverage.png?b=develop)](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/?branch=develop)
[![Build Status](https://travis-ci.org/bearsunday/BEAR.QueryRepository.svg?branch=develop)](https://travis-ci.org/bearsunday/BEAR.QueryRepository)

**BEAR.QueryRepository** segregates reads and writes into two separate repository.

Transparent caching is enabled with **@Cacheable** annotated resource. When you create, update or delete the resource by non-`get` method, cache data is automatically created and stored in **query only repository**. Meta information will be add in the header just like HTTP cache as following.

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


### @Cacheable annotation

```php

use BEAR\QueryRepository\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
 
/**
 * @Cacheable
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

`expiry` option can limit data life time, `short`, `medium`, `long` and `never` are provided.

```php
/**
 * @Cacheable(expiry="short")
 */
```

You can customize the expiry time with `Expiry`.
   
```php
this->install(new QueryRepositoryModule('VendorWorld\DemoApp', new Expiry(60, 60*60, 24*60*60)); // for query storage namespace
```

### @Purge / @Refresh annotation

You can `purge` or `refresh` entity value in query repository with `@Purge` or `@Refresh` annotation.

```php
use BEAR\QueryRepository\Annotation\Purge;
use BEAR\QueryRepository\Annotation\Refresh;

class User extends ResourceObject
{
     /**
     * @Purge(uri="app://self/user/friend?user_id={id}")
     * @Refresh(uri="app://self/user/profile?user_id={id}")
     */
     public function onPatch($id, $name)
```

### Direct access

You can manually access query repository with `QueryRepository` object.

```php

use BEAR\QueryRepository\QueryRepository
use BEAR\Resource\Uri;

class AdminTool
{
    private $queryRepository;
    
    public function __construct(QueryRepositoryInterface $queryRepository)
    {
        $this->queryRepository = $queryRepository;
    }
    
    public function onPost()
    {
        // purge resource
        $this->queryRepository->purge(new Uri('app://self/ad/?id={id}', ['id' => 1]));
        
        // save
        $this->queryRepository->put($this);
        $this->queryRepository->put($resourceObject);

        // delete
        $repository->purge($resourceObject->uri);
        $repository->purge(new Uri('app://self/user'));
        
        // load
        list($code, $headers, $body) = $repository->get(new Uri('app://self/user'));
    }
}
```

### Demo

    $ php docs/demo/run.php
    
GET
onGet invoked
200{"name":"bear","rnd":59}

GET
200{"name":"bear","rnd":59}

PATCH
onGet invoked
200{"name":"kuma","rnd":81}

GET
200{"name":"kuma","rnd":81}

GET
200{"name":"kuma","rnd":81}

## Requirements

 * PHP 5.5+
 * bear/resource:~1.0
 
