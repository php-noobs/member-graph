<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

/**
 * Collects neutral symbol-scope facts.
 *
 * @implements \IteratorAggregate<MemberGraphSymbolScopeFact>
 */
final class MemberGraphSymbolScopeFactCollection implements \Countable, \IteratorAggregate
{
    /**
     * @var list<MemberGraphSymbolScopeFact>
     */
    private array $facts = [];

    /**
     * Adds one scope fact.
     *
     * @param MemberGraphSymbolScopeFact $fact the scope fact to add
     */
    public function add(MemberGraphSymbolScopeFact $fact): self
    {
        $this->facts[] = $fact;

        return $this;
    }

    /**
     * Returns all scope facts.
     *
     * @return list<MemberGraphSymbolScopeFact>
     */
    public function all(): array
    {
        return $this->facts;
    }

    /**
     * Returns facts matching one role.
     *
     * @param MemberGraphSymbolScopeFactRole $role the role to keep
     */
    public function byRole(MemberGraphSymbolScopeFactRole $role): self
    {
        $facts = new self();

        foreach ($this->facts as $fact) {
            if ($fact->role === $role) {
                $facts->add($fact);
            }
        }

        return $facts;
    }

    /**
     * Indicates whether the collection contains one fact with the given local name.
     *
     * @param string $name the local name to find
     */
    public function hasName(string $name): bool
    {
        foreach ($this->facts as $fact) {
            if ($fact->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indicates whether the collection contains one fact with the given short name.
     *
     * @param string $shortName the short name to find
     */
    public function hasShortName(string $shortName): bool
    {
        foreach ($this->facts as $fact) {
            if ($fact->shortName === $shortName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indicates whether the collection contains one import fact with the given alias.
     *
     * @param string $alias the alias to find
     */
    public function hasAlias(string $alias): bool
    {
        foreach ($this->facts as $fact) {
            if ($fact->alias === $alias) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns an iterator over scope facts.
     *
     * @return \Traversable<MemberGraphSymbolScopeFact>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->facts;
    }

    /**
     * Counts scope facts.
     */
    public function count(): int
    {
        return count($this->facts);
    }
}
