<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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
    public function __construct(array $values)
    {
        $this->values = array_values(array_unique(array_filter($values, static fn (string $tag): bool => $tag !== '')));
    }

    public static function fromHeader(string $header): self
    {
        return new self(explode(' ', $header));
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
