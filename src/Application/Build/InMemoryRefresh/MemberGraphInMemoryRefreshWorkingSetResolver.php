<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Build\InMemoryRefresh;

use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphBuild;
use PhpNoobs\MemberGraph\Application\Impact\MemberImpactTarget;
use PhpNoobs\MemberGraph\Application\Query\MemberGraphQueryService;
use PhpNoobs\MemberGraph\Domain\Graph\MemberDependencyGraph;
use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;
use PhpNoobs\MemberGraph\Domain\Owner\KnownOwner;
use PhpNoobs\MemberGraph\Domain\Owner\KnownOwnerCollection;
use PhpNoobs\MemberGraph\Infrastructure\PhpParser\Indexing\KnownOwnersCollectionBuilder;
use PhpNoobs\PhpSource\VirtualPhpSourceFileCollection;

/**
 * Resolves the conservative in-memory refresh working set from touched virtual files.
 */
final readonly class MemberGraphInMemoryRefreshWorkingSetResolver
{
    /**
     * Resolves the physical-file working set required by touched virtual files.
     *
     * @param MemberDependencyGraphBuild     $previousBuild       the previous complete build
     * @param VirtualPhpSourceFileCollection $touchedVirtualFiles the touched virtual files
     */
    public function resolve(
        MemberDependencyGraphBuild $previousBuild,
        VirtualPhpSourceFileCollection $touchedVirtualFiles,
    ): MemberGraphInMemoryRefreshWorkingSet {
        $workingSet = new MemberGraphInMemoryRefreshWorkingSet();
        $virtualToPhysicalFilePaths = $this->virtualToPhysicalFilePaths(
            previousVirtualFiles: $previousBuild->virtualFiles,
            touchedVirtualFiles: $touchedVirtualFiles,
        );
        $structurallyChangedOwnerFqcns = $this->structurallyChangedOwnerFqcns(
            previousBuild: $previousBuild,
            touchedVirtualFiles: $touchedVirtualFiles,
        );
        $pendingTargets = [];
        $processedTargets = [];

        foreach ($this->touchedPhysicalFilePaths($touchedVirtualFiles) as $filePath) {
            $workingSet
                ->addFileToParseForContext($filePath)
                ->addFileToRebuildGraph($filePath);
            $pendingTargets += $this->impactTargetsDeclaredInPhysicalFile(
                graph: $previousBuild->memberDependencyGraph,
                physicalFilePath: $filePath,
                virtualToPhysicalFilePaths: $virtualToPhysicalFilePaths,
                structurallyChangedOwnerFqcns: $structurallyChangedOwnerFqcns,
            );
        }

        $iterations = $this->expandWithImpactedFiles(
            previousBuild: $previousBuild,
            workingSet: $workingSet,
            pendingTargets: $pendingTargets,
            processedTargets: $processedTargets,
            virtualToPhysicalFilePaths: $virtualToPhysicalFilePaths,
            structurallyChangedOwnerFqcns: $structurallyChangedOwnerFqcns,
        );

        return $workingSet->setIterations($iterations);
    }

    /**
     * Expands the working set with files impacted by touched declarations.
     *
     * @param MemberDependencyGraphBuild           $previousBuild                 the previous complete build
     * @param MemberGraphInMemoryRefreshWorkingSet $workingSet                    the working set being expanded
     * @param array<string, MemberImpactTarget>    $pendingTargets                the queued impact targets
     * @param array<string, true>                  $processedTargets              the processed impact targets
     * @param array<string, string>                $virtualToPhysicalFilePaths    virtual-to-physical file path map
     * @param array<string, true>                  $structurallyChangedOwnerFqcns structurally changed owners indexed by FQCN
     */
    private function expandWithImpactedFiles(
        MemberDependencyGraphBuild $previousBuild,
        MemberGraphInMemoryRefreshWorkingSet $workingSet,
        array $pendingTargets,
        array $processedTargets,
        array $virtualToPhysicalFilePaths,
        array $structurallyChangedOwnerFqcns,
    ): int {
        $iterations = 1;
        $query = MemberGraphQueryService::fromGraph($previousBuild->memberDependencyGraph);

        while (!$this->allTargetsProcessed($pendingTargets, $processedTargets)) {
            $expandedFiles = 0;

            foreach ($pendingTargets as $targetKey => $target) {
                if (isset($processedTargets[$targetKey])) {
                    continue;
                }

                $processedTargets[$targetKey] = true;

                foreach ($query->impactedFilesFor($target) as $graphFilePath) {
                    $filePath = $virtualToPhysicalFilePaths[$graphFilePath] ?? null;

                    if (null === $filePath || $workingSet->hasFileToRebuildGraph($filePath)) {
                        continue;
                    }

                    $workingSet
                        ->addFileToParseForContext($filePath)
                        ->addFileToRebuildGraph($filePath);
                    ++$expandedFiles;
                    $pendingTargets += $this->impactTargetsDeclaredInPhysicalFile(
                        graph: $previousBuild->memberDependencyGraph,
                        physicalFilePath: $filePath,
                        virtualToPhysicalFilePaths: $virtualToPhysicalFilePaths,
                        structurallyChangedOwnerFqcns: $structurallyChangedOwnerFqcns,
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
     * Returns impact targets declared in one physical file.
     *
     * @param MemberDependencyGraph $graph                         the previous member graph
     * @param string                $physicalFilePath              the physical file path
     * @param array<string, string> $virtualToPhysicalFilePaths    virtual-to-physical file path map
     * @param array<string, true>   $structurallyChangedOwnerFqcns structurally changed owners indexed by FQCN
     *
     * @return array<string, MemberImpactTarget>
     */
    private function impactTargetsDeclaredInPhysicalFile(
        MemberDependencyGraph $graph,
        string $physicalFilePath,
        array $virtualToPhysicalFilePaths,
        array $structurallyChangedOwnerFqcns,
    ): array {
        $targets = [];

        foreach ($graph->ownerDeclarations->all() as $declaration) {
            if (!$this->belongsToPhysicalFile($declaration->file, $physicalFilePath, $virtualToPhysicalFilePaths)) {
                continue;
            }

            if (!isset($structurallyChangedOwnerFqcns[$declaration->fqcn])) {
                continue;
            }

            $target = MemberImpactTarget::owner($declaration->fqcn);
            $targets[$this->impactTargetKey($target)] = $target;
        }

        foreach ($graph->declarations->all() as $declaration) {
            if (!$this->belongsToPhysicalFile($declaration->file, $physicalFilePath, $virtualToPhysicalFilePaths)) {
                continue;
            }

            $target = $this->impactTargetFromMemberId($declaration->id);

            if (null === $target) {
                continue;
            }

            $targets[$this->impactTargetKey($target)] = $target;
        }

        return $targets;
    }

    /**
     * Returns touched owners whose structural metadata changed.
     *
     * @param MemberDependencyGraphBuild     $previousBuild       the previous complete build
     * @param VirtualPhpSourceFileCollection $touchedVirtualFiles the touched virtual files
     *
     * @return array<string, true>
     */
    private function structurallyChangedOwnerFqcns(
        MemberDependencyGraphBuild $previousBuild,
        VirtualPhpSourceFileCollection $touchedVirtualFiles,
    ): array {
        $touchedKnownOwners = $this->knownOwnersFromVirtualFiles($touchedVirtualFiles);
        $changedOwnerFqcns = [];

        foreach ($touchedKnownOwners as $knownOwner) {
            $previousKnownOwner = $previousBuild->knownOwners->get($knownOwner->fqcn);

            if ($this->ownerStructureChanged($previousKnownOwner, $knownOwner)) {
                $changedOwnerFqcns[$knownOwner->fqcn] = true;
            }
        }

        return $changedOwnerFqcns;
    }

    /**
     * Builds known owners from the provided virtual files.
     *
     * @param VirtualPhpSourceFileCollection $virtualFiles the virtual files to inspect
     */
    private function knownOwnersFromVirtualFiles(VirtualPhpSourceFileCollection $virtualFiles): KnownOwnerCollection
    {
        $knownOwners = new KnownOwnerCollection();
        $builder = new KnownOwnersCollectionBuilder();

        foreach ($virtualFiles as $virtualFile) {
            $builder->build($virtualFile->nodes, $knownOwners);
        }

        return $knownOwners;
    }

    /**
     * Indicates whether owner structural metadata changed.
     *
     * @param KnownOwner|null $previousKnownOwner the previous owner metadata
     * @param KnownOwner      $currentKnownOwner  the current owner metadata
     */
    private function ownerStructureChanged(?KnownOwner $previousKnownOwner, KnownOwner $currentKnownOwner): bool
    {
        if (null === $previousKnownOwner) {
            return true;
        }

        return $previousKnownOwner->parentFqcn !== $currentKnownOwner->parentFqcn
            || $previousKnownOwner->kind !== $currentKnownOwner->kind
            || $this->sortedValues($previousKnownOwner->interfaces) !== $this->sortedValues($currentKnownOwner->interfaces)
            || $this->sortedValues($previousKnownOwner->extendsInterfaces) !== $this->sortedValues($currentKnownOwner->extendsInterfaces)
            || $this->sortedValues($previousKnownOwner->traits) !== $this->sortedValues($currentKnownOwner->traits);
    }

    /**
     * Returns sorted scalar values.
     *
     * @param list<string> $values the values to sort
     *
     * @return list<string>
     */
    private function sortedValues(array $values): array
    {
        sort($values);

        return $values;
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
     * Returns a stable key for one impact target.
     *
     * @param MemberImpactTarget $target the impact target
     */
    private function impactTargetKey(MemberImpactTarget $target): string
    {
        return $target->memberId?->hash() ?? $target->parameterId?->hash() ?? $target->owner ?? '';
    }

    /**
     * Checks whether a graph file path belongs to one physical file.
     *
     * @param string                $graphFilePath              the graph virtual file path
     * @param string                $physicalFilePath           the physical file path
     * @param array<string, string> $virtualToPhysicalFilePaths virtual-to-physical file path map
     */
    private function belongsToPhysicalFile(
        string $graphFilePath,
        string $physicalFilePath,
        array $virtualToPhysicalFilePaths,
    ): bool {
        return ($virtualToPhysicalFilePaths[$graphFilePath] ?? null) === $physicalFilePath;
    }

    /**
     * Builds a virtual-to-physical file path map from previous and touched virtual files.
     *
     * @param VirtualPhpSourceFileCollection $previousVirtualFiles the previous build virtual files
     * @param VirtualPhpSourceFileCollection $touchedVirtualFiles  the touched virtual files
     *
     * @return array<string, string>
     */
    private function virtualToPhysicalFilePaths(
        VirtualPhpSourceFileCollection $previousVirtualFiles,
        VirtualPhpSourceFileCollection $touchedVirtualFiles,
    ): array {
        $paths = [];

        foreach ($previousVirtualFiles as $virtualFile) {
            $paths[$virtualFile->virtualFilePath] = $virtualFile->fullFilePath;
        }

        foreach ($touchedVirtualFiles as $virtualFile) {
            $paths[$virtualFile->virtualFilePath] = $virtualFile->fullFilePath;
        }

        return $paths;
    }

    /**
     * Returns the physical file paths represented by touched virtual files.
     *
     * @param VirtualPhpSourceFileCollection $touchedVirtualFiles the touched virtual files
     *
     * @return list<string>
     */
    private function touchedPhysicalFilePaths(VirtualPhpSourceFileCollection $touchedVirtualFiles): array
    {
        $filePaths = [];

        foreach ($touchedVirtualFiles as $virtualFile) {
            $filePaths[$virtualFile->fullFilePath] = $virtualFile->fullFilePath;
        }

        return array_values($filePaths);
    }
}
