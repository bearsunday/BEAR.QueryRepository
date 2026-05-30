<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

class RepositoryLoggerTest extends TestCase
{
    public function testLogBasic(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('get', ['id' => 1]);
        $logger->log('put', ['a' => 2, 'b' => 3]);
        $logString = (string) $logger;
        $this->assertSame('{"op":"get","id":1}
{"op":"put","a":2,"b":3}', $logString);
    }

    public function testLogWithArrayValue(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('save-value', [
            'uri' => 'app://self/user',
            'tags' => ['etag1', 'uri-tag', 'dep-tag'],
        ]);
        $this->assertSame(
            '{"op":"save-value","uri":"app://self/user","tags":["etag1","uri-tag","dep-tag"]}',
            (string) $logger,
        );
    }

    public function testLogWithEmptyArray(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('invalidate-etag', ['tags' => []]);
        $this->assertSame('{"op":"invalidate-etag","tags":[]}', (string) $logger);
    }

    public function testLogWithEmptyContext(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('simple-op');
        $this->assertSame('{"op":"simple-op"}', (string) $logger);
    }

    public function testLogWithMixedParameters(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('save-value', [
            'uri' => 'app://self/user',
            'tags' => ['tag1', 'tag2'],
            'ttl' => 3600,
        ]);
        $this->assertSame(
            '{"op":"save-value","uri":"app://self/user","tags":["tag1","tag2"],"ttl":3600}',
            (string) $logger,
        );
    }

    public function testLogWithNullValue(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('save-donut', ['uri' => 'app://self/page', 'sMaxAge' => null]);
        $this->assertSame('{"op":"save-donut","uri":"app://self/page","sMaxAge":null}', (string) $logger);
    }

    public function testReset(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('operation1', ['id' => 1]);
        $logger->log('operation2', ['id' => 2]);
        $this->assertNotEmpty((string) $logger);

        $logger->reset();
        $this->assertSame('', (string) $logger);
    }

    public function testResetAllowsNewLogs(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('old-operation');
        $logger->reset();
        $logger->log('new-operation');

        $this->assertSame('{"op":"new-operation"}', (string) $logger);
    }

    public function testGetLogsReturnsMergedEntriesInOrder(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('cache-miss', ['uri' => 'page://self/user', 'layer' => 'resource']);
        $logger->log('depends-on', ['parent' => 'page://self/user', 'child' => 'app://self/profile', 'childTags' => ['_profile_']]);

        $this->assertSame([
            ['op' => 'cache-miss', 'uri' => 'page://self/user', 'layer' => 'resource'],
            ['op' => 'depends-on', 'parent' => 'page://self/user', 'child' => 'app://self/profile', 'childTags' => ['_profile_']],
        ], $logger->getLogs());
    }

    public function testGetOpsReturnsOperationSequence(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('cache-miss', ['uri' => 'page://self/user', 'layer' => 'resource']);
        $logger->log('put-query-repository', ['uri' => 'page://self/user']);
        $logger->log('cache-hit', ['uri' => 'page://self/user', 'layer' => 'resource']);

        $this->assertSame(['cache-miss', 'put-query-repository', 'cache-hit'], $logger->getOps());
    }

    public function testResetClearsStructuredAccessors(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('cache-hit', ['uri' => 'page://self/user', 'layer' => 'resource']);
        $logger->reset();

        $this->assertSame([], $logger->getLogs());
        $this->assertSame([], $logger->getOps());
    }

    public function testNullRepositoryLoggerIsNoOp(): void
    {
        $logger = new NullRepositoryLogger();
        $logger->log('cache-hit', ['uri' => 'page://self/user', 'layer' => 'resource']);
        $logger->reset();

        $this->assertSame('', (string) $logger);
    }
}
