<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

use PhpNoobs\PhpSource\VirtualPhpSourceFile;
use PhpParser\Node;

/**
 * Couples a PHPParser node with the virtual file that contains it.
 *
 * @internal
 */
final readonly class LocatedNode
{
    /**
     * Constructor.
     *
     * @param VirtualPhpSourceFile $virtualFile the virtual source file
     * @param Node                 $node        the located node
     */
    public function __construct(
        public VirtualPhpSourceFile $virtualFile,
        public Node $node,
    ) {
    }
}
