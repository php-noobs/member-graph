<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

/**
 * Exposes structural declaration contexts for property retyping callers.
 */
final readonly class MemberGraphPropertyDeclarationContext
{
    /**
     * Constructor.
     *
     * @param MemberGraphPropertyDeclarationContextItemCollection       $items       the declaration context items
     * @param MemberGraphPropertyDeclarationContextDiagnosticCollection $diagnostics the context diagnostics
     */
    public function __construct(
        private MemberGraphPropertyDeclarationContextItemCollection $items,
        private MemberGraphPropertyDeclarationContextDiagnosticCollection $diagnostics,
    ) {
    }

    /**
     * Returns declaration context items.
     */
    public function items(): MemberGraphPropertyDeclarationContextItemCollection
    {
        return $this->items;
    }

    /**
     * Returns declaration context diagnostics.
     */
    public function diagnostics(): MemberGraphPropertyDeclarationContextDiagnosticCollection
    {
        return $this->diagnostics;
    }

    /**
     * Indicates whether the context contains diagnostics.
     */
    public function hasDiagnostics(): bool
    {
        return $this->diagnostics->hasAny();
    }
}
