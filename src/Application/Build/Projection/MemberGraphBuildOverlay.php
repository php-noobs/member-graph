<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Build\Projection;

use PhpNoobs\MemberGraph\Domain\Graph\MemberType;

/**
 * Carries semantic identity updates used to project one member graph build.
 */
final readonly class MemberGraphBuildOverlay
{
    /**
     * Constructor.
     *
     * @param list<MemberGraphOwnerIdentityUpdate>     $ownerUpdates     the owner identity updates
     * @param list<MemberGraphMemberIdentityUpdate>    $memberUpdates    the member identity updates
     * @param list<MemberGraphParameterIdentityUpdate> $parameterUpdates the parameter identity updates
     */
    private function __construct(
        private array $ownerUpdates = [],
        private array $memberUpdates = [],
        private array $parameterUpdates = [],
    ) {
    }

    /**
     * Creates an empty build overlay.
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Returns a copy with one owner identity update.
     *
     * @param string $owner    the current owner identity to update
     * @param string $newOwner the projected owner identity
     */
    public function withOwnerUpdate(string $owner, string $newOwner): self
    {
        return new self(
            ownerUpdates: [
                ...$this->ownerUpdates,
                new MemberGraphOwnerIdentityUpdate($owner, $newOwner),
            ],
            memberUpdates: $this->memberUpdates,
            parameterUpdates: $this->parameterUpdates,
        );
    }

    /**
     * Returns a copy with one member identity update.
     *
     * @param MemberType $type    the member type to update
     * @param string     $owner   the current owner identity
     * @param string     $name    the current member name
     * @param string     $newName the projected member name
     */
    public function withMemberUpdate(
        MemberType $type,
        string $owner,
        string $name,
        string $newName,
    ): self {
        return new self(
            ownerUpdates: $this->ownerUpdates,
            memberUpdates: [
                ...$this->memberUpdates,
                new MemberGraphMemberIdentityUpdate($type, $owner, $name, $newName),
            ],
            parameterUpdates: $this->parameterUpdates,
        );
    }

    /**
     * Returns a copy with one parameter identity update.
     *
     * @param string   $owner            the current owner identity, or an empty string for functions
     * @param string   $functionLikeName the current method name or function FQCN
     * @param string   $parameterName    the current parameter name without "$"
     * @param string   $newParameterName the projected parameter name without "$"
     * @param int|null $parameterIndex   the optional zero-based declaration index
     */
    public function withParameterUpdate(
        string $owner,
        string $functionLikeName,
        string $parameterName,
        string $newParameterName,
        ?int $parameterIndex = null,
    ): self {
        return new self(
            ownerUpdates: $this->ownerUpdates,
            memberUpdates: $this->memberUpdates,
            parameterUpdates: [
                ...$this->parameterUpdates,
                new MemberGraphParameterIdentityUpdate(
                    owner: $owner,
                    functionLikeName: $functionLikeName,
                    parameterName: $parameterName,
                    newParameterName: $newParameterName,
                    parameterIndex: $parameterIndex,
                ),
            ],
        );
    }

    /**
     * Returns a copy with one method identity update.
     *
     * @param string $owner   the current method owner identity
     * @param string $name    the current method name
     * @param string $newName the projected method name
     */
    public function withMethodUpdate(string $owner, string $name, string $newName): self
    {
        return $this->withMemberUpdate(MemberType::METHOD, $owner, $name, $newName);
    }

    /**
     * Returns a copy with one property identity update.
     *
     * @param string $owner   the current property owner identity
     * @param string $name    the current property name
     * @param string $newName the projected property name
     */
    public function withPropertyUpdate(string $owner, string $name, string $newName): self
    {
        return $this->withMemberUpdate(MemberType::PROPERTY, $owner, $name, $newName);
    }

    /**
     * Returns a copy with one class-constant identity update.
     *
     * @param string $owner   the current class-constant owner identity
     * @param string $name    the current class-constant name
     * @param string $newName the projected class-constant name
     */
    public function withClassConstantUpdate(string $owner, string $name, string $newName): self
    {
        return $this->withMemberUpdate(MemberType::CLASS_CONSTANT, $owner, $name, $newName);
    }

    /**
     * Returns a copy with one enum-case identity update.
     *
     * @param string $owner   the current enum owner identity
     * @param string $name    the current enum-case name
     * @param string $newName the projected enum-case name
     */
    public function withEnumCaseUpdate(string $owner, string $name, string $newName): self
    {
        return $this->withMemberUpdate(MemberType::CLASS_CONSTANT, $owner, $name, $newName);
    }

    /**
     * Returns a copy with one function identity update.
     *
     * @param string $name    the current function FQCN
     * @param string $newName the projected function FQCN
     */
    public function withFunctionUpdate(string $name, string $newName): self
    {
        return $this->withMemberUpdate(MemberType::FUNCTION_, '', $name, $newName);
    }

    /**
     * Returns a copy with one namespace-level constant identity update.
     *
     * @param string $name    the current constant FQCN
     * @param string $newName the projected constant FQCN
     */
    public function withNamespaceConstantUpdate(string $name, string $newName): self
    {
        return $this->withMemberUpdate(MemberType::CONSTANT, '', $name, $newName);
    }

    /**
     * Returns recorded owner identity updates.
     *
     * @return list<MemberGraphOwnerIdentityUpdate>
     */
    public function ownerUpdates(): array
    {
        return $this->ownerUpdates;
    }

    /**
     * Returns recorded member identity updates.
     *
     * @return list<MemberGraphMemberIdentityUpdate>
     */
    public function memberUpdates(): array
    {
        return $this->memberUpdates;
    }

    /**
     * Returns recorded parameter identity updates.
     *
     * @return list<MemberGraphParameterIdentityUpdate>
     */
    public function parameterUpdates(): array
    {
        return $this->parameterUpdates;
    }
}
