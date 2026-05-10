<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

use PhpNoobs\PhpSource\VirtualPhpSourceFile;
use PhpParser\Node;

/**
 * Represents one neutral symbol-scope source fact.
 */
final readonly class MemberGraphSymbolScopeFact
{
    /**
     * Constructor.
     *
     * @param VirtualPhpSourceFile|null      $virtualFile the virtual source file containing the fact when available
     * @param Node|null                      $node        the exact PHPParser node representing the fact when available
     * @param MemberGraphSymbolScopeFactRole $role        the scope fact role
     * @param string                         $name        the local symbol name represented by the fact
     * @param string|null                    $fqcn        the fully-qualified symbol name when available
     * @param string|null                    $shortName   the short symbol name when available
     * @param string|null                    $alias       the import alias or effective imported name when available
     */
    public function __construct(
        public ?VirtualPhpSourceFile $virtualFile,
        public ?Node $node,
        public MemberGraphSymbolScopeFactRole $role,
        public string $name,
        public ?string $fqcn = null,
        public ?string $shortName = null,
        public ?string $alias = null,
    ) {
    }
}
