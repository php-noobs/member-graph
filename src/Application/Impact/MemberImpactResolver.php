<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Impact;

use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclarationCollection;
use PhpNoobs\MemberGraph\Domain\Graph\MemberDependencyGraph;
use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Owner\MemberLineageResolverV2;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerDeclarationCollection;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerUsage;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerUsageCollection;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterUsage;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterUsageCollection;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsage;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsageCollection;

/**
 * Resolves the graph impact for one member or parameter target.
 */
final readonly class MemberImpactResolver
{
    private MemberLineageResolverV2 $memberLineageResolver;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->memberLineageResolver = new MemberLineageResolverV2();
    }

    /**
     * Resolves impact information for the given target.
     *
     * @param MemberDependencyGraph $graph  the member dependency graph
     * @param MemberImpactTarget    $target the impact query target
     */
    public function resolve(MemberDependencyGraph $graph, MemberImpactTarget $target): MemberImpact
    {
        $declarations = new MemberDeclarationCollection();
        $memberUsages = new MemberUsageCollection();
        $parameterUsages = new ParameterUsageCollection();
        $ownerDeclarations = new OwnerDeclarationCollection();
        $ownerUsages = new OwnerUsageCollection();
        $impactedOwners = new ImpactedOwnerCollection();
        $impactedFiles = new ImpactedFileCollection();

        if (null !== $target->memberId) {
            foreach ($this->memberTargets($graph, $target->memberId) as $memberTarget) {
                $ownerDeclaration = $graph->ownerDeclarations->get($memberTarget->owner);

                if (null !== $ownerDeclaration) {
                    $impactedOwners->add($ownerDeclaration->fqcn);
                    $impactedFiles->add($ownerDeclaration->file);
                }

                $declaration = $graph->declarations->get($memberTarget);

                if (null !== $declaration) {
                    $declarations->add($declaration);
                    $impactedOwners->add($declaration->id->owner);
                    $impactedFiles->add($declaration->file);
                }

                foreach ($graph->usages->getByTarget($memberTarget) as $usage) {
                    $memberUsages->add($usage);
                    $this->addUsageImpact($usage, $impactedOwners, $impactedFiles);
                }
            }
        }

        if (null !== $target->parameterId) {
            foreach ($graph->parameterUsages->getByTarget($target->parameterId) as $usage) {
                $parameterUsages->add($usage);
                $this->addParameterUsageImpact($usage, $impactedOwners, $impactedFiles);
            }
        }

        if (null !== $target->owner) {
            $declaration = $graph->ownerDeclarations->get($target->owner);

            if (null !== $declaration) {
                $ownerDeclarations->add($declaration);
                $impactedOwners->add($declaration->fqcn);
                $impactedFiles->add($declaration->file);
            }

            foreach ($graph->ownerUsages->getByTarget($target->owner) as $usage) {
                $ownerUsages->add($usage);
                $this->addOwnerUsageImpact($usage, $impactedOwners, $impactedFiles);
            }
        }

        return new MemberImpact(
            target: $target,
            declarations: $declarations,
            memberUsages: $memberUsages,
            parameterUsages: $parameterUsages,
            impactedOwners: $impactedOwners,
            impactedFiles: $impactedFiles,
            ownerDeclarations: $ownerDeclarations,
            ownerUsages: $ownerUsages,
        );
    }

    /**
     * Returns the member targets that belong to the same semantic family.
     *
     * @param MemberDependencyGraph $graph  the member dependency graph
     * @param MemberId              $target the original member target
     *
     * @return list<MemberId>
     */
    private function memberTargets(MemberDependencyGraph $graph, MemberId $target): array
    {
        $targets = [];

        foreach ($this->memberLineageResolver->resolveFamily($graph, $target) as $memberTarget) {
            $targets[$memberTarget->hash()] = $memberTarget;
        }

        $targets[$target->hash()] = $target;

        return array_values($targets);
    }

    /**
     * Adds impact information carried by one member usage.
     *
     * @param MemberUsage             $usage          the member usage
     * @param ImpactedOwnerCollection $impactedOwners the impacted owners
     * @param ImpactedFileCollection  $impactedFiles  the impacted files
     */
    private function addUsageImpact(
        MemberUsage $usage,
        ImpactedOwnerCollection $impactedOwners,
        ImpactedFileCollection $impactedFiles,
    ): void {
        $impactedOwners->add($usage->target->owner);
        $impactedOwners->add($this->ownerFromSourceSymbol($usage->sourceSymbol));
        $impactedFiles->add($usage->file);
    }

    /**
     * Adds impact information carried by one parameter usage.
     *
     * @param ParameterUsage          $usage          the parameter usage
     * @param ImpactedOwnerCollection $impactedOwners the impacted owners
     * @param ImpactedFileCollection  $impactedFiles  the impacted files
     */
    private function addParameterUsageImpact(
        ParameterUsage $usage,
        ImpactedOwnerCollection $impactedOwners,
        ImpactedFileCollection $impactedFiles,
    ): void {
        $impactedOwners->add($usage->target->owner);
        $impactedOwners->add($this->ownerFromSourceSymbol($usage->sourceSymbol));
        $impactedFiles->add($usage->file);
    }

    /**
     * Adds impact information carried by one owner usage.
     *
     * @param OwnerUsage              $usage          the owner usage
     * @param ImpactedOwnerCollection $impactedOwners the impacted owners
     * @param ImpactedFileCollection  $impactedFiles  the impacted files
     */
    private function addOwnerUsageImpact(
        OwnerUsage $usage,
        ImpactedOwnerCollection $impactedOwners,
        ImpactedFileCollection $impactedFiles,
    ): void {
        $impactedOwners->add($usage->target);
        $impactedOwners->add($this->ownerFromSourceSymbol($usage->sourceSymbol));
        $impactedFiles->add($usage->file);
    }

    /**
     * Extracts an owner FQCN from a member source symbol.
     *
     * @param string $sourceSymbol the source symbol
     */
    private function ownerFromSourceSymbol(string $sourceSymbol): string
    {
        $separatorPosition = strpos($sourceSymbol, '::');

        if (false === $separatorPosition) {
            return '';
        }

        return substr($sourceSymbol, 0, $separatorPosition);
    }
}
