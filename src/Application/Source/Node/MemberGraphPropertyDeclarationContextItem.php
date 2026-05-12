<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

use PhpNoobs\PhpSource\VirtualPhpSourceFile;
use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;

/**
 * Describes the structural declaration context of one targeted property.
 */
final readonly class MemberGraphPropertyDeclarationContextItem
{
    /**
     * Constructor.
     *
     * @param VirtualPhpSourceFile   $file                         the virtual source file containing the declaration
     * @param PropertyProperty|Param $targetNode                   the targeted property declaration node
     * @param Property|null          $parentProperty               the parent grouped property statement, or null for promoted properties
     * @param ClassLike              $parentClassLike              the class-like node containing the declaration
     * @param int|null               $parentPropertyStatementIndex the parent property statement index in the class-like statement list
     * @param list<PropertyProperty> $siblingProperties            the sibling properties from the same grouped property statement
     * @param bool                   $promoted                     whether the target is a promoted property parameter
     * @param Node|null              $phpDocOwner                  the direct PHPDoc owner when unambiguous
     * @param bool                   $allSiblingsTargeted          whether every grouped sibling is included in the request
     */
    public function __construct(
        public VirtualPhpSourceFile $file,
        public PropertyProperty|Param $targetNode,
        public ?Property $parentProperty,
        public ClassLike $parentClassLike,
        public ?int $parentPropertyStatementIndex,
        public array $siblingProperties,
        public bool $promoted,
        public ?Node $phpDocOwner,
        public bool $allSiblingsTargeted,
    ) {
    }

    /**
     * Returns the targeted property name.
     */
    public function propertyName(): string
    {
        if ($this->targetNode instanceof PropertyProperty) {
            return $this->targetNode->name->toString();
        }

        $name = $this->targetNode->var->name ?? '';

        return is_string($name) ? $name : '';
    }
}
