<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

/**
 * Describes the kind of neutral symbol-scope fact exposed by source scope queries.
 */
enum MemberGraphSymbolScopeFactRole
{
    /**
     * The fact is a method declaration in a method scope.
     */
    case METHOD_DECLARATION;

    /**
     * The fact is a property declaration in a property scope.
     */
    case PROPERTY_DECLARATION;

    /**
     * The fact is a class-constant declaration in a class-constant scope.
     */
    case CLASS_CONSTANT_DECLARATION;

    /**
     * The fact is an enum-case declaration in a class-constant scope.
     */
    case ENUM_CASE_DECLARATION;

    /**
     * The fact is a class-like declaration in a namespace scope.
     */
    case CLASS_LIKE_NAMESPACE_DECLARATION;

    /**
     * The fact is a function declaration in a namespace scope.
     */
    case FUNCTION_NAMESPACE_DECLARATION;

    /**
     * The fact is a class-like import in a file scope.
     */
    case CLASS_LIKE_IMPORT;

    /**
     * The fact is a function import in a file scope.
     */
    case FUNCTION_IMPORT;

    /**
     * The fact is a constant import in a file scope.
     */
    case CONSTANT_IMPORT;
}
