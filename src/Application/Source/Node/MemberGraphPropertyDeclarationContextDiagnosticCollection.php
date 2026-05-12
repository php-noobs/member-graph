<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

/**
 * Collects property declaration context diagnostics.
 *
 * @implements \IteratorAggregate<MemberGraphPropertyDeclarationContextDiagnostic>
 */
final class MemberGraphPropertyDeclarationContextDiagnosticCollection implements \Countable, \IteratorAggregate
{
    /**
     * @var list<MemberGraphPropertyDeclarationContextDiagnostic>
     */
    private array $diagnostics = [];

    /**
     * Adds one diagnostic.
     *
     * @param MemberGraphPropertyDeclarationContextDiagnostic $diagnostic the diagnostic to add
     */
    public function add(MemberGraphPropertyDeclarationContextDiagnostic $diagnostic): self
    {
        $this->diagnostics[] = $diagnostic;

        return $this;
    }

    /**
     * Returns all diagnostics.
     *
     * @return list<MemberGraphPropertyDeclarationContextDiagnostic>
     */
    public function all(): array
    {
        return $this->diagnostics;
    }

    /**
     * Indicates whether the collection contains diagnostics.
     */
    public function hasAny(): bool
    {
        return [] !== $this->diagnostics;
    }

    /**
     * Returns an iterator over diagnostics.
     *
     * @return \Traversable<MemberGraphPropertyDeclarationContextDiagnostic>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->diagnostics;
    }

    /**
     * Counts diagnostics.
     */
    public function count(): int
    {
        return count($this->diagnostics);
    }
}
