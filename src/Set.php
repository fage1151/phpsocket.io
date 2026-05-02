<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Countable;
use IteratorAggregate;
use ArrayIterator;
use Traversable;

final class Set implements Countable, IteratorAggregate
{
    private array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = array_values(array_unique($items));
    }

    public function has(string $item): bool
    {
        return in_array($item, $this->items, true);
    }

    public function add(string $item): self
    {
        if (!in_array($item, $this->items, true)) {
            $this->items[] = $item;
        }
        return $this;
    }

    public function delete(string $item): self
    {
        $key = array_search($item, $this->items, true);
        if ($key !== false) {
            array_splice($this->items, $key, 1);
        }
        return $this;
    }

    public function values(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function toArray(): array
    {
        return $this->items;
    }
}
