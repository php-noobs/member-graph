<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Domain\Graph;

/**
 * Enum representing member types.
 */
enum MemberType
{
    case METHOD;
    case PROPERTY;
    case CLASS_CONSTANT;
    case FUNCTION_;
    case PARAMETER;
    case CONSTANT;
}
