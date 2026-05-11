<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Build\Projection;

use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterId;

/**
 * Resolves base and projected identities for one graph projection.
 */
final class MemberGraphProjectionMap
{
    /**
     * @var array<string, string>
     */
    private array $projectedOwnerByBase = [];

    /**
     * @var array<string, string>
     */
    private array $baseOwnerByCurrent = [];

    /**
     * @var array<string, string>
     */
    private array $projectedMemberNameByBaseHash = [];

    /**
     * @var array<string, MemberId>
     */
    private array $baseMemberByCurrentHash = [];

    /**
     * @var array<string, string>
     */
    private array $projectedParameterNameByBaseHash = [];

    /**
     * Constructor.
     *
     * @param MemberGraphBuildOverlay $overlay the graph build overlay
     */
    public function __construct(MemberGraphBuildOverlay $overlay)
    {
        foreach ($overlay->ownerUpdates() as $ownerUpdate) {
            $baseOwner = $this->baseOwner($ownerUpdate->owner);

            $this->projectedOwnerByBase[$baseOwner] = $ownerUpdate->newOwner;
            $this->baseOwnerByCurrent[$ownerUpdate->newOwner] = $baseOwner;
        }

        foreach ($overlay->memberUpdates() as $memberUpdate) {
            $baseOwner = $this->baseOwner($memberUpdate->owner);
            $baseMember = new MemberId($baseOwner, $memberUpdate->name, $memberUpdate->type);
            $projectedMember = new MemberId(
                owner: $this->projectedOwner($baseMember->owner),
                name: $memberUpdate->newName,
                type: $baseMember->type,
            );

            $this->projectedMemberNameByBaseHash[$baseMember->hash()] = $memberUpdate->newName;
            $this->baseMemberByCurrentHash[$projectedMember->hash()] = $baseMember;
        }

        foreach ($overlay->parameterUpdates() as $parameterUpdate) {
            $baseParameter = $this->baseParameter(
                owner: $parameterUpdate->owner,
                functionLikeName: $parameterUpdate->functionLikeName,
                parameterName: $parameterUpdate->parameterName,
                parameterIndex: $parameterUpdate->parameterIndex,
            );

            $this->projectedParameterNameByBaseHash[$baseParameter->hash()] = $parameterUpdate->newParameterName;
            $this->projectedParameterNameByBaseHash[$baseParameter->nameHash()] = $parameterUpdate->newParameterName;
        }
    }

    /**
     * Resolves a current owner identity back to its base identity.
     *
     * @param string $owner the current owner identity
     */
    public function baseOwner(string $owner): string
    {
        return $this->baseOwnerByCurrent[$owner] ?? $owner;
    }

    /**
     * Resolves a base owner identity to its projected identity.
     *
     * @param string $owner the base owner identity
     */
    public function projectedOwner(string $owner): string
    {
        return $this->projectedOwnerByBase[$owner] ?? $owner;
    }

    /**
     * Resolves a base member identity to its projected identity.
     *
     * @param MemberId $member the base member identity
     */
    public function projectedMember(MemberId $member): MemberId
    {
        return new MemberId(
            owner: $this->projectedOwner($member->owner),
            name: $this->projectedMemberName($member),
            type: $member->type,
        );
    }

    /**
     * Records a projected member name for one base member identity.
     *
     * @param MemberId $member  the base member identity
     * @param string   $newName the projected member name
     */
    public function recordProjectedMemberName(MemberId $member, string $newName): void
    {
        $this->projectedMemberNameByBaseHash[$member->hash()] = $newName;
    }

    /**
     * Resolves a base member name to its projected name.
     *
     * @param MemberId $member the base member identity
     */
    public function projectedMemberName(MemberId $member): string
    {
        return $this->projectedMemberNameByBaseHash[$member->hash()] ?? $member->name;
    }

    /**
     * Indicates whether one base member identity has an explicit member-name update.
     *
     * @param MemberId $member the base member identity
     */
    public function hasMemberNameUpdate(MemberId $member): bool
    {
        return isset($this->projectedMemberNameByBaseHash[$member->hash()]);
    }

    /**
     * Builds a base member identity from a current owner identity.
     *
     * @param string     $owner the current owner identity
     * @param string     $name  the current member name
     * @param MemberType $type  the member type
     */
    public function baseMember(string $owner, string $name, MemberType $type): MemberId
    {
        $currentMember = new MemberId($owner, $name, $type);

        return $this->baseMemberByCurrentHash[$currentMember->hash()]
            ?? new MemberId($this->baseOwner($owner), $name, $type);
    }

    /**
     * Builds a base parameter identity from current identities.
     *
     * @param string   $owner            the current owner identity, or an empty string for functions
     * @param string   $functionLikeName the current method name or function FQCN
     * @param string   $parameterName    the current parameter name without "$"
     * @param int|null $parameterIndex   the optional zero-based declaration index
     */
    public function baseParameter(
        string $owner,
        string $functionLikeName,
        string $parameterName,
        ?int $parameterIndex = null,
    ): ParameterId {
        $baseOwner = $this->baseOwner($owner);
        $baseFunctionLikeName = $functionLikeName;

        if ('' !== $owner) {
            $methodMember = $this->baseMember($owner, $functionLikeName, MemberType::METHOD);
            $baseFunctionLikeName = $methodMember->name;
        }

        return new ParameterId(
            owner: $baseOwner,
            functionLikeName: $baseFunctionLikeName,
            parameterName: $parameterName,
            parameterIndex: $parameterIndex,
        );
    }

    /**
     * Resolves a base parameter identity to its projected identity.
     *
     * @param ParameterId $parameter the base parameter identity
     */
    public function projectedParameter(ParameterId $parameter): ParameterId
    {
        $projectedOwner = $this->projectedOwner($parameter->owner);
        $projectedFunctionLikeName = $parameter->functionLikeName;

        if ('' !== $parameter->owner) {
            $projectedFunctionLikeName = $this->projectedMember(new MemberId(
                owner: $parameter->owner,
                name: $parameter->functionLikeName,
                type: MemberType::METHOD,
            ))->name;
        }

        return new ParameterId(
            owner: $projectedOwner,
            functionLikeName: $projectedFunctionLikeName,
            parameterName: $this->projectedParameterName($parameter),
            parameterIndex: $parameter->parameterIndex,
        );
    }

    /**
     * Resolves a base parameter name to its projected name.
     *
     * @param ParameterId $parameter the base parameter identity
     */
    public function projectedParameterName(ParameterId $parameter): string
    {
        return $this->projectedParameterNameByBaseHash[$parameter->hash()]
            ?? $this->projectedParameterNameByBaseHash[$parameter->nameHash()]
            ?? $parameter->parameterName;
    }

    /**
     * Projects an owner-based source symbol.
     *
     * @param string $sourceSymbol the source symbol to project
     */
    public function projectedSourceSymbol(string $sourceSymbol): string
    {
        $separatorPosition = strpos($sourceSymbol, '::');

        if (false === $separatorPosition) {
            return $this->projectedOwner($sourceSymbol);
        }

        $owner = substr($sourceSymbol, 0, $separatorPosition);
        $name = substr($sourceSymbol, $separatorPosition + 2);
        $methodMember = new MemberId($owner, $name, MemberType::METHOD);

        if ($this->hasMemberNameUpdate($methodMember)) {
            $projectedMethodMember = $this->projectedMember($methodMember);

            return $projectedMethodMember->owner.'::'.$projectedMethodMember->name;
        }

        return $this->projectedOwner($owner).'::'.$name;
    }
}
