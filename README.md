# BEAR.QueryRepository
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/badges/quality-score.png?b=develop)](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/?branch=develop)
[![Code Coverage](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/badges/coverage.png?b=develop)](https://scrutinizer-ci.com/g/bearsunday/BEAR.QueryRepository/?branch=develop)
[![Build Status](https://travis-ci.org/bearsunday/BEAR.QueryRepository.svg?branch=develop)](https://travis-ci.org/bearsunday/BEAR.QueryRepository)

[CQRS](http://martinfowler.com/bliki/CQRS.html)-inspired **BEAR.QueryRepository** segregates reads and writes into two separate repository.

Transparent caching is enabled with **@Cacheable** annotated resource. When you `post`, `put`, `patch` or `delete` the resource, data is automatically stored **Query only repository**. It can be work as a normal cache with `expiry` time. You can also treat it as permanent query only data storage without `expiry`.

Meta information will be add in the header just like HTTP cache as following.

 * Etag: 2296077071
 * Last-Modified: Mon, 29 Dec 2014 04:51:43 GMT

## Composer install

    $ composer require bear/query-repository:~1.0@dev
 
## Module install

```php

use BEAR\QueryRepository\Expiry;
use BEAR\QueryRepository\QueryRepositoryModule;
use BEAR\RepositoryModule\Annotation\ExpiryConfig;
use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\Module\ResourceModule;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

class AppModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        // Query repository engine
        $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->to(ArrayCache::class)->in(Scope::SINGLETON);
        // Cache time
        list($short, $medium, $long) = [60, 3600, 24 * 3600];
        $this->bind()->annotatedWith(ExpiryConfig::class)->toInstance(new Expiry($short, $medium, $long));

        $this->install(new ResourceModule(__NAMESPACE__));
        $this->install(new QueryRepositoryModule);
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

You can configure expiry time with `ExpiryConfig` binding.
   
```php
// Cache time
list($short, $medium, $long) = [60, 3600, 24 * 3600];
$this->bind()->annotatedWith(ExpiryConfig::class)->toInstance(new Expiry($short, $medium, $long));
storage namespace
```

Also `Storage` for cache storage engine. 

```php
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\ApcCache;
use BEAR\RepositoryModule\Annotation\Storage;
use Ray\Di\Scope;

// ...

$this->bind(CacheProvider::class)->annotatedWith(Storage::class)->to(ApcCache::class)->in(Scope::SINGLETON);
```
When you have multiple web server, shared storage engine like [MemcacheCache](http://doctrine-orm.readthedocs.org/en/latest/reference/caching.html#memcache) or [Redis](http://doctrine-orm.readthedocs.org/en/latest/reference/caching.html#redis) are required.

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

## Demo

```
php docs/demo/run.php
    
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
```

## Requirements

 * PHP 5.5+
 * bear/resource:~1.0
 
