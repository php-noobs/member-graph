<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Traverse;

use PhpNoobs\MemberGraph\Application\Build\Context\MemberGraphBuildContext;
use PhpNoobs\MemberGraph\Application\Collect\InferredStructuredReturnCollector;
use PhpNoobs\MemberGraph\Application\Collect\LocalVariableTypeCollector;
use PhpNoobs\MemberGraph\Application\Collect\MemberDeclarationCollector;
use PhpNoobs\MemberGraph\Application\Collect\MemberUsageCollector;
use PhpNoobs\MemberGraph\Application\Collect\OwnerDeclarationCollector;
use PhpNoobs\MemberGraph\Application\Collect\OwnerUsageCollector;
use PhpNoobs\MemberGraph\Application\Collect\ParameterUsageCollector;
use PhpNoobs\MemberGraph\Application\Collect\VariableTypePropagationResolver;
use PhpNoobs\MemberGraph\Application\Resolver\Contracts\ExpressionTypeResolverInterface;
use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclarationCollection;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerDeclarationCollection;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerUsageCollection;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerUsageType;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterUsageCollection;
use PhpNoobs\MemberGraph\Domain\Source\SourceNodeId;
use PhpNoobs\MemberGraph\Domain\Symbol\SymbolCollection;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsageCollection;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsageType;
use PhpNoobs\MemberGraph\Infrastructure\PhpDoc\Extractor\LocalVarPhpDocTypeExtractor;
use PhpNoobs\MemberGraph\Infrastructure\PhpDoc\Extractor\ParamPhpDocTypeExtractor;
use PhpNoobs\MemberGraph\Infrastructure\PhpDoc\Extractor\PhpDocTemplateDefinitionExtractor;
use PhpNoobs\MemberGraph\Infrastructure\PhpDoc\Resolver\PhpDocTagKind;
use PhpNoobs\MemberGraph\Infrastructure\PhpDoc\Resolver\StructuredPhpDocTypeSelector;
use PhpNoobs\MemberGraph\Infrastructure\UseStatements\UsesByAliasCollection;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Const_ as ConstStatement;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\UnionType;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\NodeVisitorAbstract;

/**
 * Class MemberGraphBuilderVisitor.
 */
final class MemberGraphBuilderVisitor extends NodeVisitorAbstract
{
    private MemberGraphTraversalState $state;

    private MemberDeclarationCollector $memberDeclarationCollector;

    private MemberUsageCollector $memberUsageCollector;

    private OwnerDeclarationCollector $ownerDeclarationCollector;

    private OwnerUsageCollector $ownerUsageCollector;

    private ParameterUsageCollector $parameterUsageCollector;

    private InferredStructuredReturnCollector $inferredStructuredReturnCollector;

    private LocalVariableTypeCollector $localVariableTypeCollector;

    private MemberGraphBuildContext $context;

    /**
     * @param string                            $fullFilePath                      the full file path
     * @param string                            $virtualFilePath                   the current virtual file path
     * @param MemberDeclarationCollection       $declarations                      the declarations collection
     * @param MemberUsageCollection             $usages                            the usages collection
     * @param ParameterUsageCollection          $parameterUsages                   the parameter usages collection
     * @param OwnerDeclarationCollection        $ownerDeclarations                 the owner declarations collection
     * @param OwnerUsageCollection              $ownerUsages                       the owner usages collection
     * @param ExpressionTypeResolverInterface   $expressionTypeResolver            the expression type resolver
     * @param LocalVarPhpDocTypeExtractor       $localVarPhpDocTypeExtractor       the local variable type extractor
     * @param ParamPhpDocTypeExtractor          $paramPhpDocTypeExtractor          the parameter type extractor
     * @param PhpDocTemplateDefinitionExtractor $phpDocTemplateDefinitionExtractor the PHPDoc template definition extractor
     * @param UsesByAliasCollection             $usesByAlias                       the uses by alias map
     * @param MemberGraphBuildContext           $context                           the enriched member graph build context
     */
    public function __construct(
        string $fullFilePath,
        string $virtualFilePath,
        MemberDeclarationCollection $declarations,
        MemberUsageCollection $usages,
        ParameterUsageCollection $parameterUsages,
        OwnerDeclarationCollection $ownerDeclarations,
        OwnerUsageCollection $ownerUsages,
        private readonly ExpressionTypeResolverInterface $expressionTypeResolver,
        LocalVarPhpDocTypeExtractor $localVarPhpDocTypeExtractor,
        ParamPhpDocTypeExtractor $paramPhpDocTypeExtractor,
        private readonly PhpDocTemplateDefinitionExtractor $phpDocTemplateDefinitionExtractor,
        private readonly UsesByAliasCollection $usesByAlias,
        MemberGraphBuildContext $context,
    ) {
        $this->context = $context;
        $this->state = new MemberGraphTraversalState($fullFilePath, $virtualFilePath);
        $this->memberDeclarationCollector = new MemberDeclarationCollector($declarations, $virtualFilePath);
        $this->ownerDeclarationCollector = new OwnerDeclarationCollector($ownerDeclarations, $virtualFilePath);
        $this->ownerUsageCollector = new OwnerUsageCollector($ownerUsages, $virtualFilePath);
        $this->memberUsageCollector = new MemberUsageCollector(
            $usages,
            $this->context->polymorphicImplementationsIndex,
            $virtualFilePath,
        );
        $this->parameterUsageCollector = new ParameterUsageCollector(
            $parameterUsages,
            $this->context->polymorphicImplementationsIndex,
            $virtualFilePath,
        );
        $structuredPhpDocTypeSelector = new StructuredPhpDocTypeSelector();
        $this->inferredStructuredReturnCollector = new InferredStructuredReturnCollector(
            $this->expressionTypeResolver,
            $this->context->methodReturnStructuredTypeIndex,
            $this->context->methodReturnInferredStructuredTypeIndex,
            $this->context->functionReturnStructuredTypeIndex,
            $this->context->functionReturnInferredStructuredTypeIndex,
            $this->usesByAlias,
            $structuredPhpDocTypeSelector,
        );
        $variableTypePropagationResolver = new VariableTypePropagationResolver();
        $this->localVariableTypeCollector = new LocalVariableTypeCollector(
            $this->expressionTypeResolver,
            $localVarPhpDocTypeExtractor,
            $paramPhpDocTypeExtractor,
            $this->context->methodParameterStructuredTypeIndex,
            $this->context->functionParameterStructuredTypeIndex,
            $this->usesByAlias,
            $structuredPhpDocTypeSelector,
            $variableTypePropagationResolver,
        );
    }

    /**
     * Handles node entry.
     *
     * @param Node $node the current node
     */
    public function enterNode(Node $node): null
    {
        if ($node instanceof Namespace_) {
            $this->state->enterNamespace($node->name?->toString() ?? '');
        }

        if ((
            ($node instanceof Class_)
                || ($node instanceof Interface_)
                || ($node instanceof Trait_)
                || ($node instanceof Enum_)
        ) && isset($node->namespacedName)) {
            $this->enterClassLikeNode($node);

            return null;
        }

        if ($node instanceof ClassMethod) {
            $this->enterClassMethodNode($node);

            return null;
        }

        if ($node instanceof Function_ && isset($node->namespacedName)) {
            $this->enterFunctionNode($node);

            return null;
        }

        if ($node instanceof ConstStatement) {
            $this->memberDeclarationCollector->collectConstants($node, $this->state->currentNamespace());

            return null;
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            $this->enterClosureLikeNode($node);

            return null;
        }

        if ($node instanceof Assign) {
            $this->localVariableTypeCollector->collectAssignment($node, $this->state);

            return null;
        }

        if ($node instanceof Return_) {
            $this->inferredStructuredReturnCollector->collect($node, $this->state);

            return null;
        }

        if ($node instanceof Property) {
            $this->collectTypeReferenceUsages($node->type);
            $this->memberDeclarationCollector->collectProperties($node, $this->state->currentClass());

            return null;
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            $this->collectOwnerUsageFromName($node->class, OwnerUsageType::NEW);

            return null;
        }

        if ($node instanceof Instanceof_ && $node->class instanceof Name) {
            $this->collectOwnerUsageFromName($node->class, OwnerUsageType::INSTANCEOF);

            return null;
        }

        if ($node instanceof TraitUse) {
            foreach ($node->traits as $trait) {
                $this->collectOwnerUsageFromName($trait, OwnerUsageType::TRAIT_USE);
            }

            return null;
        }

        if ($node instanceof Attribute) {
            $this->collectOwnerUsageFromName($node->name, OwnerUsageType::ATTRIBUTE);

            return null;
        }

        if ($node instanceof ClassConst) {
            $this->memberDeclarationCollector->collectClassConstants($node, $this->state->currentClass());

            return null;
        }

        if ($node instanceof EnumCase) {
            $this->memberDeclarationCollector->collectEnumCase($node, $this->state->currentClass());

            return null;
        }

        if ($node instanceof ClassConstFetch && $node->class instanceof Name && $node->name instanceof Identifier) {
            $this->collectOwnerUsageFromName($node->class, OwnerUsageType::CLASS_CONSTANT_FETCH);
            $this->collectClassConstantFetchUsage($node);

            return null;
        }

        if ($node instanceof ConstFetch) {
            $this->collectConstantFetchUsage($node);

            return null;
        }

        if ($node instanceof PropertyFetch && $node->name instanceof Identifier) {
            $this->collectPropertyFetchUsage($node);

            return null;
        }

        if ($node instanceof StaticPropertyFetch && $node->class instanceof Name) {
            $this->collectOwnerUsageFromName($node->class, OwnerUsageType::STATIC_PROPERTY_FETCH);
            $this->collectStaticPropertyFetchUsage($node);

            return null;
        }

        if ($node instanceof Expression) {
            $this->localVariableTypeCollector->collectLocalVarPhpDoc($node, $this->state);
        }

        return null;
    }

    /**
     * Handles node exit.
     *
     * @param Node $node the current node
     *
     * @return null
     */
    public function leaveNode(Node $node): mixed
    {
        if ($node instanceof ClassMethod) {
            $this->leaveClassMethodNode();

            return null;
        }
        if ($node instanceof Function_) {
            $this->leaveFunctionNode();

            return null;
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            $this->state->popClosureVariableScope();

            return null;
        }

        if ($node instanceof Class_ || $node instanceof Trait_ || $node instanceof Interface_ || $node instanceof Enum_) {
            $this->leaveClassLikeNode();
        }

        // We do this in leaveNode so we can process things like $service->getBox()->get()->send();

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $this->collectMethodCallUsage($node);

            return null;
        }

        if ($node instanceof NullsafeMethodCall && $node->name instanceof Identifier) {
            $this->collectMethodCallUsage($node);

            return null;
        }

        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $this->collectOwnerUsageFromName($node->class, OwnerUsageType::STATIC_CALL);
            $this->collectStaticCallUsage($node);

            return null;
        }

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $this->collectFunctionCallUsage($node);

            return null;
        }

        return null;
    }

    /**
     * Enters a class-like node and collects its template definitions.
     *
     * @param Class_|Trait_|Interface_|Enum_ $node the class-like node
     */
    private function enterClassLikeNode(Class_|Trait_|Interface_|Enum_ $node): void
    {
        if (null === $node->namespacedName) {
            return;
        }

        $this->state->enterClassLike($node->namespacedName->toString());
        $this->ownerDeclarationCollector->collect($node);
        $this->collectClassLikeOwnerUsages($node);
        $this->collectTemplateDefinitions($node);
    }

    /**
     * Enters a class method node and collects method-local declarations and parameter types.
     *
     * @param ClassMethod $node the class method node
     */
    private function enterClassMethodNode(ClassMethod $node): void
    {
        $this->state->enterMethod($node);
        $this->collectTemplateDefinitions($node);
        $this->collectFunctionLikeTypeReferenceUsages(array_values($node->params), $node->returnType);
        $this->localVariableTypeCollector->collectParameters(
            $node->params,
            $this->state->currentMethod(),
            $this->state,
        );
        $this->localVariableTypeCollector->collectParametersFromPhpDoc($node, $this->state);
        $this->memberDeclarationCollector->collectMethod($node, $this->state->currentClass());
        $this->memberDeclarationCollector->collectPromotedProperties($node, $this->state->currentClass());
    }

    /**
     * Enters a function node and collects function-local declarations and parameter types.
     *
     * @param Function_ $node the function node
     */
    private function enterFunctionNode(Function_ $node): void
    {
        if (null === $node->namespacedName) {
            return;
        }

        $this->state->enterFunction($node, $node->namespacedName->toString());
        $this->collectTemplateDefinitions($node);
        $this->collectFunctionLikeTypeReferenceUsages(array_values($node->params), $node->returnType);
        $this->localVariableTypeCollector->collectParameters(
            $node->params,
            $this->state->currentFunction(),
            $this->state,
        );
        $this->localVariableTypeCollector->collectParametersFromPhpDoc($node, $this->state);
        $this->memberDeclarationCollector->collectFunction($node, $this->state->currentFunction());
    }

    /**
     * Enters a closure-like node and opens a local variable scope.
     *
     * @param Closure|ArrowFunction $node the closure-like node
     */
    private function enterClosureLikeNode(Closure|ArrowFunction $node): void
    {
        $this->state->pushClosureVariableScope();
        $this->collectFunctionLikeTypeReferenceUsages(array_values($node->params), $node->returnType);
        $this->localVariableTypeCollector->collectParameters($node->params, '', $this->state);
    }

    /**
     * Collects owner usages declared by class-like structural clauses.
     *
     * @param Class_|Trait_|Interface_|Enum_ $node the class-like node
     */
    private function collectClassLikeOwnerUsages(Class_|Trait_|Interface_|Enum_ $node): void
    {
        if ($node instanceof Class_ && null !== $node->extends) {
            $this->collectOwnerUsageFromName($node->extends, OwnerUsageType::EXTENDS);
        }

        if ($node instanceof Class_ || $node instanceof Enum_) {
            foreach ($node->implements as $interface) {
                $this->collectOwnerUsageFromName($interface, OwnerUsageType::IMPLEMENTS);
            }
        }

        if ($node instanceof Interface_) {
            foreach ($node->extends as $interface) {
                $this->collectOwnerUsageFromName($interface, OwnerUsageType::EXTENDS);
            }
        }
    }

    /**
     * Collects owner usages from function-like native parameter and return types.
     *
     * @param array<int, Node\Param>           $parameters the function-like parameters
     * @param Identifier|Name|ComplexType|null $returnType the function-like return type
     */
    private function collectFunctionLikeTypeReferenceUsages(array $parameters, Identifier|Name|ComplexType|null $returnType): void
    {
        foreach ($parameters as $parameter) {
            $this->collectTypeReferenceUsages($parameter->type);
        }

        $this->collectTypeReferenceUsages($returnType);
    }

    /**
     * Collects owner usages from one native type node.
     *
     * @param Identifier|Name|ComplexType|null $type the native type node
     */
    private function collectTypeReferenceUsages(Identifier|Name|ComplexType|null $type): void
    {
        if (null === $type || $type instanceof Identifier) {
            return;
        }

        if ($type instanceof Name) {
            $this->collectOwnerUsageFromName($type, OwnerUsageType::TYPE_REFERENCE);

            return;
        }

        if ($type instanceof NullableType) {
            $this->collectTypeReferenceUsages($type->type);

            return;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $innerType) {
                $this->collectTypeReferenceUsages($innerType);
            }
        }
    }

    /**
     * Collects one owner usage from a class-name node.
     *
     * @param Name           $name the class-name node
     * @param OwnerUsageType $type the owner usage type
     */
    private function collectOwnerUsageFromName(Name $name, OwnerUsageType $type): void
    {
        $owner = $this->resolveOwnerName($name);

        if ('' === $owner) {
            return;
        }

        $this->ownerUsageCollector->collect(
            sourceSymbol: $this->state->sourceSymbol(),
            target: $owner,
            type: $type,
            sourceNodeId: SourceNodeId::fromNode($this->state->virtualFilePath(), $name),
        );
    }

    /**
     * Resolves an owner name node with NameResolver attributes when available.
     *
     * @param Name $name the owner name node
     */
    private function resolveOwnerName(Name $name): string
    {
        $lowerName = $name->toLowerString();

        if ('self' === $lowerName || 'static' === $lowerName || 'parent' === $lowerName) {
            return '';
        }

        $resolvedName = $name->getAttribute('resolvedName');

        if ($resolvedName instanceof Name) {
            return $resolvedName->toString();
        }

        $namespacedName = $name->getAttribute('namespacedName');

        if ($namespacedName instanceof Name) {
            return $namespacedName->toString();
        }

        if (is_string($namespacedName) && '' !== $namespacedName) {
            return $namespacedName;
        }

        return $name->toString();
    }

    /**
     * Leaves the current class-like node.
     */
    private function leaveClassLikeNode(): void
    {
        $this->state->leaveClassLike();
        $this->state->popTemplateDefinitions();
    }

    /**
     * Leaves the current class method node.
     */
    private function leaveClassMethodNode(): void
    {
        $this->state->leaveMethod();
        $this->state->popTemplateDefinitions();
    }

    /**
     * Collects one class constant fetch usage.
     *
     * @param ClassConstFetch $node the class constant fetch node
     */
    private function collectClassConstantFetchUsage(ClassConstFetch $node): void
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return;
        }

        $resolvedOwners = $this->expressionTypeResolver->resolve(
            $node,
            $this->state->variableTypes(),
            $this->state->currentClass(),
            $this->state->currentTemplateDefinitions(),
            $this->usesByAlias
        );

        if ($resolvedOwners->isEmpty()) {
            $resolvedOwners = $this->resolveStaticCallOwner($node->class);
        }

        $this->memberUsageCollector->collectClassConstantFetch(
            $this->state->sourceSymbol(),
            $resolvedOwners,
            $node->name->toString(),
            SourceNodeId::fromNode($this->state->virtualFilePath(), $node),
        );
    }

    /**
     * Collects one global or namespaced constant fetch usage.
     *
     * @param ConstFetch $node the constant fetch node
     */
    private function collectConstantFetchUsage(ConstFetch $node): void
    {
        if ($this->isNativeConstantFetch($node)) {
            return;
        }

        $this->memberUsageCollector->collectConstantFetch(
            $this->state->sourceSymbol(),
            $this->resolveConstantFetchName($node->name),
            SourceNodeId::fromNode($this->state->virtualFilePath(), $node),
        );
    }

    /**
     * Indicates whether a constant fetch targets a native language constant.
     *
     * @param ConstFetch $node the constant fetch node
     */
    private function isNativeConstantFetch(ConstFetch $node): bool
    {
        return in_array($node->name->toLowerString(), ['true', 'false', 'null'], true);
    }

    /**
     * Resolves a constant fetch name with NameResolver attributes when available.
     *
     * @param Name $name the constant fetch name
     */
    private function resolveConstantFetchName(Name $name): string
    {
        $resolvedName = $name->getAttribute('resolvedName');

        if ($resolvedName instanceof Name) {
            return $resolvedName->toString();
        }

        $namespacedName = $name->getAttribute('namespacedName');

        if ($namespacedName instanceof Name) {
            return $namespacedName->toString();
        }

        if (is_string($namespacedName) && '' !== $namespacedName) {
            return $namespacedName;
        }

        if ($name->isFullyQualified() || '' === $this->state->currentNamespace()) {
            return ltrim($name->toString(), '\\');
        }

        return $this->state->currentNamespace().'\\'.$name->toString();
    }

    /**
     * Collects one property fetch usage.
     *
     * @param PropertyFetch $node the property fetch node
     */
    private function collectPropertyFetchUsage(PropertyFetch $node): void
    {
        if (!$node->name instanceof Identifier) {
            return;
        }

        $owners = $this->resolveExprTypes($node->var);

        $this->memberUsageCollector->collectPropertyFetch(
            $this->state->sourceSymbol(),
            $owners,
            $node->name->toString(),
            SourceNodeId::fromNode($this->state->virtualFilePath(), $node),
        );
    }

    /**
     * Collects one static property fetch usage.
     *
     * @param StaticPropertyFetch $node the static property fetch node
     */
    private function collectStaticPropertyFetchUsage(StaticPropertyFetch $node): void
    {
        if (!$node->class instanceof Name) {
            return;
        }

        $name = $node->name instanceof VarLikeIdentifier ? $node->name->toString() : 'unknown';
        $resolvedOwners = $this->resolveStaticPropertyFetchOwners($node->class, $name);

        $this->memberUsageCollector->collectStaticPropertyFetch(
            $this->state->sourceSymbol(),
            $resolvedOwners,
            $name,
            SourceNodeId::fromNode($this->state->virtualFilePath(), $node),
        );
    }

    /**
     * Collects one method or nullsafe method call usage.
     *
     * @param MethodCall|NullsafeMethodCall $node the method call node
     */
    private function collectMethodCallUsage(MethodCall|NullsafeMethodCall $node): void
    {
        if (!$node->name instanceof Identifier) {
            return;
        }

        $methodName = $node->name->toString();
        $owners = $this->resolveExprTypes($node->var);

        foreach ($owners as $owner) {
            $this->memberUsageCollector->collectMethodWithPolymorphism(
                sourceSymbol: $this->state->sourceSymbol(),
                owner: $owner,
                methodName: $methodName,
                usageType: MemberUsageType::METHOD_CALL,
                sourceNodeId: SourceNodeId::fromNode($this->state->virtualFilePath(), $node),
            );

            $this->parameterUsageCollector->collectMethodLikeNamedArgumentsWithPolymorphism(
                sourceSymbol: $this->state->sourceSymbol(),
                owner: $owner,
                functionLikeName: $methodName,
                args: $this->callArguments($node->args),
            );
        }
    }

    /**
     * Collects one static method call usage.
     *
     * @param StaticCall $node the static call node
     */
    private function collectStaticCallUsage(StaticCall $node): void
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return;
        }

        $owner = $this->resolveSingleStaticCallOwner($node->class);
        $methodName = $node->name->toString();

        $this->memberUsageCollector->collectMethodWithPolymorphism(
            sourceSymbol: $this->state->sourceSymbol(),
            owner: $owner,
            methodName: $methodName,
            usageType: MemberUsageType::STATIC_METHOD_CALL,
            sourceNodeId: SourceNodeId::fromNode($this->state->virtualFilePath(), $node),
        );

        $this->parameterUsageCollector->collectMethodLikeNamedArgumentsWithPolymorphism(
            sourceSymbol: $this->state->sourceSymbol(),
            owner: $owner,
            functionLikeName: $methodName,
            args: $this->callArguments($node->args),
        );
    }

    /**
     * Collects one function call usage.
     *
     * @param FuncCall $node the function call node
     */
    private function collectFunctionCallUsage(FuncCall $node): void
    {
        if (!$node->name instanceof Name) {
            return;
        }

        $functionName = $this->resolveFunctionName($node->name);

        $this->memberUsageCollector->collectFunctionCall(
            $this->state->sourceSymbol(),
            $functionName,
            SourceNodeId::fromNode($this->state->virtualFilePath(), $node),
        );

        $this->parameterUsageCollector->collectFunctionNamedArguments(
            $this->state->sourceSymbol(),
            $functionName,
            $this->callArguments($node->args),
        );
    }

    /**
     * Leaves the current function node.
     */
    private function leaveFunctionNode(): void
    {
        $this->state->leaveFunction();
        $this->state->popTemplateDefinitions();
    }

    /**
     * Collects template definitions declared by a class-like or function-like node.
     *
     * @param ClassMethod|Function_|Class_|Trait_|Interface_|Enum_ $node the node carrying template PHPDoc
     */
    private function collectTemplateDefinitions(
        ClassMethod|Function_|Class_|Trait_|Interface_|Enum_ $node,
    ): void {
        $parent = $this->state->currentTemplateDefinitions();
        $current = $this->phpDocTemplateDefinitionExtractor->extract(
            $node,
            $this->state->currentNamespace(),
            $this->usesByAlias,
            $parent,
            $this->state->context(),
            PhpDocTagKind::TEMPLATE
        );

        $this->state->pushTemplateDefinitions($parent->merge($current));
    }

    /**
     * Resolves the best-known owner type for one expression.
     *
     * @param Node $node the expression node
     */
    private function resolveExprTypes(Node $node): SymbolCollection
    {
        $types = $this->expressionTypeResolver->resolve(
            expression: $node,
            variableTypes: $this->state->variableTypes(),
            currentClass: $this->state->currentClass(),
            templateDefinitions: $this->state->currentTemplateDefinitions(),
            usesByAlias: $this->usesByAlias,
        );

        if ($types->isEmpty()) {
            $types->add('unknown');
        }

        return $types;
    }

    /**
     * Resolves the effective owner of one static call.
     *
     * @param Name $className the static call class part
     */
    private function resolveStaticCallOwner(Name $className): SymbolCollection
    {
        $lowerName = $className->toLowerString();

        $owners = new SymbolCollection();

        if ('self' === $lowerName || 'static' === $lowerName) {
            return $owners->add($this->state->currentClass());
        }

        if ('parent' === $lowerName) {
            return $owners->add($this->context->knownOwners->get($this->state->currentClass())->parentFqcn ?? '');
        }

        $resolvedName = $className->getAttribute('resolvedName');

        if ($resolvedName instanceof Name) {
            return $owners->add($resolvedName->toString());
        }

        return $owners->add($className->toString());
    }

    /**
     * Resolves one static call owner, falling back to unknown when no owner can be resolved.
     *
     * @param Name $className the static call class part
     */
    private function resolveSingleStaticCallOwner(Name $className): string
    {
        $owners = $this->resolveStaticCallOwner($className);

        if (!$owners->isEmpty()) {
            $owner = $owners->first();

            if (null !== $owner) {
                return $owner;
            }
        }

        return 'unknown';
    }

    /**
     * Resolves the declaring owner of one static property fetch.
     *
     * @param Name   $className    the static property class part
     * @param string $propertyName the static property name without "$"
     */
    private function resolveStaticPropertyFetchOwners(Name $className, string $propertyName): SymbolCollection
    {
        $owners = new SymbolCollection();

        foreach ($this->resolveStaticCallOwner($className) as $owner) {
            $current = $owner;
            $visited = [];

            while ('' !== $current && !isset($visited[$current])) {
                $visited[$current] = true;

                if (!$this->context->propertyTypeIndex->get($current, $propertyName)->isEmpty()) {
                    $owners->add($current);
                    break;
                }

                $knownOwner = $this->context->knownOwners->get($current);
                $current = $knownOwner->parentFqcn ?? '';
            }
        }

        if (!$owners->isEmpty()) {
            return $owners;
        }

        return $this->resolveStaticCallOwner($className);
    }

    /**
     * Resolves a function call name from PHPParser name attributes when available.
     *
     * @param Name $name the function call name node
     */
    private function resolveFunctionName(Name $name): string
    {
        $resolvedName = $name->getAttribute('resolvedName');

        if ($resolvedName instanceof Name) {
            return $resolvedName->toString();
        }

        $namespacedName = $name->getAttribute('namespacedName');

        if ($namespacedName instanceof Name) {
            return $namespacedName->toString();
        }

        if (is_string($namespacedName) && '' !== $namespacedName) {
            return $namespacedName;
        }

        return $name->toString();
    }

    /**
     * Filters parser call arguments to concrete argument nodes.
     *
     * @param array<array-key, Arg|VariadicPlaceholder> $arguments the parser arguments
     *
     * @return array<int, Arg>
     */
    private function callArguments(array $arguments): array
    {
        return array_values(array_filter($arguments, static fn (mixed $argument): bool => $argument instanceof Arg));
    }
}
