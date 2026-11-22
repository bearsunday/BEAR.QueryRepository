<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

use function array_filter;
use function array_unique;
use function array_values;
use function explode;
use function implode;

/** @internal */
final readonly class TagSet
{
    /** @var list<string> */
    private array $values;

    /**
     * Only '' is dropped - an empty or double-spaced key string splits into one, and the pool
     * rejects it. Every other value and the first-seen order are kept as given.
     *
     * @param list<string> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = array_values(array_unique(array_filter($values, static fn (string $tag): bool => $tag !== '')));
    }

    /** @param array<string, string> $headers */
    public static function ofHeaders(array $headers): self
    {
        return new self(isset($headers[Header::SURROGATE_KEY]) ? explode(' ', $headers[Header::SURROGATE_KEY]) : []);
    }

    /** Keys already in the header stay first. Nothing is written when there is no key at all. */
    public function addTo(ResourceObject $ro): void
    {
        $all = self::ofHeaders($ro->headers)->merge($this);
        if (! $all->isEmpty()) {
            $ro->headers[Header::SURROGATE_KEY] = $all->toHeader();
        }
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return $this->values;
    }

    public function toHeader(): string
    {
        return implode(' ', $this->values);
    }

    public function merge(self $other): self
    {
        return new self([...$this->values, ...$other->values]);
    }
}
