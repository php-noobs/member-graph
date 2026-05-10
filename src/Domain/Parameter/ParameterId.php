<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Domain\Parameter;

/**
 * Represents one function-like parameter identifier.
 *
 * When the declaration index is known, the identifier targets one exact slot in the function-like signature. When it is
 * absent, the identifier keeps the historical name-scoped behavior.
 */
final readonly class ParameterId
{
    /**
     * @param string   $owner            The owner FQCN. Empty string for functions.
     * @param string   $functionLikeName the method name or fully-qualified function name
     * @param string   $parameterName    the parameter name without the leading "$"
     * @param int|null $parameterIndex   the optional zero-based declaration index
     */
    public function __construct(
        public string $owner,
        public string $functionLikeName,
        public string $parameterName,
        public ?int $parameterIndex = null,
    ) {
    }

    /**
     * Returns the exact graph-storage hash for this parameter identifier.
     */
    public function hash(): string
    {
        if (null === $this->parameterIndex) {
            return $this->nameHash();
        }

        return sprintf(
            '%s::#%d',
            $this->nameHash(),
            $this->parameterIndex,
        );
    }

    /**
     * Returns the name-scoped hash used by parameter usages that cannot carry a declaration index.
     */
    public function nameHash(): string
    {
        return sprintf(
            'PARAMETER:%s::%s::$%s',
            $this->owner,
            $this->functionLikeName,
            $this->parameterName,
        );
    }

    /**
     * Returns whether this identifier targets a specific declaration index.
     */
    public function hasParameterIndex(): bool
    {
        return null !== $this->parameterIndex;
    }
}
