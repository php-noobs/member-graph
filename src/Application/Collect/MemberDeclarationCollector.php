<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Collect;

use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclaration;
use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclarationCollection;
use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;
use PhpNoobs\MemberGraph\Domain\Source\SourceNodeId;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Const_ as ConstStatement;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;

/**
 * Collects member declarations discovered during member graph traversal.
 */
final readonly class MemberDeclarationCollector
{
    /**
     * Constructor.
     *
     * @param MemberDeclarationCollection $declarations    the declarations collection
     * @param string                      $virtualFilePath the current virtual file path
     */
    public function __construct(
        private MemberDeclarationCollection $declarations,
        private string $virtualFilePath,
    ) {
    }

    /**
     * Collects one method declaration.
     *
     * @param ClassMethod $method the method node
     * @param string      $owner  the current class-like owner
     */
    public function collectMethod(ClassMethod $method, string $owner): void
    {
        if ('global' === $owner) {
            return;
        }

        $this->declarations->add(new MemberDeclaration(
            id: new MemberId(
                owner: $owner,
                name: $method->name->toString(),
                type: MemberType::METHOD,
            ),
            file: $this->virtualFilePath,
            sourceNodeId: SourceNodeId::fromNode($this->virtualFilePath, $method),
        ));
    }

    /**
     * Collects one function declaration.
     *
     * @param Function_ $function     the function node
     * @param string    $functionName the fully-qualified function name
     */
    public function collectFunction(Function_ $function, string $functionName): void
    {
        $this->declarations->add(new MemberDeclaration(
            id: new MemberId(
                owner: '',
                name: $functionName,
                type: MemberType::FUNCTION_,
            ),
            file: $this->virtualFilePath,
            sourceNodeId: SourceNodeId::fromNode($this->virtualFilePath, $function),
        ));
    }

    /**
     * Collects global or namespaced constant declarations.
     *
     * @param ConstStatement $constStatement the constant statement node
     * @param string         $namespace      the current namespace
     */
    public function collectConstants(ConstStatement $constStatement, string $namespace): void
    {
        foreach ($constStatement->consts as $constant) {
            $this->declarations->add(new MemberDeclaration(
                id: new MemberId(
                    owner: '',
                    name: $this->constantName($constant->name->toString(), $namespace),
                    type: MemberType::CONSTANT,
                ),
                file: $this->virtualFilePath,
                sourceNodeId: SourceNodeId::fromNode($this->virtualFilePath, $constant),
            ));
        }
    }

    /**
     * Collects property declarations.
     *
     * @param Property $propertyNode the property declaration node
     * @param string   $owner        the current class-like owner
     */
    public function collectProperties(Property $propertyNode, string $owner): void
    {
        if ('global' === $owner) {
            return;
        }

        foreach ($propertyNode->props as $property) {
            $this->declarations->add(new MemberDeclaration(
                id: new MemberId(
                    owner: $owner,
                    name: $property->name->toString(),
                    type: MemberType::PROPERTY,
                ),
                file: $this->virtualFilePath,
                sourceNodeId: SourceNodeId::fromNode($this->virtualFilePath, $property),
            ));
        }
    }

    /**
     * Collects class-constant declarations.
     *
     * @param ClassConst $classConst the class-constant declaration node
     * @param string     $owner      the current class-like owner
     */
    public function collectClassConstants(ClassConst $classConst, string $owner): void
    {
        if ('global' === $owner) {
            return;
        }

        foreach ($classConst->consts as $constant) {
            $this->declarations->add(new MemberDeclaration(
                id: new MemberId(
                    owner: $owner,
                    name: $constant->name->toString(),
                    type: MemberType::CLASS_CONSTANT,
                ),
                file: $this->virtualFilePath,
                sourceNodeId: SourceNodeId::fromNode($this->virtualFilePath, $constant),
            ));
        }
    }

    /**
     * Collects one enum-case declaration as a class-constant declaration.
     *
     * @param EnumCase $enumCase the enum-case node
     * @param string   $owner    the current class-like owner
     */
    public function collectEnumCase(EnumCase $enumCase, string $owner): void
    {
        if ('global' === $owner) {
            return;
        }

        $this->declarations->add(new MemberDeclaration(
            id: new MemberId(
                owner: $owner,
                name: $enumCase->name->toString(),
                type: MemberType::CLASS_CONSTANT,
            ),
            file: $this->virtualFilePath,
            sourceNodeId: SourceNodeId::fromNode($this->virtualFilePath, $enumCase),
        ));
    }

    /**
     * Collects property declarations created by constructor property promotion.
     *
     * @param ClassMethod $method the method node
     * @param string      $owner  the current class-like owner
     */
    public function collectPromotedProperties(ClassMethod $method, string $owner): void
    {
        if ('global' === $owner || '__construct' !== strtolower($method->name->toString())) {
            return;
        }

        foreach ($method->params as $param) {
            if (!$param->isPromoted() || !$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $this->declarations->add(new MemberDeclaration(
                id: new MemberId(
                    owner: $owner,
                    name: $param->var->name,
                    type: MemberType::PROPERTY,
                ),
                file: $this->virtualFilePath,
                sourceNodeId: SourceNodeId::fromNode($this->virtualFilePath, $param),
            ));
        }
    }

    /**
     * Resolves one constant declaration name against its namespace.
     *
     * @param string $name      the local constant name
     * @param string $namespace the current namespace
     */
    private function constantName(string $name, string $namespace): string
    {
        if ('' === $namespace) {
            return $name;
        }

        return $namespace.'\\'.$name;
    }
}
