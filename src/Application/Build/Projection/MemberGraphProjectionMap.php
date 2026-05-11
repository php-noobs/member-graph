<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Build\Projection;

use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;

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

            $this->projectedMemberNameByBaseHash[$baseMember->hash()] = $memberUpdate->newName;
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
        return new MemberId($this->baseOwner($owner), $name, $type);
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
