<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Build\PartialGraph\WorkingSet;

use PhpNoobs\MemberGraph\Application\Build\PartialGraph\Diagnostics\MemberDependencyGraphPartialRebuildClosureDiagnostic;
use PhpNoobs\MemberGraph\Application\Build\PartialGraph\Diagnostics\MemberDependencyGraphPartialRebuildClosureDiagnosticReason;
use PhpNoobs\MemberGraph\Application\Build\PartialGraph\Input\MemberDependencyGraphPartialRebuildPreparedInput;
use PhpNoobs\MemberGraph\Application\Cache\Fragment\MemberGraphFragmentCollection;
use PhpNoobs\MemberGraph\Application\Cache\Fragment\MemberGraphFragmentMerger;
use PhpNoobs\MemberGraph\Application\Cache\Plan\MemberGraphCacheFileCollection;
use PhpNoobs\MemberGraph\Application\Cache\Snapshot\Declaration\MemberGraphDeclarationSnapshot;
use PhpNoobs\MemberGraph\Application\Cache\Snapshot\Declaration\OwnerDeclarationSnapshot;
use PhpNoobs\MemberGraph\Application\Cache\Snapshot\MemberGraphVirtualSourceMetadataCollection;
use PhpNoobs\MemberGraph\Application\Impact\MemberImpactTarget;
use PhpNoobs\MemberGraph\Application\Query\MemberGraphQueryService;
use PhpNoobs\MemberGraph\Domain\Graph\MemberDependencyGraph;
use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;

/**
 * Resolves the initial working set required by a partial member graph rebuild.
 */
final readonly class MemberDependencyGraphPartialRebuildWorkingSetResolver
{
    /**
     * Constructor.
     *
     * @param MemberGraphFragmentMerger $fragmentMerger the graph fragment merger
     */
    public function __construct(
        private MemberGraphFragmentMerger $fragmentMerger = new MemberGraphFragmentMerger(),
    ) {
    }

    /**
     * Resolves the initial partial rebuild working set.
     *
     * @param MemberDependencyGraphPartialRebuildPreparedInput $preparedInput the prepared partial rebuild input
     */
    public function resolve(
        MemberDependencyGraphPartialRebuildPreparedInput $preparedInput,
    ): MemberDependencyGraphPartialRebuildWorkingSet {
        $workingSet = new MemberDependencyGraphPartialRebuildWorkingSet();

        foreach ($preparedInput->partialRebuildInput->filesToBuild as $filePath) {
            $workingSet->addFileToParseForContext($filePath);
            $workingSet->addFileToRebuildGraph($filePath);
        }

        $iterations = $this->expandWithImpactedFiles($workingSet, $preparedInput);

        $workingSet
            ->setFragmentsToReuse($this->fragmentsOutsideRebuildSet(
                fragmentsToReuse: $preparedInput->fragmentsToReuse,
                filesToRebuildGraph: $workingSet->filesToRebuildGraph,
            ))
            ->setIterations($iterations);

        return $workingSet;
    }

    /**
     * Adds files impacted by declarations loaded from files scheduled for rebuild.
     *
     * @param MemberDependencyGraphPartialRebuildWorkingSet    $workingSet    the working set being expanded
     * @param MemberDependencyGraphPartialRebuildPreparedInput $preparedInput the prepared partial rebuild input
     */
    private function expandWithImpactedFiles(
        MemberDependencyGraphPartialRebuildWorkingSet $workingSet,
        MemberDependencyGraphPartialRebuildPreparedInput $preparedInput,
    ): int {
        if (0 === count($preparedInput->fragmentsToReuse)) {
            return 1;
        }

        $iterations = 1;
        $processedTargets = [];
        $pendingTargets = $this->indexedImpactTargets($this->impactTargetsFromLoadedDeclarations(
            $preparedInput->sourceView->loadedInput->loadedDeclarationSnapshot,
        ));
        $pendingTargets += $this->indexedImpactTargets($this->impactTargetsFromChangedOwnerMetadata(
            preparedInput: $preparedInput,
        ));
        $pendingTargets += $this->indexedImpactTargets($this->impactTargetsFromCachedDeclarationsInRebuiltFiles(
            preparedInput: $preparedInput,
        ));

        while (!$this->allTargetsProcessed($pendingTargets, $processedTargets)) {
            $expandedFiles = 0;
            $reusableFragments = $this->fragmentsOutsideRebuildSet(
                fragmentsToReuse: $preparedInput->fragmentsToReuse,
                filesToRebuildGraph: $workingSet->filesToRebuildGraph,
            );

            if (0 === count($reusableFragments)) {
                break;
            }

            $query = MemberGraphQueryService::fromGraph($this->fragmentMerger->merge($reusableFragments));

            foreach ($pendingTargets as $targetKey => $target) {
                if (isset($processedTargets[$targetKey])) {
                    continue;
                }

                $processedTargets[$targetKey] = true;

                foreach ($query->impactedFilesFor($target) as $graphFilePath) {
                    $filePath = $this->physicalFilePathForGraphFile(
                        graphFilePath: $graphFilePath,
                        allSourceMetadata: $preparedInput->sourceView->allSourceMetadata,
                    );

                    if (null === $filePath) {
                        $expandedFiles += $this->expandConservativelyForUnresolvedGraphFile(
                            graphFilePath: $graphFilePath,
                            workingSet: $workingSet,
                            reusableFragments: $reusableFragments,
                        );

                        continue;
                    }

                    if ($workingSet->hasFileToRebuildGraph($filePath)) {
                        continue;
                    }

                    $workingSet->addFileToParseForContext($filePath);
                    $workingSet->addFileToRebuildGraph($filePath);
                    ++$expandedFiles;
                    $pendingTargets += $this->impactTargetsFromFragmentDeclarations(
                        $preparedInput->fragmentsToReuse->get($filePath),
                    );
                }
            }

            if (0 === $expandedFiles) {
                continue;
            }

            ++$iterations;
        }

        return $iterations;
    }

    /**
     * Returns impact targets declared by one cached graph fragment.
     *
     * @param MemberDependencyGraph|null $fragment the graph fragment, or null when the file is not cached
     *
     * @return array<string, MemberImpactTarget>
     */
    private function impactTargetsFromFragmentDeclarations(?MemberDependencyGraph $fragment): array
    {
        if (null === $fragment) {
            return [];
        }

        $targets = [];

        foreach ($fragment->declarations->all() as $declaration) {
            $target = $this->impactTargetFromMemberId($declaration->id);

            if (null === $target) {
                continue;
            }

            $targets[$this->impactTargetKey($target)] = $target;
        }

        return $targets;
    }

    /**
     * Converts a member identifier into an impact target.
     *
     * @param MemberId $memberId the declared member identifier
     */
    private function impactTargetFromMemberId(MemberId $memberId): ?MemberImpactTarget
    {
        return match ($memberId->type) {
            MemberType::METHOD => MemberImpactTarget::method($memberId->owner, $memberId->name),
            MemberType::PROPERTY => MemberImpactTarget::property($memberId->owner, $memberId->name),
            MemberType::CLASS_CONSTANT => MemberImpactTarget::classConstant($memberId->owner, $memberId->name),
            MemberType::FUNCTION_ => MemberImpactTarget::forFunction($memberId->name),
            MemberType::CONSTANT => MemberImpactTarget::constant($memberId->name),
            MemberType::PARAMETER => null,
        };
    }

    /**
     * Indexes impact targets by stable target key.
     *
     * @param iterable<MemberImpactTarget> $targets the impact targets to index
     *
     * @return array<string, MemberImpactTarget>
     */
    private function indexedImpactTargets(iterable $targets): array
    {
        $indexedTargets = [];

        foreach ($targets as $target) {
            $indexedTargets[$this->impactTargetKey($target)] = $target;
        }

        return $indexedTargets;
    }

    /**
     * Indicates whether all queued targets have already been processed.
     *
     * @param array<string, MemberImpactTarget> $pendingTargets   the queued targets
     * @param array<string, true>               $processedTargets the processed target keys
     */
    private function allTargetsProcessed(array $pendingTargets, array $processedTargets): bool
    {
        foreach (array_keys($pendingTargets) as $targetKey) {
            if (!isset($processedTargets[$targetKey])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns a stable key for an impact target.
     *
     * @param MemberImpactTarget $target the impact target
     */
    private function impactTargetKey(MemberImpactTarget $target): string
    {
        return $target->memberId?->hash() ?? $target->parameterId?->hash() ?? '';
    }

    /**
     * Returns impact targets declared by freshly loaded files.
     *
     * @param MemberGraphDeclarationSnapshot $loadedDeclarationSnapshot the loaded declaration snapshot
     *
     * @return iterable<MemberImpactTarget>
     */
    private function impactTargetsFromLoadedDeclarations(
        MemberGraphDeclarationSnapshot $loadedDeclarationSnapshot,
    ): iterable {
        foreach ($loadedDeclarationSnapshot->methods as $method) {
            yield MemberImpactTarget::method($method->ownerFqcn, $method->name);

            foreach ($method->parameters as $parameter) {
                yield MemberImpactTarget::parameter($method->ownerFqcn, $method->name, $parameter->name);
            }
        }

        foreach ($loadedDeclarationSnapshot->functions as $function) {
            yield MemberImpactTarget::forFunction($function->name);

            foreach ($function->parameters as $parameter) {
                yield MemberImpactTarget::parameter('', $function->name, $parameter->name);
            }
        }

        foreach ($loadedDeclarationSnapshot->properties as $property) {
            yield MemberImpactTarget::property($property->ownerFqcn, $property->name);
        }

        foreach ($loadedDeclarationSnapshot->classConstants as $classConstant) {
            yield MemberImpactTarget::classConstant($classConstant->ownerFqcn, $classConstant->name);
        }
    }

    /**
     * Returns impact targets declared by cached declarations from files scheduled for rebuild.
     *
     * @param MemberDependencyGraphPartialRebuildPreparedInput $preparedInput the prepared partial rebuild input
     *
     * @return iterable<MemberImpactTarget>
     */
    private function impactTargetsFromCachedDeclarationsInRebuiltFiles(
        MemberDependencyGraphPartialRebuildPreparedInput $preparedInput,
    ): iterable {
        foreach ($preparedInput->cachedDeclarationSnapshot->methods as $method) {
            if (!$this->isRemovedOrRebuiltFile($method->fullFilePath, $preparedInput)) {
                continue;
            }

            yield MemberImpactTarget::method($method->ownerFqcn, $method->name);

            foreach ($method->parameters as $parameter) {
                yield MemberImpactTarget::parameter($method->ownerFqcn, $method->name, $parameter->name);
            }
        }

        foreach ($preparedInput->cachedDeclarationSnapshot->functions as $function) {
            if (!$this->isRemovedOrRebuiltFile($function->fullFilePath, $preparedInput)) {
                continue;
            }

            yield MemberImpactTarget::forFunction($function->name);

            foreach ($function->parameters as $parameter) {
                yield MemberImpactTarget::parameter('', $function->name, $parameter->name);
            }
        }

        foreach ($preparedInput->cachedDeclarationSnapshot->properties as $property) {
            if (!$this->isRemovedOrRebuiltFile($property->fullFilePath, $preparedInput)) {
                continue;
            }

            yield MemberImpactTarget::property($property->ownerFqcn, $property->name);
        }

        foreach ($preparedInput->cachedDeclarationSnapshot->classConstants as $classConstant) {
            if (!$this->isRemovedOrRebuiltFile($classConstant->fullFilePath, $preparedInput)) {
                continue;
            }

            yield MemberImpactTarget::classConstant($classConstant->ownerFqcn, $classConstant->name);
        }
    }

    /**
     * Returns impact targets created by changed class-like owner metadata.
     *
     * @param MemberDependencyGraphPartialRebuildPreparedInput $preparedInput the prepared partial rebuild input
     *
     * @return iterable<MemberImpactTarget>
     */
    private function impactTargetsFromChangedOwnerMetadata(
        MemberDependencyGraphPartialRebuildPreparedInput $preparedInput,
    ): iterable {
        foreach ($preparedInput->sourceView->loadedInput->loadedDeclarationSnapshot->owners as $loadedOwner) {
            $cachedOwner = $preparedInput->cachedDeclarationSnapshot->owners->get($loadedOwner->fqcn);

            foreach ($this->changedInterfaces($cachedOwner, $loadedOwner) as $interfaceFqcn) {
                foreach ($this->interfaceHierarchy($interfaceFqcn, $preparedInput->partialGlobalIndexes->mergedDeclarationSnapshot) as $targetInterfaceFqcn) {
                    foreach ($this->methodTargetsDeclaredByOwner(
                        ownerFqcn: $targetInterfaceFqcn,
                        declarationSnapshot: $preparedInput->partialGlobalIndexes->mergedDeclarationSnapshot,
                    ) as $target) {
                        yield $target;
                    }
                }
            }

            foreach ($this->changedParentClasses($cachedOwner, $loadedOwner) as $parentFqcn) {
                foreach ($this->classParentHierarchy($parentFqcn, $preparedInput->partialGlobalIndexes->mergedDeclarationSnapshot) as $targetParentFqcn) {
                    foreach ($this->methodTargetsDeclaredByOwner(
                        ownerFqcn: $targetParentFqcn,
                        declarationSnapshot: $preparedInput->partialGlobalIndexes->mergedDeclarationSnapshot,
                    ) as $target) {
                        yield $target;
                    }
                }
            }
        }
    }

    /**
     * Returns one interface and every parent interface it extends.
     *
     * @param string                         $interfaceFqcn       the interface FQCN
     * @param MemberGraphDeclarationSnapshot $declarationSnapshot the declaration snapshot to inspect
     * @param array<string, true>            $visited             the already visited interfaces
     *
     * @return list<string>
     */
    private function interfaceHierarchy(
        string $interfaceFqcn,
        MemberGraphDeclarationSnapshot $declarationSnapshot,
        array $visited = [],
    ): array {
        if (isset($visited[$interfaceFqcn])) {
            return [];
        }

        $visited[$interfaceFqcn] = true;
        $interfaces = [$interfaceFqcn => $interfaceFqcn];
        $owner = $declarationSnapshot->owners->get($interfaceFqcn);

        if (null === $owner) {
            return array_values($interfaces);
        }

        foreach ($owner->extendsInterfaces as $extendedInterfaceFqcn) {
            foreach ($this->interfaceHierarchy(
                interfaceFqcn: $extendedInterfaceFqcn,
                declarationSnapshot: $declarationSnapshot,
                visited: $visited,
            ) as $nestedInterfaceFqcn) {
                $interfaces[$nestedInterfaceFqcn] = $nestedInterfaceFqcn;
            }
        }

        return array_values($interfaces);
    }

    /**
     * Returns one class and every parent class it extends.
     *
     * @param string                         $parentFqcn          the parent class FQCN
     * @param MemberGraphDeclarationSnapshot $declarationSnapshot the declaration snapshot to inspect
     * @param array<string, true>            $visited             the already visited classes
     *
     * @return list<string>
     */
    private function classParentHierarchy(
        string $parentFqcn,
        MemberGraphDeclarationSnapshot $declarationSnapshot,
        array $visited = [],
    ): array {
        if (isset($visited[$parentFqcn])) {
            return [];
        }

        $visited[$parentFqcn] = true;
        $parents = [$parentFqcn => $parentFqcn];
        $owner = $declarationSnapshot->owners->get($parentFqcn);

        if (null === $owner || null === $owner->parentFqcn) {
            return array_values($parents);
        }

        foreach ($this->classParentHierarchy(
            parentFqcn: $owner->parentFqcn,
            declarationSnapshot: $declarationSnapshot,
            visited: $visited,
        ) as $nestedParentFqcn) {
            $parents[$nestedParentFqcn] = $nestedParentFqcn;
        }

        return array_values($parents);
    }

    /**
     * Returns method impact targets declared by one owner.
     *
     * @param string                         $ownerFqcn           the declaring owner FQCN
     * @param MemberGraphDeclarationSnapshot $declarationSnapshot the declaration snapshot to inspect
     *
     * @return iterable<MemberImpactTarget>
     */
    private function methodTargetsDeclaredByOwner(
        string $ownerFqcn,
        MemberGraphDeclarationSnapshot $declarationSnapshot,
    ): iterable {
        foreach ($declarationSnapshot->methods as $method) {
            if ($ownerFqcn !== $method->ownerFqcn) {
                continue;
            }

            yield MemberImpactTarget::method($method->ownerFqcn, $method->name);
        }
    }

    /**
     * Returns interfaces added to or removed from one owner.
     *
     * @param OwnerDeclarationSnapshot|null $cachedOwner the cached owner snapshot
     * @param OwnerDeclarationSnapshot      $loadedOwner the loaded owner snapshot
     *
     * @return list<string>
     */
    private function changedInterfaces(
        ?OwnerDeclarationSnapshot $cachedOwner,
        OwnerDeclarationSnapshot $loadedOwner,
    ): array {
        $cachedInterfaces = $cachedOwner->interfaces ?? [];
        $loadedInterfaces = $loadedOwner->interfaces;

        return array_values(array_unique(array_merge(
            array_diff($cachedInterfaces, $loadedInterfaces),
            array_diff($loadedInterfaces, $cachedInterfaces),
        )));
    }

    /**
     * Returns parent classes added to or removed from one owner.
     *
     * @param OwnerDeclarationSnapshot|null $cachedOwner the cached owner snapshot
     * @param OwnerDeclarationSnapshot      $loadedOwner the loaded owner snapshot
     *
     * @return list<string>
     */
    private function changedParentClasses(
        ?OwnerDeclarationSnapshot $cachedOwner,
        OwnerDeclarationSnapshot $loadedOwner,
    ): array {
        $parents = [];
        $cachedParentFqcn = $cachedOwner?->parentFqcn;

        if (null !== $cachedParentFqcn && $cachedParentFqcn !== $loadedOwner->parentFqcn) {
            $parents[$cachedParentFqcn] = $cachedParentFqcn;
        }

        if (null !== $loadedOwner->parentFqcn && $cachedParentFqcn !== $loadedOwner->parentFqcn) {
            $parents[$loadedOwner->parentFqcn] = $loadedOwner->parentFqcn;
        }

        return array_values($parents);
    }

    /**
     * Indicates whether one cached declaration belongs to a rebuilt or deleted file.
     *
     * @param string                                           $filePath      the cached declaration physical file path
     * @param MemberDependencyGraphPartialRebuildPreparedInput $preparedInput the prepared partial rebuild input
     */
    private function isRemovedOrRebuiltFile(
        string $filePath,
        MemberDependencyGraphPartialRebuildPreparedInput $preparedInput,
    ): bool {
        return $preparedInput->partialRebuildInput->filesToBuild->contains($filePath)
            || $preparedInput->partialRebuildInput->filesToDelete->contains($filePath);
    }

    /**
     * Resolves a graph file path to its physical file path when source metadata is available.
     *
     * @param string                                     $graphFilePath     the graph file path, usually a virtual file path
     * @param MemberGraphVirtualSourceMetadataCollection $allSourceMetadata the complete source metadata view
     */
    private function physicalFilePathForGraphFile(
        string $graphFilePath,
        MemberGraphVirtualSourceMetadataCollection $allSourceMetadata,
    ): ?string {
        return $allSourceMetadata->get($graphFilePath)?->fullFilePath;
    }

    /**
     * Expands the rebuild set conservatively when one impacted graph file cannot be resolved.
     *
     * @param string                                        $graphFilePath     the unresolved graph file path
     * @param MemberDependencyGraphPartialRebuildWorkingSet $workingSet        the working set being expanded
     * @param MemberGraphFragmentCollection                 $reusableFragments the currently reusable fragments
     */
    private function expandConservativelyForUnresolvedGraphFile(
        string $graphFilePath,
        MemberDependencyGraphPartialRebuildWorkingSet $workingSet,
        MemberGraphFragmentCollection $reusableFragments,
    ): int {
        $filePathsToExpand = [];

        foreach ($reusableFragments as $filePath => $fragment) {
            if ($workingSet->hasFileToRebuildGraph($filePath)) {
                continue;
            }

            $filePathsToExpand[] = $filePath;
        }

        if ([] === $filePathsToExpand) {
            return 0;
        }

        $workingSet->addDiagnostic(new MemberDependencyGraphPartialRebuildClosureDiagnostic(
            reason: MemberDependencyGraphPartialRebuildClosureDiagnosticReason::UNRESOLVED_REFERENCE,
            message: 'Unable to map an impacted graph file path to a physical file path.',
            reference: $graphFilePath,
        ));
        $workingSet->addDiagnostic(new MemberDependencyGraphPartialRebuildClosureDiagnostic(
            reason: MemberDependencyGraphPartialRebuildClosureDiagnosticReason::CONSERVATIVE_EXPANSION,
            message: 'All remaining reusable fragments were added to the rebuild set.',
            reference: $graphFilePath,
        ));

        foreach ($filePathsToExpand as $filePath) {
            $workingSet->addFileToParseForContext($filePath);
            $workingSet->addFileToRebuildGraph($filePath);
        }

        return count($filePathsToExpand);
    }

    /**
     * Keeps only cached fragments that are outside files scheduled for graph rebuild.
     *
     * @param MemberGraphFragmentCollection  $fragmentsToReuse    the cached fragments available for reuse
     * @param MemberGraphCacheFileCollection $filesToRebuildGraph the physical files scheduled for graph rebuild
     */
    private function fragmentsOutsideRebuildSet(
        MemberGraphFragmentCollection $fragmentsToReuse,
        MemberGraphCacheFileCollection $filesToRebuildGraph,
    ): MemberGraphFragmentCollection {
        $filteredFragments = new MemberGraphFragmentCollection();

        foreach ($fragmentsToReuse as $filePath => $fragment) {
            if ($filesToRebuildGraph->contains($filePath)) {
                continue;
            }

            $filteredFragments->add($filePath, $fragment);
        }

        return $filteredFragments;
    }
}
