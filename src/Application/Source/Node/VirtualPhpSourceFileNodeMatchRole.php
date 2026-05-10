<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Source\Node;

/**
 * Describes why one source node matched a member graph impact target.
 *
 * Member roles describe graph members themselves: methods, properties, class constants, enum cases, and functions.
 * Parameter roles describe function-like parameters separately so a refactoring caller can distinguish changing a
 * method or function from changing one of its parameters.
 */
enum VirtualPhpSourceFileNodeMatchRole
{
    /**
     * The node declares a class-like owner, including classes, interfaces, traits, and enums.
     */
    case OWNER_DECLARATION;

    /**
     * The node uses a class-like owner through a native PHP class-name reference.
     */
    case OWNER_USAGE;

    /**
     * The node declares a graph member, including promoted-property parameters when they declare a property member.
     */
    case MEMBER_DECLARATION;

    /**
     * The node uses a graph member: method call, property fetch, class-constant fetch, or function call.
     */
    case MEMBER_USAGE;

    /**
     * The node declares a function-like parameter targeted by a parameter impact query.
     */
    case PARAMETER_DECLARATION;

    /**
     * The node uses a parameter through a named argument.
     */
    case PARAMETER_USAGE;

    /**
     * The node uses a function-like parameter as a local variable inside its declaring body.
     */
    case PARAMETER_LOCAL_USAGE;

    /**
     * The node declares one parameter in the same function-like signature as a targeted parameter.
     */
    case PARAMETER_SCOPE_PARAMETER;

    /**
     * The node declares or assigns one local variable in the same function-like body as a targeted parameter.
     */
    case PARAMETER_SCOPE_LOCAL_VARIABLE;
}
