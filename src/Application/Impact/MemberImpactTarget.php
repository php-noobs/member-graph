<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Impact;

use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterId;

/**
 * Represents one member-impact query target.
 */
final readonly class MemberImpactTarget
{
    /**
     * Constructor.
     *
     * @param MemberId|null    $memberId    the targeted member identifier
     * @param ParameterId|null $parameterId the targeted parameter identifier
     * @param string|null      $owner       the targeted class-like owner FQCN
     */
    private function __construct(
        public ?MemberId $memberId,
        public ?ParameterId $parameterId,
        public ?string $owner,
    ) {
    }

    /**
     * Creates an owner impact target.
     *
     * @param string $owner the class-like owner FQCN
     */
    public static function owner(string $owner): self
    {
        return new self(
            memberId: null,
            parameterId: null,
            owner: $owner,
        );
    }

    /**
     * Creates a method impact target.
     *
     * @param string $owner the method owner FQCN
     * @param string $name  the method name
     */
    public static function method(string $owner, string $name): self
    {
        return self::member($owner, $name, MemberType::METHOD);
    }

    /**
     * Creates a property impact target.
     *
     * @param string $owner the property owner FQCN
     * @param string $name  the property name
     */
    public static function property(string $owner, string $name): self
    {
        return self::member($owner, $name, MemberType::PROPERTY);
    }

    /**
     * Creates a class-constant impact target.
     *
     * @param string $owner the class-constant owner FQCN
     * @param string $name  the class-constant name
     */
    public static function classConstant(string $owner, string $name): self
    {
        return self::member($owner, $name, MemberType::CLASS_CONSTANT);
    }

    /**
     * Creates a function impact target.
     *
     * @param string $name the fully-qualified function name
     */
    public static function forFunction(string $name): self
    {
        return self::member('', $name, MemberType::FUNCTION_);
    }

    /**
     * Creates a parameter impact target.
     *
     * @param string   $owner            the owner FQCN, or an empty string for functions
     * @param string   $functionLikeName the method name or fully-qualified function name
     * @param string   $parameterName    the parameter name without "$"
     * @param int|null $parameterIndex   the optional zero-based declaration index
     */
    public static function parameter(
        string $owner,
        string $functionLikeName,
        string $parameterName,
        ?int $parameterIndex = null,
    ): self {
        return new self(
            memberId: null,
            parameterId: new ParameterId($owner, $functionLikeName, $parameterName, $parameterIndex),
            owner: null,
        );
    }

    /**
     * Creates a member impact target.
     *
     * @param string     $owner the member owner FQCN
     * @param string     $name  the member name
     * @param MemberType $type  the member type
     */
    private static function member(string $owner, string $name, MemberType $type): self
    {
        return new self(
            memberId: new MemberId($owner, $name, $type),
            parameterId: null,
            owner: null,
        );
    }
}
