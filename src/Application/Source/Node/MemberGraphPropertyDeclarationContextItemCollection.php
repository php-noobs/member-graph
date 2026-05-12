<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

/**
 * Collects property declaration context items.
 *
 * @implements \IteratorAggregate<MemberGraphPropertyDeclarationContextItem>
 */
final class MemberGraphPropertyDeclarationContextItemCollection implements \Countable, \IteratorAggregate
{
    /**
     * @var list<MemberGraphPropertyDeclarationContextItem>
     */
    private array $items = [];

    /**
     * Adds one context item.
     *
     * @param MemberGraphPropertyDeclarationContextItem $item the item to add
     */
    public function add(MemberGraphPropertyDeclarationContextItem $item): self
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * Returns all context items.
     *
     * @return list<MemberGraphPropertyDeclarationContextItem>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Returns the first item, when available.
     */
    public function first(): ?MemberGraphPropertyDeclarationContextItem
    {
        return $this->items[0] ?? null;
    }

    /**
     * Returns only promoted-property context items.
     */
    public function promoted(): self
    {
        return $this->filter(static fn (MemberGraphPropertyDeclarationContextItem $item): bool => $item->promoted);
    }

    /**
     * Returns only grouped property context items.
     */
    public function grouped(): self
    {
        return $this->filter(static fn (MemberGraphPropertyDeclarationContextItem $item): bool => !$item->promoted);
    }

    /**
     * Returns items matching one property name.
     *
     * @param string $propertyName the property name to keep
     */
    public function byPropertyName(string $propertyName): self
    {
        return $this->filter(static fn (MemberGraphPropertyDeclarationContextItem $item): bool => $item->propertyName() === $propertyName);
    }

    /**
     * Filters context items.
     *
     * @param callable(MemberGraphPropertyDeclarationContextItem): bool $predicate the item predicate
     */
    private function filter(callable $predicate): self
    {
        $items = new self();

        foreach ($this->items as $item) {
            if ($predicate($item)) {
                $items->add($item);
            }
        }

        return $items;
    }

    /**
     * Returns an iterator over context items.
     *
     * @return \Traversable<MemberGraphPropertyDeclarationContextItem>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    /**
     * Counts context items.
     */
    public function count(): int
    {
        return count($this->items);
    }
}
