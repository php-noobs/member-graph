<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Domain\Usage;

/**
 * Enum representing usage types.
 */
enum MemberUsageType
{
    case METHOD_CALL;
    case STATIC_METHOD_CALL;
    case PROPERTY_FETCH;
    case STATIC_PROPERTY_FETCH;
    case CLASS_CONST_FETCH;
    case FUNCTION_CALL;
    case PARAMETER_NAMED_ARG;
    case CONSTANT_FETCH;
}
