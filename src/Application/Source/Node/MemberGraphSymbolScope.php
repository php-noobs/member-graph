<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

/**
 * Exposes neutral symbol-scope facts with typed projections.
 */
final readonly class MemberGraphSymbolScope
{
    /**
     * Constructor.
     *
     * @param MemberGraphSymbolScopeFactCollection $facts the scope facts
     */
    public function __construct(
        private MemberGraphSymbolScopeFactCollection $facts,
    ) {
    }

    /**
     * Returns every scope fact.
     */
    public function facts(): MemberGraphSymbolScopeFactCollection
    {
        return $this->facts;
    }

    /**
     * Returns method declaration facts.
     */
    public function methodDeclarations(): MemberGraphSymbolScopeFactCollection
    {
        return $this->facts->byRole(MemberGraphSymbolScopeFactRole::METHOD_DECLARATION);
    }

    /**
     * Returns property declaration facts.
     */
    public function propertyDeclarations(): MemberGraphSymbolScopeFactCollection
    {
        return $this->facts->byRole(MemberGraphSymbolScopeFactRole::PROPERTY_DECLARATION);
    }

    /**
     * Returns class-constant declaration facts.
     */
    public function classConstantDeclarations(): MemberGraphSymbolScopeFactCollection
    {
        return $this->facts->byRole(MemberGraphSymbolScopeFactRole::CLASS_CONSTANT_DECLARATION);
    }

    /**
     * Returns enum-case declaration facts.
     */
    public function enumCaseDeclarations(): MemberGraphSymbolScopeFactCollection
    {
        return $this->facts->byRole(MemberGraphSymbolScopeFactRole::ENUM_CASE_DECLARATION);
    }

    /**
     * Returns class-like namespace declaration facts.
     */
    public function classLikeDeclarations(): MemberGraphSymbolScopeFactCollection
    {
        return $this->facts->byRole(MemberGraphSymbolScopeFactRole::CLASS_LIKE_NAMESPACE_DECLARATION);
    }

    /**
     * Returns function namespace declaration facts.
     */
    public function functionDeclarations(): MemberGraphSymbolScopeFactCollection
    {
        return $this->facts->byRole(MemberGraphSymbolScopeFactRole::FUNCTION_NAMESPACE_DECLARATION);
    }

    /**
     * Returns constant namespace declaration facts.
     */
    public function constantDeclarations(): MemberGraphSymbolScopeFactCollection
    {
        return $this->facts->byRole(MemberGraphSymbolScopeFactRole::CONSTANT_NAMESPACE_DECLARATION);
    }

    /**
     * Returns class-like import facts.
     */
    public function classLikeImports(): MemberGraphSymbolScopeFactCollection
    {
        return $this->facts->byRole(MemberGraphSymbolScopeFactRole::CLASS_LIKE_IMPORT);
    }

    /**
     * Returns function import facts.
     */
    public function functionImports(): MemberGraphSymbolScopeFactCollection
    {
        return $this->facts->byRole(MemberGraphSymbolScopeFactRole::FUNCTION_IMPORT);
    }

    /**
     * Returns constant import facts.
     */
    public function constantImports(): MemberGraphSymbolScopeFactCollection
    {
        return $this->facts->byRole(MemberGraphSymbolScopeFactRole::CONSTANT_IMPORT);
    }
}
