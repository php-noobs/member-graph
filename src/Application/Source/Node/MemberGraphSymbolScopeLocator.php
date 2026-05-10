<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphBuild;
use PhpNoobs\MemberGraph\Domain\Availability\AvailableMember;
use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclaration;
use PhpNoobs\MemberGraph\Domain\Graph\MemberDependencyGraph;
use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;
use PhpNoobs\MemberGraph\Domain\Source\SourceNodeId;
use PhpNoobs\PhpSource\VirtualPhpSourceFile;
use PhpNoobs\PhpSource\VirtualPhpSourceFileCollection;
use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;

/**
 * Locates neutral symbol-scope source facts for rename-planning callers.
 */
final readonly class MemberGraphSymbolScopeLocator
{
    /**
     * Constructor.
     *
     * @param MemberDependencyGraph          $graph        the member dependency graph
     * @param VirtualPhpSourceFileCollection $virtualFiles the loaded virtual source files
     */
    public function __construct(
        private MemberDependencyGraph $graph,
        private VirtualPhpSourceFileCollection $virtualFiles,
    ) {
    }

    /**
     * Creates a symbol scope locator from a factory build result.
     *
     * @param MemberDependencyGraphBuild $build the member dependency graph build result
     */
    public static function fromBuild(MemberDependencyGraphBuild $build): self
    {
        return new self($build->memberDependencyGraph, $build->virtualFiles);
    }

    /**
     * Creates a symbol scope locator from a graph and its virtual files.
     *
     * @param MemberDependencyGraph          $graph        the member dependency graph
     * @param VirtualPhpSourceFileCollection $virtualFiles the virtual source files to inspect
     */
    public static function fromGraphAndVirtualFiles(
        MemberDependencyGraph $graph,
        VirtualPhpSourceFileCollection $virtualFiles,
    ): self {
        return new self($graph, $virtualFiles);
    }

    /**
     * Locates method declaration facts for the targeted owner scope.
     *
     * @param string $owner      the owner FQCN
     * @param string $methodName the targeted method name
     */
    public function methodScope(string $owner, string $methodName): MemberGraphSymbolScope
    {
        $facts = new MemberGraphSymbolScopeFactCollection();

        $this->addAvailableMemberFacts($facts, $owner, MemberType::METHOD, MemberGraphSymbolScopeFactRole::METHOD_DECLARATION);

        return new MemberGraphSymbolScope($facts);
    }

    /**
     * Locates property declaration facts for the targeted owner scope.
     *
     * @param string $owner        the owner FQCN
     * @param string $propertyName the targeted property name
     */
    public function propertyScope(string $owner, string $propertyName): MemberGraphSymbolScope
    {
        $facts = new MemberGraphSymbolScopeFactCollection();

        $this->addAvailableMemberFacts($facts, $owner, MemberType::PROPERTY, MemberGraphSymbolScopeFactRole::PROPERTY_DECLARATION);

        return new MemberGraphSymbolScope($facts);
    }

    /**
     * Locates class-constant and enum-case declaration facts for the targeted owner scope.
     *
     * @param string $owner        the owner FQCN
     * @param string $constantName the targeted constant or enum-case name
     */
    public function classConstantScope(string $owner, string $constantName): MemberGraphSymbolScope
    {
        $facts = new MemberGraphSymbolScopeFactCollection();

        $this->addAvailableMemberFacts($facts, $owner, MemberType::CLASS_CONSTANT, MemberGraphSymbolScopeFactRole::CLASS_CONSTANT_DECLARATION);
        $this->addInterfaceConstantFacts($facts, $owner);

        return new MemberGraphSymbolScope($facts);
    }

    /**
     * Locates class-like declarations in one namespace.
     *
     * @param string $namespace the namespace FQCN without leading slash
     */
    public function classLikeNamespaceScope(string $namespace): MemberGraphSymbolScope
    {
        $facts = new MemberGraphSymbolScopeFactCollection();

        foreach ($this->virtualFiles as $virtualFile) {
            foreach ($virtualFile->getAst() as $node) {
                $this->collectClassLikeNamespaceFacts($node, $virtualFile, $namespace, $facts);
            }
        }

        return new MemberGraphSymbolScope($facts);
    }

    /**
     * Locates function declarations in one namespace.
     *
     * @param string $namespace the namespace FQCN without leading slash
     */
    public function functionNamespaceScope(string $namespace): MemberGraphSymbolScope
    {
        $facts = new MemberGraphSymbolScopeFactCollection();

        foreach ($this->virtualFiles as $virtualFile) {
            foreach ($virtualFile->getAst() as $node) {
                $this->collectFunctionNamespaceFacts($node, $virtualFile, $namespace, $facts);
            }
        }

        return new MemberGraphSymbolScope($facts);
    }

    /**
     * Locates import facts declared in one virtual source file.
     *
     * @param VirtualPhpSourceFile $virtualFile the virtual source file to inspect
     */
    public function fileImportScope(VirtualPhpSourceFile $virtualFile): MemberGraphSymbolScope
    {
        $facts = new MemberGraphSymbolScopeFactCollection();

        foreach ($virtualFile->getAst() as $node) {
            $this->collectImportFacts($node, $virtualFile, $facts);
        }

        return new MemberGraphSymbolScope($facts);
    }

    /**
     * Adds facts for members available on one owner.
     *
     * @param MemberGraphSymbolScopeFactCollection $facts the output facts
     * @param string                               $owner the target owner FQCN
     * @param MemberType                           $type  the member type to expose
     * @param MemberGraphSymbolScopeFactRole       $role  the scope fact role
     */
    private function addAvailableMemberFacts(
        MemberGraphSymbolScopeFactCollection $facts,
        string $owner,
        MemberType $type,
        MemberGraphSymbolScopeFactRole $role,
    ): void {
        foreach ($this->graph->availableMembers->getByOwner($owner) as $availableMember) {
            if ($availableMember->member->type !== $type) {
                continue;
            }

            $this->addAvailableMemberFact($facts, $availableMember, $role);
        }
    }

    /**
     * Adds one available member fact.
     *
     * @param MemberGraphSymbolScopeFactCollection $facts           the output facts
     * @param AvailableMember                      $availableMember the available member
     * @param MemberGraphSymbolScopeFactRole       $role            the scope fact role
     */
    private function addAvailableMemberFact(
        MemberGraphSymbolScopeFactCollection $facts,
        AvailableMember $availableMember,
        MemberGraphSymbolScopeFactRole $role,
    ): void {
        $locatedDeclaration = $this->locateAvailableMemberDeclaration($availableMember);

        $facts->add(new MemberGraphSymbolScopeFact(
            virtualFile: $locatedDeclaration?->virtualFile,
            node: $locatedDeclaration?->node,
            role: $this->scopeFactRoleForLocatedDeclaration($role, $locatedDeclaration),
            name: $availableMember->member->name,
            shortName: $availableMember->member->name,
        ));
    }

    /**
     * Adds class-constant facts declared by implemented or extended interfaces.
     *
     * @param MemberGraphSymbolScopeFactCollection $facts the output facts
     * @param string                               $owner the target owner FQCN
     */
    private function addInterfaceConstantFacts(MemberGraphSymbolScopeFactCollection $facts, string $owner): void
    {
        foreach ($this->interfaceFamily($owner) as $interfaceFqcn) {
            foreach ($this->graph->declarations->all() as $declaration) {
                if ($declaration->id->owner !== $interfaceFqcn || MemberType::CLASS_CONSTANT !== $declaration->id->type) {
                    continue;
                }

                $locatedDeclaration = $this->locateDeclarationNode($declaration);
                $facts->add(new MemberGraphSymbolScopeFact(
                    virtualFile: $locatedDeclaration?->virtualFile,
                    node: $locatedDeclaration?->node,
                    role: MemberGraphSymbolScopeFactRole::CLASS_CONSTANT_DECLARATION,
                    name: $declaration->id->name,
                    shortName: $declaration->id->name,
                ));
            }
        }
    }

    /**
     * Returns interfaces directly or indirectly related to one owner.
     *
     * @param string $owner the owner FQCN
     *
     * @return list<string>
     */
    private function interfaceFamily(string $owner): array
    {
        $knownOwner = $this->graph->knownOwners->get($owner);

        if (null === $knownOwner) {
            return [];
        }

        $interfaces = [];
        $visited = [];

        foreach ([...$knownOwner->interfaces, ...$knownOwner->extendsInterfaces] as $interfaceFqcn) {
            $this->collectInterfaceFamily($interfaceFqcn, $interfaces, $visited);
        }

        return $interfaces;
    }

    /**
     * Collects interface inheritance recursively.
     *
     * @param string              $interfaceFqcn the interface FQCN
     * @param list<string>        $interfaces    the collected interfaces
     * @param array<string, true> $visited       the visited interfaces
     */
    private function collectInterfaceFamily(string $interfaceFqcn, array &$interfaces, array &$visited): void
    {
        if (isset($visited[$interfaceFqcn])) {
            return;
        }

        $visited[$interfaceFqcn] = true;
        $interfaces[] = $interfaceFqcn;
        $knownInterface = $this->graph->knownOwners->get($interfaceFqcn);

        if (null === $knownInterface) {
            return;
        }

        foreach ($knownInterface->extendsInterfaces as $parentInterfaceFqcn) {
            $this->collectInterfaceFamily($parentInterfaceFqcn, $interfaces, $visited);
        }
    }

    /**
     * Returns the most precise scope role for a located declaration.
     *
     * @param MemberGraphSymbolScopeFactRole $fallbackRole       the fallback role
     * @param LocatedNode|null               $locatedDeclaration the located declaration
     */
    private function scopeFactRoleForLocatedDeclaration(
        MemberGraphSymbolScopeFactRole $fallbackRole,
        ?LocatedNode $locatedDeclaration,
    ): MemberGraphSymbolScopeFactRole {
        if (
            MemberGraphSymbolScopeFactRole::CLASS_CONSTANT_DECLARATION === $fallbackRole
            && $locatedDeclaration?->node instanceof EnumCase
        ) {
            return MemberGraphSymbolScopeFactRole::ENUM_CASE_DECLARATION;
        }

        return $fallbackRole;
    }

    /**
     * Locates the best available source declaration for one available member.
     *
     * @param AvailableMember $availableMember the available member
     */
    private function locateAvailableMemberDeclaration(AvailableMember $availableMember): ?LocatedNode
    {
        foreach (array_keys($availableMember->declaredIns) as $declaredIn) {
            $declaration = $this->graph->declarations->get(new MemberId(
                owner: $declaredIn,
                name: $availableMember->member->name,
                type: $availableMember->member->type,
            ));

            if (null === $declaration) {
                continue;
            }

            $locatedNode = $this->locateDeclarationNode($declaration);

            if (null !== $locatedNode) {
                return $locatedNode;
            }
        }

        return null;
    }

    /**
     * Locates the exact source node for one member declaration.
     *
     * @param MemberDeclaration $declaration the member declaration
     */
    private function locateDeclarationNode(MemberDeclaration $declaration): ?LocatedNode
    {
        $virtualFile = $this->virtualFiles->getByPath($declaration->file);

        if (null === $virtualFile) {
            return null;
        }

        foreach ($virtualFile->getAst() as $node) {
            $locatedNode = $this->locateDeclarationNodeInAst($node, $virtualFile, $declaration);

            if (null !== $locatedNode) {
                return $locatedNode;
            }
        }

        return null;
    }

    /**
     * Locates one declaration node recursively.
     *
     * @param Node                 $node        the node to inspect
     * @param VirtualPhpSourceFile $virtualFile the virtual source file
     * @param MemberDeclaration    $declaration the member declaration
     */
    private function locateDeclarationNodeInAst(
        Node $node,
        VirtualPhpSourceFile $virtualFile,
        MemberDeclaration $declaration,
    ): ?LocatedNode {
        if ($this->nodeMatchesDeclaration($node, $virtualFile, $declaration)) {
            return new LocatedNode($virtualFile, $node);
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node) {
                $locatedNode = $this->locateDeclarationNodeInAst($subNode, $virtualFile, $declaration);

                if (null !== $locatedNode) {
                    return $locatedNode;
                }

                continue;
            }

            if (!is_array($subNode)) {
                continue;
            }

            foreach ($subNode as $subNodeItem) {
                if (!$subNodeItem instanceof Node) {
                    continue;
                }

                $locatedNode = $this->locateDeclarationNodeInAst($subNodeItem, $virtualFile, $declaration);

                if (null !== $locatedNode) {
                    return $locatedNode;
                }
            }
        }

        return null;
    }

    /**
     * Indicates whether one node matches one member declaration.
     *
     * @param Node                 $node        the node to inspect
     * @param VirtualPhpSourceFile $virtualFile the virtual source file
     * @param MemberDeclaration    $declaration the member declaration
     */
    private function nodeMatchesDeclaration(
        Node $node,
        VirtualPhpSourceFile $virtualFile,
        MemberDeclaration $declaration,
    ): bool {
        if (null !== $declaration->sourceNodeId) {
            $sourceNodeId = SourceNodeId::fromNode($virtualFile->virtualFilePath, $node);

            return null !== $sourceNodeId && $declaration->sourceNodeId->equals($sourceNodeId);
        }

        return $this->nodeNameMatchesMember($node, $declaration->id);
    }

    /**
     * Indicates whether one node name matches one member id.
     *
     * @param Node     $node     the node to inspect
     * @param MemberId $memberId the member id
     */
    private function nodeNameMatchesMember(Node $node, MemberId $memberId): bool
    {
        if (MemberType::METHOD === $memberId->type) {
            return $node instanceof ClassMethod && $node->name->toString() === $memberId->name;
        }

        if (MemberType::PROPERTY === $memberId->type) {
            return (
                $node instanceof PropertyProperty
                && $node->name->toString() === $memberId->name
            ) || (
                $node instanceof Param
                && $node->var instanceof Variable
                && is_string($node->var->name)
                && $node->var->name === $memberId->name
            );
        }

        return MemberType::CLASS_CONSTANT === $memberId->type
            && $node instanceof Const_
            && $node->name->toString() === $memberId->name;
    }

    /**
     * Collects class-like declaration facts recursively.
     *
     * @param Node                                 $node        the node to inspect
     * @param VirtualPhpSourceFile                 $virtualFile the virtual source file
     * @param string                               $namespace   the namespace to match
     * @param MemberGraphSymbolScopeFactCollection $facts       the output facts
     */
    private function collectClassLikeNamespaceFacts(
        Node $node,
        VirtualPhpSourceFile $virtualFile,
        string $namespace,
        MemberGraphSymbolScopeFactCollection $facts,
    ): void {
        if ($node instanceof ClassLike && $node->namespacedName instanceof Name) {
            $fqcn = $node->namespacedName->toString();

            if ($this->namespaceOf($fqcn) === $namespace) {
                $shortName = $this->shortName($fqcn);
                $facts->add(new MemberGraphSymbolScopeFact(
                    virtualFile: $virtualFile,
                    node: $node,
                    role: MemberGraphSymbolScopeFactRole::CLASS_LIKE_NAMESPACE_DECLARATION,
                    name: $shortName,
                    fqcn: $fqcn,
                    shortName: $shortName,
                ));
            }
        }

        $this->collectFromChildren(
            node: $node,
            collector: fn (Node $child): null => $this->collectClassLikeNamespaceFacts($child, $virtualFile, $namespace, $facts),
        );
    }

    /**
     * Collects function declaration facts recursively.
     *
     * @param Node                                 $node        the node to inspect
     * @param VirtualPhpSourceFile                 $virtualFile the virtual source file
     * @param string                               $namespace   the namespace to match
     * @param MemberGraphSymbolScopeFactCollection $facts       the output facts
     */
    private function collectFunctionNamespaceFacts(
        Node $node,
        VirtualPhpSourceFile $virtualFile,
        string $namespace,
        MemberGraphSymbolScopeFactCollection $facts,
    ): void {
        if ($node instanceof Function_ && $node->namespacedName instanceof Name) {
            $fqcn = $node->namespacedName->toString();

            if ($this->namespaceOf($fqcn) === $namespace) {
                $shortName = $this->shortName($fqcn);
                $facts->add(new MemberGraphSymbolScopeFact(
                    virtualFile: $virtualFile,
                    node: $node,
                    role: MemberGraphSymbolScopeFactRole::FUNCTION_NAMESPACE_DECLARATION,
                    name: $shortName,
                    fqcn: $fqcn,
                    shortName: $shortName,
                ));
            }
        }

        $this->collectFromChildren(
            node: $node,
            collector: fn (Node $child): null => $this->collectFunctionNamespaceFacts($child, $virtualFile, $namespace, $facts),
        );
    }

    /**
     * Collects import facts recursively.
     *
     * @param Node                                 $node        the node to inspect
     * @param VirtualPhpSourceFile                 $virtualFile the virtual source file
     * @param MemberGraphSymbolScopeFactCollection $facts       the output facts
     */
    private function collectImportFacts(
        Node $node,
        VirtualPhpSourceFile $virtualFile,
        MemberGraphSymbolScopeFactCollection $facts,
    ): void {
        if ($node instanceof Use_) {
            foreach ($node->uses as $useUse) {
                $this->addImportFact($facts, $virtualFile, $useUse, $useUse->name->toString(), $node->type);
            }

            return;
        }

        if ($node instanceof GroupUse) {
            foreach ($node->uses as $useUse) {
                $this->addImportFact(
                    facts: $facts,
                    virtualFile: $virtualFile,
                    useUse: $useUse,
                    fqcn: $node->prefix->toString().'\\'.$useUse->name->toString(),
                    fallbackType: $node->type,
                );
            }

            return;
        }

        $this->collectFromChildren(
            node: $node,
            collector: fn (Node $child): null => $this->collectImportFacts($child, $virtualFile, $facts),
        );
    }

    /**
     * Adds one import fact.
     *
     * @param MemberGraphSymbolScopeFactCollection $facts        the output facts
     * @param VirtualPhpSourceFile                 $virtualFile  the virtual source file
     * @param UseItem                              $useUse       the imported item node
     * @param string                               $fqcn         the imported FQCN
     * @param int                                  $fallbackType the fallback use type
     */
    private function addImportFact(
        MemberGraphSymbolScopeFactCollection $facts,
        VirtualPhpSourceFile $virtualFile,
        UseItem $useUse,
        string $fqcn,
        int $fallbackType,
    ): void {
        $alias = $useUse->alias?->toString() ?? $useUse->name->getLast();
        $type = Use_::TYPE_UNKNOWN !== $useUse->type ? $useUse->type : $fallbackType;
        $role = match ($type) {
            Use_::TYPE_FUNCTION => MemberGraphSymbolScopeFactRole::FUNCTION_IMPORT,
            Use_::TYPE_CONSTANT => MemberGraphSymbolScopeFactRole::CONSTANT_IMPORT,
            default => MemberGraphSymbolScopeFactRole::CLASS_LIKE_IMPORT,
        };

        $facts->add(new MemberGraphSymbolScopeFact(
            virtualFile: $virtualFile,
            node: $useUse,
            role: $role,
            name: $alias,
            fqcn: $fqcn,
            shortName: $this->shortName($fqcn),
            alias: $alias,
        ));
    }

    /**
     * Collects recursively from child nodes.
     *
     * @param Node                 $node      the parent node
     * @param callable(Node): null $collector the child collector
     */
    private function collectFromChildren(Node $node, callable $collector): void
    {
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node) {
                $collector($subNode);

                continue;
            }

            if (!is_array($subNode)) {
                continue;
            }

            foreach ($subNode as $subNodeItem) {
                if ($subNodeItem instanceof Node) {
                    $collector($subNodeItem);
                }
            }
        }
    }

    /**
     * Returns the namespace part of one FQCN.
     *
     * @param string $fqcn the FQCN
     */
    private function namespaceOf(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        if (false === $position) {
            return '';
        }

        return substr($fqcn, 0, $position);
    }

    /**
     * Returns the short name part of one FQCN.
     *
     * @param string $fqcn the FQCN
     */
    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        if (false === $position) {
            return $fqcn;
        }

        return substr($fqcn, $position + 1);
    }
}
