<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Build\Projection;

/**
 * Represents one parameter identity update requested by a graph projection.
 */
final readonly class MemberGraphParameterIdentityUpdate
{
    /**
     * Constructor.
     *
     * @param string   $owner            the current owner identity, or an empty string for functions
     * @param string   $functionLikeName the current method name or function FQCN
     * @param string   $parameterName    the current parameter name without "$"
     * @param string   $newParameterName the projected parameter name without "$"
     * @param int|null $parameterIndex   the optional zero-based declaration index
     */
    public function __construct(
        public string $owner,
        public string $functionLikeName,
        public string $parameterName,
        public string $newParameterName,
        public ?int $parameterIndex = null,
    ) {
    }
}
