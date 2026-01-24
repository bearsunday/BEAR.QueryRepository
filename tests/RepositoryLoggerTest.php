<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

class RepositoryLoggerTest extends TestCase
{
    public function testLog(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('get %s', 1);
        $logger->log('put %s %s', 2, 3);
        $logString = (string) $logger;
        $this->assertSame('get 1
put 2 3', $logString);
    }

    public function testLogWithArrayValue(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('save uri:%s tags:%s', 'app://self/user', ['etag1', 'uri-tag', 'dep-tag']);
        $this->assertSame('save uri:app://self/user tags:etag1 uri-tag dep-tag', (string) $logger);
    }

    public function testLogWithEmptyArray(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('invalidate tags:%s', []);
        $this->assertSame('invalidate tags:', (string) $logger);
    }

    public function testLogWithSingleElementArray(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('tags:%s', ['single-tag']);
        $this->assertSame('tags:single-tag', (string) $logger);
    }

    public function testLogWithMixedParameters(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('uri:%s tags:%s ttl:%s', 'app://self/user', ['tag1', 'tag2'], 3600);
        $this->assertSame('uri:app://self/user tags:tag1 tag2 ttl:3600', (string) $logger);
    }
}
