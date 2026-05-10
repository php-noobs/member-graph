<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Locates neutral source facts belonging to one function-like parameter scope.
 */
final readonly class ParameterScopeNodeLocator
{
    /**
     * Locates local variable declarations or assignments in one function-like body.
     *
     * @param ClassMethod|Function_ $functionLike the function-like declaration to inspect
     *
     * @return list<Variable>
     */
    public function localVariables(ClassMethod|Function_ $functionLike): array
    {
        $variables = [];

        foreach ($functionLike->stmts ?? [] as $statement) {
            $this->collectLocalVariablesFromNode($statement, $variables);
        }

        return $variables;
    }

    /**
     * Collects local variable declarations or assignments from one node.
     *
     * @param Node           $node      the node to inspect
     * @param list<Variable> $variables the collected local variable nodes
     */
    private function collectLocalVariablesFromNode(Node $node, array &$variables): void
    {
        if (
            $node instanceof ClassMethod
            || $node instanceof Function_
            || $node instanceof Class_
            || $node instanceof Closure
            || $node instanceof ArrowFunction
        ) {
            return;
        }

        if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
            $variables[] = $node->var;
        }

        $this->collectLocalVariablesFromChildren($node, $variables);
    }

    /**
     * Collects local variable declarations or assignments from child nodes.
     *
     * @param Node           $node      the parent node to inspect
     * @param list<Variable> $variables the collected local variable nodes
     */
    private function collectLocalVariablesFromChildren(Node $node, array &$variables): void
    {
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node) {
                $this->collectLocalVariablesFromNode($subNode, $variables);

                continue;
            }

            if (!is_array($subNode)) {
                continue;
            }

            foreach ($subNode as $subNodeItem) {
                if ($subNodeItem instanceof Node) {
                    $this->collectLocalVariablesFromNode($subNodeItem, $variables);
                }
            }
        }
    }
}
