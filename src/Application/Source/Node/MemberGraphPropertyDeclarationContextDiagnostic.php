<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

/**
 * Describes a structural issue found while building a property declaration context.
 */
final readonly class MemberGraphPropertyDeclarationContextDiagnostic
{
    /**
     * Constructor.
     *
     * @param string $code    the stable diagnostic code
     * @param string $message the human-readable diagnostic message
     */
    public function __construct(
        public string $code,
        public string $message,
    ) {
    }
}
