<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Infrastructure\PhpDoc\Inheritance;

use PhpNoobs\MemberGraph\Application\Issue\MemberGraphIssueCollection;
use PhpNoobs\MemberGraph\Application\Validator\PhpDoc\PhpDocIssueCollection;
use PhpNoobs\MemberGraph\Application\Validator\PhpDoc\PhpDocResolutionIssueType;
use PhpNoobs\MemberGraph\Application\Validator\PhpDoc\PhpDocValidityChecker;
use PhpNoobs\MemberGraph\Application\Validator\PhpDoc\SemanticState;
use PhpNoobs\MemberGraph\Domain\Index\Template\PhpDocTemplateDefinitionCollection;
use PhpNoobs\MemberGraph\Domain\Type\TypeIndexContext;
use PhpNoobs\MemberGraph\Infrastructure\PhpDoc\Extractor\ParamPhpDocTypeExtractor;
use PhpNoobs\MemberGraph\Infrastructure\PhpDoc\Extractor\PhpDocTemplateDefinitionExtractor;
use PhpNoobs\MemberGraph\Infrastructure\PhpDoc\Extractor\ReturnPhpDocTypeExtractor;
use PhpNoobs\MemberGraph\Infrastructure\PhpDoc\PhpDocHelper;
use PhpNoobs\MemberGraph\Infrastructure\PhpDoc\Resolver\PhpDocTagKind;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;

/**
 * Resolves effective PHPDoc when {@±inheritDoc} is used.
 */
final class PhpDocInheritDocResolver
{
    /**
     * Current type-index context.
     */
    private TypeIndexContext $currentTypeIndexContext;

    /**
     * Constructor.
     *
     * @param Lexer                             $lexer                             the PHPDoc lexer
     * @param PhpDocParser                      $phpDocParser                      the PHPDoc parser
     * @param PhpDocValidityChecker             $phpDocValidityChecker             the semantic validity checker
     * @param ParamPhpDocTypeExtractor          $paramPhpDocTypeExtractor          the parameter PHPDoc extractor
     * @param ReturnPhpDocTypeExtractor         $returnPhpDocTypeExtractor         the return PHPDoc extractor
     * @param PhpDocTemplateDefinitionExtractor $phpDocTemplateDefinitionExtractor the template definition extractor
     * @param MemberGraphIssueCollection|null   $issues                            the optional dependency-graph issue collection
     */
    public function __construct(
        private readonly Lexer $lexer,
        private readonly PhpDocParser $phpDocParser,
        private readonly PhpDocValidityChecker $phpDocValidityChecker,
        private readonly ParamPhpDocTypeExtractor $paramPhpDocTypeExtractor,
        private readonly ReturnPhpDocTypeExtractor $returnPhpDocTypeExtractor,
        private readonly PhpDocTemplateDefinitionExtractor $phpDocTemplateDefinitionExtractor,
        private readonly ?MemberGraphIssueCollection $issues = null,
    ) {
    }

    /**
     * Resolves the effective doc comment for one child node against one parent node.
     *
     * This helper keeps the original V1 behavior and is intentionally simple.
     *
     * @param Node      $childNode  the current node
     * @param Node|null $parentNode the inherited parent node, when available
     */
    public function resolve(Node $childNode, ?Node $parentNode): ?Doc
    {
        $childDoc = self::getEffectiveDocComment($childNode);
        $parentDoc = self::getEffectiveDocComment($parentNode);

        if (!$childDoc instanceof Doc) {
            return $parentDoc instanceof Doc ? $parentDoc : null;
        }

        if (!$this->containsInheritDoc($childDoc)) {
            return $childDoc;
        }

        if ($parentDoc instanceof Doc) {
            return $parentDoc;
        }

        return $childDoc;
    }

    /**
     * Resolves the effective merged doc comment by walking the parent chain.
     *
     * Strategy:
     * - if the child has no doc, the first valid resolved parent doc is returned
     * - if the child doc has no marker, the child doc is returned as-is
     * - if the child doc has @±inheritDoc, each parent is considered in order
     * - if a parent also contains @±inheritDoc, it is first resolved recursively against the remaining parents
     * - the first valid merged result is returned
     * - issues are collected for unusable parents, incoherent merges, and missing valid parent docs
     *
     * @param Node             $childNode        the child node
     * @param Node[]           $parentNodes      the inherited parent nodes ordered from nearest to farthest
     * @param TypeIndexContext $typeIndexContext the type index context
     */
    public function mergeEffectiveDoc(
        Node $childNode,
        array $parentNodes,
        TypeIndexContext $typeIndexContext,
    ): ?Doc {
        $this->currentTypeIndexContext = $typeIndexContext;
        $childDoc = self::getEffectiveDocComment($childNode);

        if (!$childDoc instanceof Doc) {
            return $this->resolveFirstValidParentDoc(
                childNode: $childNode,
                parentNodes: $parentNodes,
            );
        }

        if (!$this->containsInheritDoc($childDoc)) {
            return $childDoc;
        }

        foreach ($parentNodes as $index => $parentNode) {
            $remainingParents = array_slice($parentNodes, $index + 1);
            $parentDoc = $this->resolveParentDocCandidate(
                parentNode: $parentNode,
                remainingParentNodes: $remainingParents,
            );

            if (!$parentDoc instanceof Doc) {
                continue;
            }

            if (!$this->isValidInheritedDoc(
                node: $childNode,
                doc: $parentDoc,
            )) {
                $this->addIssue(PhpDocResolutionIssueType::INHERIT_DOC_PARENT_NOT_USABLE);

                continue;
            }

            $mergedDoc = $this->mergeTwoDocs($childDoc, $parentDoc);

            $problems = $this->collectSemanticProblems(
                node: $childNode,
                doc: $mergedDoc,
            );

            if ([] === $problems) {
                return $mergedDoc;
            }

            foreach ($problems as $problem) {
                $this->addIssue($problem);
            }

            $this->addIssue(PhpDocResolutionIssueType::INHERIT_DOC_MERGE_INCOHERENT);
        }

        $this->addIssue(PhpDocResolutionIssueType::INHERIT_DOC_PARENT_NOT_FOUND);

        return null;
    }

    /**
     * Resolves the first valid parent doc for one child node.
     *
     * @param Node   $childNode   the child node
     * @param Node[] $parentNodes the ordered parent nodes
     */
    private function resolveFirstValidParentDoc(
        Node $childNode,
        array $parentNodes,
    ): ?Doc {
        foreach ($parentNodes as $index => $parentNode) {
            $remainingParents = array_slice($parentNodes, $index + 1);
            $parentDoc = $this->resolveParentDocCandidate(
                parentNode: $parentNode,
                remainingParentNodes: $remainingParents,
            );

            if (!$parentDoc instanceof Doc) {
                continue;
            }

            if ($this->isValidInheritedDoc(
                node: $childNode,
                doc: $parentDoc,
            )) {
                return $parentDoc;
            }

            $this->addIssue(PhpDocResolutionIssueType::INHERIT_DOC_PARENT_NOT_USABLE);
        }

        if ([] !== $parentNodes) {
            $this->addIssue(PhpDocResolutionIssueType::INHERIT_DOC_PARENT_NOT_FOUND);
        }

        return null;
    }

    /**
     * Resolves one parent doc candidate.
     *
     * If the parent itself contains , the method resolves it recursively
     * against the remaining parent nodes.
     *
     * @param Node   $parentNode           the parent node to inspect
     * @param Node[] $remainingParentNodes the remaining parent nodes after the current one
     */
    private function resolveParentDocCandidate(
        Node $parentNode,
        array $remainingParentNodes,
    ): ?Doc {
        $parentDoc = self::getEffectiveDocComment($parentNode);

        if (!$parentDoc instanceof Doc) {
            return null;
        }

        if (!$this->containsInheritDoc($parentDoc)) {
            return $parentDoc;
        }

        return $this->mergeEffectiveDoc(
            childNode: $parentNode,
            parentNodes: $remainingParentNodes,
            typeIndexContext: $this->currentTypeIndexContext,
        );
    }

    /**
     * Adds one issue to the dependency-graph issue collection when available.
     *
     * @param PhpDocResolutionIssueType $issueType the issue type
     */
    public function addIssue(PhpDocResolutionIssueType $issueType): void
    {
        PhpDocIssueCollection::add(
            $this->issues,
            $issueType,
            $this->currentTypeIndexContext->fullFilePath,
            $this->currentTypeIndexContext->owner,
            $this->currentTypeIndexContext->member
        );
    }

    /**
     * Returns whether one inherited doc candidate is valid.
     *
     * @param Node $node the related node
     * @param Doc  $doc  the candidate doc
     */
    private function isValidInheritedDoc(
        Node $node,
        Doc $doc,
    ): bool {
        return [] === $this->collectSemanticProblems(
            node: $node,
            doc: $doc,
        );
    }

    /**
     * Collects semantic problems for one resolved doc candidate.
     *
     * @param Node $node the related node
     * @param Doc  $doc  the doc to validate
     *
     * @return list<PhpDocResolutionIssueType>
     */
    private function collectSemanticProblems(
        Node $node,
        Doc $doc,
    ): array {
        $semanticState = $this->buildSemanticStateFromDoc(
            node: $node,
            doc: $doc,
        );

        return array_values($this->phpDocValidityChecker->collectSemanticProblems($semanticState));
    }

    /**
     * Builds the semantic state from one effective doc candidate.
     *
     * @param Node $node the related node
     * @param Doc  $doc  the doc to analyze
     */
    private function buildSemanticStateFromDoc(
        Node $node,
        Doc $doc,
    ): SemanticState {
        $previousEffectiveDoc = $node->getAttribute('effectiveDoc');
        self::setEffectiveDocComment($node, $doc);
        $phpDocNode = $this->parsePhpDocNode($doc);

        try {
            $templates = $this->phpDocTemplateDefinitionExtractor->extract(
                node: $node,
                currentNamespace: $this->currentTypeIndexContext->namespace,
                usesByAlias: $this->currentTypeIndexContext->usesByAlias,
                visibleTemplateDefinitions: new PhpDocTemplateDefinitionCollection(),
                context: $this->currentTypeIndexContext,
                phpDocTagKind: PhpDocTagKind::TEMPLATE
            );
            $hasTemplate = $phpDocNode instanceof PhpDocNode && PhpDocHelper::hasTag($phpDocNode, '@template');

            $returnType = $this->returnPhpDocTypeExtractor->extractStructured(
                node: $node,
                currentNamespace: $this->currentTypeIndexContext->namespace,
                usesByAlias: $this->currentTypeIndexContext->usesByAlias,
                templateDefinitions: $templates,
                context: $this->currentTypeIndexContext
            );
            $hasReturnType = $phpDocNode instanceof PhpDocNode && PhpDocHelper::hasTag($phpDocNode, '@return');

            $params = $this->paramPhpDocTypeExtractor->extract(
                node: $node,
                currentNamespace: $this->currentTypeIndexContext->namespace,
                usesByAlias: $this->currentTypeIndexContext->usesByAlias,
                templateDefinitions: $templates,
                context: $this->currentTypeIndexContext
            );
            $hasParam = $phpDocNode instanceof PhpDocNode && PhpDocHelper::hasTag($phpDocNode, '@param');
        } finally {
            $node->setAttribute('effectiveDoc', $previousEffectiveDoc);
        }

        $paramsByName = [];

        foreach ($params as $parameterName => $resolvedType) {
            $paramsByName[$parameterName] = $resolvedType->structuredType;
        }

        return new SemanticState(
            templates: $templates,
            hasTemplate: $hasTemplate,
            paramsByName: $paramsByName,
            hasParam: $hasParam,
            returnType: $returnType,
            hasReturnType: $hasReturnType,
        );
    }

    /**
     * Stores one effective doc comment on one node.
     *
     * @param Node     $methodNode the node to update
     * @param Doc|null $doc        the effective doc comment
     */
    public static function setEffectiveDocComment(Node $methodNode, ?Doc $doc): void
    {
        if (null !== $doc) {
            $methodNode->setAttribute('effectiveDoc', $doc);
        }
    }

    /**
     * Returns the effective doc comment for one node.
     *
     * @param Node|null $node the node to inspect
     */
    public static function getEffectiveDocComment(?Node $node): ?Doc
    {
        if (null === $node) {
            return null;
        }

        $effectiveDoc = $node->getAttribute('effectiveDoc');

        if ($effectiveDoc instanceof Doc) {
            return $effectiveDoc;
        }

        $docComment = $node->getDocComment();

        return $docComment instanceof Doc ? $docComment : null;
    }

    /**
     * Parses one doc comment into one PhpDocNode.
     *
     * @param Doc $doc the doc comment to parse
     */
    private function parsePhpDocNode(Doc $doc): ?PhpDocNode
    {
        try {
            $tokens = new TokenIterator($this->lexer->tokenize($doc->getText()));

            return $this->phpDocParser->parse($tokens);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Returns whether one doc comment contains {@±inheritdoc} or @±inheritdoc.
     *
     * @param Doc $doc the doc comment to inspect
     */
    private function containsInheritDoc(Doc $doc): bool
    {
        $text = strtolower($doc->getText());

        return str_contains($text, '@inheritdoc')
            || str_contains($text, '{@inheritdoc}');
    }

    /**
     * Merges two PHPDoc blocks without validation.
     *
     * Child overrides parent.
     *
     * @param Doc $childDoc  the child doc block
     * @param Doc $parentDoc the parent doc block
     */
    private function mergeTwoDocs(Doc $childDoc, Doc $parentDoc): Doc
    {
        $childLines = $this->extractDocLines($childDoc);
        $parentLines = $this->extractDocLines($parentDoc);

        $childTags = $this->groupTags($childLines);
        $parentTags = $this->groupTags($parentLines);

        $merged = [];

        $merged['template'] = array_merge(
            $parentTags['template'] ?? [],
            $childTags['template'] ?? [],
        );

        $merged['param'] = $parentTags['param'] ?? [];

        foreach ($childTags['param'] ?? [] as $childParamLine) {
            $name = $this->extractParamName($childParamLine);

            if (null === $name) {
                continue;
            }

            $merged['param'][$name] = $childParamLine;
        }

        if (!empty($childTags['return'])) {
            $merged['return'] = $childTags['return'];
        } else {
            $merged['return'] = $parentTags['return'] ?? [];
        }

        foreach ($childTags as $tag => $lines) {
            if (in_array(strtolower($tag), ['param', 'return', 'template', 'inheritdoc'], true)) {
                continue;
            }

            $merged[$tag] = $lines;
        }

        foreach ($parentTags as $tag => $lines) {
            if (!isset($merged[$tag])) {
                $merged[$tag] = $lines;
            }
        }

        $lines = ['/**'];

        foreach ($merged as $entries) {
            foreach ($entries as $entry) {
                $lines[] = ' * '.$entry;
            }
        }

        $lines[] = ' */';

        return new Doc(implode("\n", $lines));
    }

    /**
     * Extracts the significant lines of one doc comment.
     *
     * @param Doc $doc the doc comment to normalize
     *
     * @return string[]
     */
    private function extractDocLines(Doc $doc): array
    {
        $raw = $doc->getText();
        $lines = preg_split('/\R/', $raw);
        $clean = [];

        if (false === $lines) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            $line = ltrim($line, '/* ');
            $line = rtrim($line, '*/ ');

            if ('' !== $line) {
                $clean[] = $line;
            }
        }

        return $clean;
    }

    /**
     * Groups raw doc lines by tag kind.
     *
     * @param string[] $lines the normalized doc lines
     *
     * @return array<string, string[]>
     */
    private function groupTags(array $lines): array
    {
        $grouped = [];

        foreach ($lines as $line) {
            if (!str_starts_with($line, '@')) {
                $grouped['description'][] = $line;
                continue;
            }

            [$tag] = explode(' ', $line, 2);
            $tag = ltrim($tag, '@');

            if ('param' === $tag) {
                $name = $this->extractParamName($line);

                if (null !== $name) {
                    $grouped['param'][$name] = $line;
                }

                continue;
            }

            $grouped[$tag][] = $line;
        }

        return $grouped;
    }

    /**
     * Extracts the parameter name from one @±param line.
     *
     * @param string $line the raw @±param line
     */
    private function extractParamName(string $line): ?string
    {
        if (!preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)\b/', $line, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
