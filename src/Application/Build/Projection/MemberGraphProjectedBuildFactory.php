<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Build\Projection;

use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphBuild;
use PhpNoobs\MemberGraph\Application\Cache\VirtualFile\MemberGraphVirtualFileReferenceCollection;
use PhpNoobs\MemberGraph\Domain\Availability\AvailableMember;
use PhpNoobs\MemberGraph\Domain\Availability\AvailableMemberCollection;
use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclaration;
use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclarationCollection;
use PhpNoobs\MemberGraph\Domain\Graph\MemberDependencyGraph;
use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;
use PhpNoobs\MemberGraph\Domain\Index\Polymorphism\PolymorphicImplementationsIndex;
use PhpNoobs\MemberGraph\Domain\Owner\KnownOwner;
use PhpNoobs\MemberGraph\Domain\Owner\KnownOwnerCollection;
use PhpNoobs\MemberGraph\Domain\Owner\MemberLineageResolverV2;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerDeclaration;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerDeclarationCollection;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerUsage;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerUsageCollection;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterId;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterUsage;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterUsageCollection;
use PhpNoobs\MemberGraph\Domain\Type\TraitAliasAdaptation;
use PhpNoobs\MemberGraph\Domain\Type\TraitInsteadOfAdaptation;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsage;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsageCollection;

/**
 * Builds projected member dependency graph builds from semantic identity updates.
 */
final readonly class MemberGraphProjectedBuildFactory
{
    /**
     * Projects one build through the provided overlay.
     *
     * @param MemberDependencyGraphBuild $build   the base build
     * @param MemberGraphBuildOverlay    $overlay the identity-update overlay
     */
    public static function fromBuild(
        MemberDependencyGraphBuild $build,
        MemberGraphBuildOverlay $overlay,
    ): MemberDependencyGraphBuild {
        return new self()->project($build, $overlay);
    }

    /**
     * Projects one build through the provided overlay.
     *
     * @param MemberDependencyGraphBuild $build   the base build
     * @param MemberGraphBuildOverlay    $overlay the identity-update overlay
     */
    public function project(
        MemberDependencyGraphBuild $build,
        MemberGraphBuildOverlay $overlay,
    ): MemberDependencyGraphBuild {
        $projectionMap = new MemberGraphProjectionMap($overlay);

        $this->recordSemanticMemberFamilies($build->memberDependencyGraph, $overlay, $projectionMap);

        $projectedGraph = new MemberDependencyGraph(
            declarations: $this->projectDeclarations($build->memberDependencyGraph->declarations, $projectionMap),
            usages: $this->projectUsages($build->memberDependencyGraph->usages, $projectionMap),
            parameterUsages: $this->projectParameterUsages($build->memberDependencyGraph->parameterUsages, $projectionMap),
            availableMembers: $this->projectAvailableMembers($build->memberDependencyGraph->availableMembers, $projectionMap),
            knownOwners: $this->projectKnownOwners($build->memberDependencyGraph->knownOwners, $projectionMap),
            interfaceImplementationsIndex: $this->projectPolymorphicImplementationsIndex(
                $build->memberDependencyGraph->interfaceImplementationsIndex,
                $projectionMap,
            ),
            dependencyGraphIssues: $build->memberDependencyGraph->dependencyGraphIssues,
            ownerDeclarations: $this->projectOwnerDeclarations($build->memberDependencyGraph->ownerDeclarations, $projectionMap),
            ownerUsages: $this->projectOwnerUsages($build->memberDependencyGraph->ownerUsages, $projectionMap),
        );

        return new MemberDependencyGraphBuild(
            memberDependencyGraph: $projectedGraph,
            virtualFiles: $build->virtualFiles,
            virtualFileReferences: MemberGraphVirtualFileReferenceCollection::fromVirtualFiles($build->virtualFiles),
            knownOwners: $projectedGraph->knownOwners,
            dependencyGraphIssues: $build->dependencyGraphIssues,
            buildReport: $build->buildReport,
        );
    }

    /**
     * Records method-family projected names before collection projection starts.
     *
     * @param MemberDependencyGraph    $graph         the base graph
     * @param MemberGraphBuildOverlay  $overlay       the identity-update overlay
     * @param MemberGraphProjectionMap $projectionMap the mutable projection map
     */
    private function recordSemanticMemberFamilies(
        MemberDependencyGraph $graph,
        MemberGraphBuildOverlay $overlay,
        MemberGraphProjectionMap $projectionMap,
    ): void {
        $memberLineageResolver = new MemberLineageResolverV2();

        foreach ($overlay->memberUpdates() as $memberUpdate) {
            $baseMember = $projectionMap->baseMember($memberUpdate->owner, $memberUpdate->name, $memberUpdate->type);

            if (MemberType::METHOD !== $memberUpdate->type) {
                $projectionMap->recordProjectedMemberName($baseMember, $memberUpdate->newName);

                continue;
            }

            foreach ($memberLineageResolver->resolveFamily($graph, $baseMember) as $familyMember) {
                $projectionMap->recordProjectedMemberName($familyMember, $memberUpdate->newName);
            }

            $projectionMap->recordProjectedMemberName($baseMember, $memberUpdate->newName);
        }
    }

    /**
     * Projects known owners.
     *
     * @param KnownOwnerCollection     $owners        the base known owners
     * @param MemberGraphProjectionMap $projectionMap the projection map
     */
    private function projectKnownOwners(
        KnownOwnerCollection $owners,
        MemberGraphProjectionMap $projectionMap,
    ): KnownOwnerCollection {
        $projectedOwners = new KnownOwnerCollection();

        foreach ($owners as $owner) {
            $projectedOwners->add(new KnownOwner(
                fqcn: $projectionMap->projectedOwner($owner->fqcn),
                parentFqcn: null === $owner->parentFqcn ? null : $projectionMap->projectedOwner($owner->parentFqcn),
                kind: $owner->kind,
                isAbstract: $owner->isAbstract,
                traits: $this->projectOwnerNames($owner->traits, $projectionMap),
                interfaces: $this->projectOwnerNames($owner->interfaces, $projectionMap),
                extendsInterfaces: $this->projectOwnerNames($owner->extendsInterfaces, $projectionMap),
                traitAliasAdaptations: $this->projectTraitAliasAdaptations($owner->traitAliasAdaptations, $projectionMap),
                traitInsteadOfAdaptations: $this->projectTraitInsteadOfAdaptations(array_values($owner->traitInsteadOfAdaptations), $projectionMap),
            ));
        }

        return $projectedOwners;
    }

    /**
     * Projects owner declarations.
     *
     * @param OwnerDeclarationCollection $declarations  the base owner declarations
     * @param MemberGraphProjectionMap   $projectionMap the projection map
     */
    private function projectOwnerDeclarations(
        OwnerDeclarationCollection $declarations,
        MemberGraphProjectionMap $projectionMap,
    ): OwnerDeclarationCollection {
        $projectedDeclarations = new OwnerDeclarationCollection();

        foreach ($declarations as $declaration) {
            $projectedDeclarations->add(new OwnerDeclaration(
                fqcn: $projectionMap->projectedOwner($declaration->fqcn),
                kind: $declaration->kind,
                file: $declaration->file,
                sourceNodeId: $declaration->sourceNodeId,
            ));
        }

        return $projectedDeclarations;
    }

    /**
     * Projects owner usages.
     *
     * @param OwnerUsageCollection     $usages        the base owner usages
     * @param MemberGraphProjectionMap $projectionMap the projection map
     */
    private function projectOwnerUsages(
        OwnerUsageCollection $usages,
        MemberGraphProjectionMap $projectionMap,
    ): OwnerUsageCollection {
        $projectedUsages = new OwnerUsageCollection();

        foreach ($usages as $usagesByTarget) {
            foreach ($usagesByTarget as $usage) {
                $projectedUsages->add(new OwnerUsage(
                    sourceSymbol: $projectionMap->projectedSourceSymbol($usage->sourceSymbol),
                    target: $projectionMap->projectedOwner($usage->target),
                    type: $usage->type,
                    file: $usage->file,
                    sourceNodeId: $usage->sourceNodeId,
                ));
            }
        }

        return $projectedUsages;
    }

    /**
     * Projects member declarations.
     *
     * @param MemberDeclarationCollection $declarations  the base member declarations
     * @param MemberGraphProjectionMap    $projectionMap the projection map
     */
    private function projectDeclarations(
        MemberDeclarationCollection $declarations,
        MemberGraphProjectionMap $projectionMap,
    ): MemberDeclarationCollection {
        $projectedDeclarations = new MemberDeclarationCollection();

        foreach ($declarations->all() as $declaration) {
            $projectedDeclarations->add(new MemberDeclaration(
                id: $projectionMap->projectedMember($declaration->id),
                file: $declaration->file,
                sourceNodeId: $declaration->sourceNodeId,
            ));
        }

        return $projectedDeclarations;
    }

    /**
     * Projects member usages.
     *
     * @param MemberUsageCollection    $usages        the base member usages
     * @param MemberGraphProjectionMap $projectionMap the projection map
     */
    private function projectUsages(
        MemberUsageCollection $usages,
        MemberGraphProjectionMap $projectionMap,
    ): MemberUsageCollection {
        $projectedUsages = new MemberUsageCollection();

        foreach ($usages as $usagesByTarget) {
            foreach ($usagesByTarget as $usage) {
                $projectedUsages->add(new MemberUsage(
                    sourceSymbol: $projectionMap->projectedSourceSymbol($usage->sourceSymbol),
                    target: $projectionMap->projectedMember($usage->target),
                    type: $usage->type,
                    file: $usage->file,
                    sourceNodeId: $usage->sourceNodeId,
                ));
            }
        }

        return $projectedUsages;
    }

    /**
     * Projects parameter usages.
     *
     * @param ParameterUsageCollection $usages        the base parameter usages
     * @param MemberGraphProjectionMap $projectionMap the projection map
     */
    private function projectParameterUsages(
        ParameterUsageCollection $usages,
        MemberGraphProjectionMap $projectionMap,
    ): ParameterUsageCollection {
        $projectedUsages = new ParameterUsageCollection();

        foreach ($usages as $usagesByTarget) {
            foreach ($usagesByTarget as $usage) {
                $projectedFunctionLikeMember = $projectionMap->projectedMember(new MemberId(
                    owner: $usage->target->owner,
                    name: $usage->target->functionLikeName,
                    type: MemberType::METHOD,
                ));

                $projectedUsages->add(new ParameterUsage(
                    sourceSymbol: $projectionMap->projectedSourceSymbol($usage->sourceSymbol),
                    target: new ParameterId(
                        owner: $projectionMap->projectedOwner($usage->target->owner),
                        functionLikeName: $projectedFunctionLikeMember->name,
                        parameterName: $usage->target->parameterName,
                        parameterIndex: $usage->target->parameterIndex,
                    ),
                    type: $usage->type,
                    file: $usage->file,
                    sourceNodeId: $usage->sourceNodeId,
                ));
            }
        }

        return $projectedUsages;
    }

    /**
     * Projects available members.
     *
     * @param AvailableMemberCollection $availableMembers the base available members
     * @param MemberGraphProjectionMap  $projectionMap    the projection map
     */
    private function projectAvailableMembers(
        AvailableMemberCollection $availableMembers,
        MemberGraphProjectionMap $projectionMap,
    ): AvailableMemberCollection {
        $projectedAvailableMembers = new AvailableMemberCollection();

        foreach ($availableMembers->iterateMembers() as $availableMember) {
            $projectedAvailableMembers->add(new AvailableMember(
                member: $projectionMap->projectedMember($availableMember->member),
                origin: $availableMember->origin,
                declaredIns: $this->projectDeclaredIns($availableMember->declaredIns, $projectionMap),
                visibility: $availableMember->visibility,
            ));
        }

        return $projectedAvailableMembers;
    }

    /**
     * Projects the polymorphic implementations index.
     *
     * @param PolymorphicImplementationsIndex $index         the base polymorphic index
     * @param MemberGraphProjectionMap        $projectionMap the projection map
     */
    private function projectPolymorphicImplementationsIndex(
        PolymorphicImplementationsIndex $index,
        MemberGraphProjectionMap $projectionMap,
    ): PolymorphicImplementationsIndex {
        $projectedIndex = new PolymorphicImplementationsIndex();

        foreach ($index->all() as $contract => $implementations) {
            foreach (array_keys($implementations) as $implementation) {
                $projectedIndex->addImplementation(
                    contract: $projectionMap->projectedOwner($contract),
                    implementation: $projectionMap->projectedOwner($implementation),
                );
            }
        }

        return $projectedIndex;
    }

    /**
     * Projects a list of owner FQCNs.
     *
     * @param list<string>             $owners        the owner FQCNs
     * @param MemberGraphProjectionMap $projectionMap the projection map
     *
     * @return list<string>
     */
    private function projectOwnerNames(array $owners, MemberGraphProjectionMap $projectionMap): array
    {
        return array_values(array_unique(array_map(
            static fn (string $owner): string => $projectionMap->projectedOwner($owner),
            $owners,
        )));
    }

    /**
     * Projects declared-in owner keys.
     *
     * @param array<string, true>      $declaredIns   the base declared-in owners
     * @param MemberGraphProjectionMap $projectionMap the projection map
     *
     * @return array<string, true>
     */
    private function projectDeclaredIns(array $declaredIns, MemberGraphProjectionMap $projectionMap): array
    {
        $projectedDeclaredIns = [];

        foreach (array_keys($declaredIns) as $declaredIn) {
            $projectedDeclaredIns[$projectionMap->projectedOwner($declaredIn)] = true;
        }

        return $projectedDeclaredIns;
    }

    /**
     * Projects trait alias adaptations.
     *
     * @param array<string, array<string, TraitAliasAdaptation>> $adaptations   the base adaptations
     * @param MemberGraphProjectionMap                           $projectionMap the projection map
     *
     * @return array<string, array<string, TraitAliasAdaptation>>
     */
    private function projectTraitAliasAdaptations(
        array $adaptations,
        MemberGraphProjectionMap $projectionMap,
    ): array {
        $projectedAdaptations = [];

        foreach ($adaptations as $traitFqcn => $adaptationsByMethod) {
            $projectedTraitFqcn = $projectionMap->projectedOwner($traitFqcn);

            foreach ($adaptationsByMethod as $methodName => $adaptation) {
                $method = new MemberId($traitFqcn, $methodName, MemberType::METHOD);
                $projectedMethodName = $projectionMap->projectedMemberName($method);

                $projectedAdaptations[$projectedTraitFqcn][$projectedMethodName] = new TraitAliasAdaptation(
                    originalName: $projectionMap->projectedMemberName(new MemberId($traitFqcn, $adaptation->originalName, MemberType::METHOD)),
                    aliasName: $adaptation->aliasName,
                    visibility: $adaptation->visibility,
                );
            }
        }

        return $projectedAdaptations;
    }

    /**
     * Projects trait instead-of adaptations.
     *
     * @param list<TraitInsteadOfAdaptation> $adaptations   the base adaptations
     * @param MemberGraphProjectionMap       $projectionMap the projection map
     *
     * @return list<TraitInsteadOfAdaptation>
     */
    private function projectTraitInsteadOfAdaptations(
        array $adaptations,
        MemberGraphProjectionMap $projectionMap,
    ): array {
        $projectedAdaptations = [];

        foreach ($adaptations as $adaptation) {
            $projectedAdaptations[] = new TraitInsteadOfAdaptation(
                preferredTraitFqcn: $projectionMap->projectedOwner($adaptation->preferredTraitFqcn),
                methodName: $projectionMap->projectedMemberName(new MemberId(
                    $adaptation->preferredTraitFqcn,
                    $adaptation->methodName,
                    MemberType::METHOD,
                )),
                excludedTraitFqcns: $this->projectOwnerNames(array_values($adaptation->excludedTraitFqcns), $projectionMap),
            );
        }

        return $projectedAdaptations;
    }
}
