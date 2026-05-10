<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

/**
 * Exposes neutral source facts for the declaring scope of one targeted parameter.
 */
final readonly class MemberGraphParameterScope
{
    /**
     * Constructor.
     *
     * @param VirtualPhpSourceFileNodeMatchCollection $matches the scope source-node matches
     */
    public function __construct(
        private VirtualPhpSourceFileNodeMatchCollection $matches,
    ) {
    }

    /**
     * Returns every scope match.
     */
    public function matches(): VirtualPhpSourceFileNodeMatchCollection
    {
        return $this->matches;
    }

    /**
     * Returns all parameters declared in the same signature as the targeted parameter.
     */
    public function parameters(): VirtualPhpSourceFileNodeMatchCollection
    {
        return $this->matches->parameterScopeParameters();
    }

    /**
     * Returns local variables declared or assigned in the targeted parameter declaring body.
     */
    public function localVariables(): VirtualPhpSourceFileNodeMatchCollection
    {
        return $this->matches->parameterScopeLocalVariables();
    }

    /**
     * Returns local usages of the targeted parameter inside its declaring body.
     */
    public function parameterLocalUsages(): VirtualPhpSourceFileNodeMatchCollection
    {
        return $this->matches->parameterLocalUsages();
    }
}
