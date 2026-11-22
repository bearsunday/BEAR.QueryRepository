<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Closure;
use ReflectionParameter;

use function array_key_exists;

/**
 * One parameter of a resource's onGet(), read from a query under its name or, failing that, its snake_case key
 *
 * @internal
 */
final readonly class OnGetParameter
{
    public string $name;

    /** @param bool $isBound whether #[ResourceParam] fills it from another resource */
    public function __construct(
        private ReflectionParameter $parameter,
        private string $snakeName,
        public bool $isBound,
    ) {
        $this->name = $parameter->name;
    }

    public function hasDefault(): bool
    {
        return $this->parameter->isDefaultValueAvailable();
    }

    public function default(): mixed
    {
        return $this->parameter->getDefaultValue();
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return string|null the key that holds a value other than null
     */
    public function valueKey(array $query): string|null
    {
        return $this->key(static fn (string $key): bool => isset($query[$key]));
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return string|null the key BEAR.Resource binds it from: one present with null counts when it allows null
     */
    public function bindingKey(array $query): string|null
    {
        $allowsNull = $this->parameter->allowsNull();

        return $this->key(static fn (string $key): bool => isset($query[$key]) || ($allowsNull && array_key_exists($key, $query)));
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return string|null the key that is present, whatever it holds
     */
    public function presentKey(array $query): string|null
    {
        return $this->key(static fn (string $key): bool => array_key_exists($key, $query));
    }

    /** @param Closure(string): bool $holds */
    private function key(Closure $holds): string|null
    {
        foreach ([$this->name, $this->snakeName] as $key) {
            if ($holds($key)) {
                return $key;
            }
        }

        return null;
    }
}
