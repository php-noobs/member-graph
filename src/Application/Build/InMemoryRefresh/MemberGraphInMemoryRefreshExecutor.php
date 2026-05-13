<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Build\InMemoryRefresh;

use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphBuild;
use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphFactoryBuildReport;
use PhpNoobs\MemberGraph\Application\Build\Factory\Mode\MemberDependencyGraphFactoryBuildMode;
use PhpNoobs\MemberGraph\Application\Build\Factory\Mode\MemberDependencyGraphFactoryRebuildMode;
use PhpNoobs\MemberGraph\Application\Build\Factory\Mode\MemberDependencyGraphFactoryRebuildReason;
use PhpNoobs\MemberGraph\Application\Build\Factory\Plan\MemberDependencyGraphFactoryRebuildPlan;
use PhpNoobs\MemberGraph\Application\Build\Input\MemberGraphBuildInput;
use PhpNoobs\MemberGraph\Application\Build\MemberDependencyGraphBuilder;
use PhpNoobs\MemberGraph\Application\Cache\Core\MemberGraphCacheLoadResult;
use PhpNoobs\MemberGraph\Application\Cache\Core\MemberGraphCacheLoadStatus;
use PhpNoobs\MemberGraph\Application\Cache\Core\MemberGraphCacheWriteResult;
use PhpNoobs\MemberGraph\Application\Cache\Fragment\MemberGraphFragmentCollection;
use PhpNoobs\MemberGraph\Application\Cache\Fragment\MemberGraphFragmenter;
use PhpNoobs\MemberGraph\Application\Cache\Fragment\MemberGraphFragmentMerger;
use PhpNoobs\MemberGraph\Application\Cache\Plan\MemberGraphCacheFileCollection;
use PhpNoobs\MemberGraph\Application\Cache\Plan\MemberGraphCachePlan;
use PhpNoobs\MemberGraph\Application\Cache\VirtualFile\MemberGraphVirtualFileReferenceCollection;
use PhpNoobs\MemberGraph\Application\Issue\MemberGraphIssueCollection;
use PhpNoobs\MemberGraph\Application\Source\MemberGraphPhpSourceRegistryInstance;
use PhpNoobs\PhpSource\VirtualPhpSourceFileCollection;

/**
 * Executes an in-memory partial graph refresh from a closed physical-file working set.
 */
final readonly class MemberGraphInMemoryRefreshExecutor
{
    /**
     * Constructor.
     *
     * @param MemberGraphFragmenter     $fragmenter     the graph fragmenter
     * @param MemberGraphFragmentMerger $fragmentMerger the graph fragment merger
     */
    public function __construct(
        private MemberGraphFragmenter $fragmenter = new MemberGraphFragmenter(),
        private MemberGraphFragmentMerger $fragmentMerger = new MemberGraphFragmentMerger(),
    ) {
    }

    /**
     * Executes the in-memory partial refresh when the working set can be represented by the merged source view.
     *
     * @param MemberDependencyGraphBuild           $previousBuild         the previous complete build
     * @param VirtualPhpSourceFileCollection       $mergedVirtualFiles    the complete current in-memory source view
     * @param MemberGraphInMemoryRefreshWorkingSet $workingSet            the physical-file working set to rebuild
     * @param MemberGraphIssueCollection           $dependencyGraphIssues the dependency graph issue collection
     */
    public function execute(
        MemberDependencyGraphBuild $previousBuild,
        VirtualPhpSourceFileCollection $mergedVirtualFiles,
        MemberGraphInMemoryRefreshWorkingSet $workingSet,
        MemberGraphIssueCollection $dependencyGraphIssues,
    ): ?MemberDependencyGraphBuild {
        if (count($previousBuild->virtualFiles) !== count($previousBuild->virtualFileReferences)) {
            return null;
        }

        $virtualFilesToRebuild = $this->virtualFilesForPhysicalFiles(
            virtualFiles: $mergedVirtualFiles,
            physicalFilePaths: $workingSet->filesToRebuildGraph,
        );

        if (count($workingSet->filesToRebuildGraph) !== count($this->physicalFilePaths($virtualFilesToRebuild))) {
            return null;
        }

        $fileRegistry = new MemberGraphPhpSourceRegistryInstance();
        $fileRegistry->registerVirtualFiles($mergedVirtualFiles);
        $knownOwners = $fileRegistry->getKnownOwners();
        $rebuiltGraph = new MemberDependencyGraphBuilder(
            fileRegistry: $fileRegistry,
            dependencyGraphIssues: $dependencyGraphIssues,
        )->build(new MemberGraphBuildInput(
            knownOwners: $knownOwners,
            virtualFiles: $virtualFilesToRebuild,
        ));
        $previousFragments = $this->fragmenter->fragment(
            graph: $previousBuild->memberDependencyGraph,
            virtualFiles: $previousBuild->virtualFiles,
        );
        $rebuiltFragments = $this->fragmenter->fragment(
            graph: $rebuiltGraph,
            virtualFiles: $virtualFilesToRebuild,
        );
        $mergedFragments = $this->mergeFragments(
            reusableFragments: $this->fragmentsOutsideWorkingSet($previousFragments, $workingSet),
            rebuiltFragments: $rebuiltFragments,
        );
        $virtualFileReferences = MemberGraphVirtualFileReferenceCollection::fromVirtualFiles($mergedVirtualFiles);

        return new MemberDependencyGraphBuild(
            memberDependencyGraph: $this->fragmentMerger->mergeWithKnownOwners($mergedFragments, $knownOwners),
            virtualFiles: $mergedVirtualFiles,
            virtualFileReferences: $virtualFileReferences,
            knownOwners: $knownOwners,
            dependencyGraphIssues: $dependencyGraphIssues,
            buildReport: $this->createBuildReport(
                loadedVirtualFileCount: count($virtualFilesToRebuild),
                virtualFileReferenceCount: count($virtualFileReferences),
                workingSet: $workingSet,
            ),
            sourceRegistry: $fileRegistry,
        );
    }

    /**
     * Returns virtual files whose physical file path belongs to the requested physical-file collection.
     *
     * @param VirtualPhpSourceFileCollection $virtualFiles      the virtual files to filter
     * @param MemberGraphCacheFileCollection $physicalFilePaths the physical file paths to keep
     */
    private function virtualFilesForPhysicalFiles(
        VirtualPhpSourceFileCollection $virtualFiles,
        MemberGraphCacheFileCollection $physicalFilePaths,
    ): VirtualPhpSourceFileCollection {
        $filteredVirtualFiles = new VirtualPhpSourceFileCollection();

        foreach ($virtualFiles as $virtualFile) {
            if (!$physicalFilePaths->contains($virtualFile->fullFilePath)) {
                continue;
            }

            $filteredVirtualFiles->add($virtualFile);
        }

        return $filteredVirtualFiles;
    }

    /**
     * Keeps previous fragments outside the in-memory refresh working set.
     *
     * @param MemberGraphFragmentCollection        $fragments  the previous graph fragments
     * @param MemberGraphInMemoryRefreshWorkingSet $workingSet the working set being rebuilt
     */
    private function fragmentsOutsideWorkingSet(
        MemberGraphFragmentCollection $fragments,
        MemberGraphInMemoryRefreshWorkingSet $workingSet,
    ): MemberGraphFragmentCollection {
        $reusableFragments = new MemberGraphFragmentCollection();

        foreach ($fragments as $filePath => $fragment) {
            if ($workingSet->hasFileToRebuildGraph($filePath)) {
                continue;
            }

            $reusableFragments->add($filePath, $fragment);
        }

        return $reusableFragments;
    }

    /**
     * Merges reusable and rebuilt fragments.
     *
     * @param MemberGraphFragmentCollection $reusableFragments the fragments reused from the previous build
     * @param MemberGraphFragmentCollection $rebuiltFragments  the fragments rebuilt from current in-memory ASTs
     */
    private function mergeFragments(
        MemberGraphFragmentCollection $reusableFragments,
        MemberGraphFragmentCollection $rebuiltFragments,
    ): MemberGraphFragmentCollection {
        $fragments = new MemberGraphFragmentCollection();

        foreach ($reusableFragments as $filePath => $fragment) {
            $fragments->add($filePath, $fragment);
        }

        foreach ($rebuiltFragments as $filePath => $fragment) {
            $fragments->add($filePath, $fragment);
        }

        return $fragments;
    }

    /**
     * Returns unique physical file paths represented by a virtual-file collection.
     *
     * @param VirtualPhpSourceFileCollection $virtualFiles the virtual files to inspect
     *
     * @return list<string>
     */
    private function physicalFilePaths(VirtualPhpSourceFileCollection $virtualFiles): array
    {
        $filePaths = [];

        foreach ($virtualFiles as $virtualFile) {
            $filePaths[$virtualFile->fullFilePath] = $virtualFile->fullFilePath;
        }

        return array_values($filePaths);
    }

    /**
     * Creates the in-memory partial refresh build report.
     *
     * @param int                                  $loadedVirtualFileCount    the number of virtual files rebuilt
     * @param int                                  $virtualFileReferenceCount the number of virtual file references exposed by the result
     * @param MemberGraphInMemoryRefreshWorkingSet $workingSet                the resolved in-memory refresh working set
     */
    private function createBuildReport(
        int $loadedVirtualFileCount,
        int $virtualFileReferenceCount,
        MemberGraphInMemoryRefreshWorkingSet $workingSet,
    ): MemberDependencyGraphFactoryBuildReport {
        $cachePlan = new MemberGraphCachePlan(
            freshFiles: new MemberGraphCacheFileCollection(),
            staleFiles: new MemberGraphCacheFileCollection(),
            missingFiles: new MemberGraphCacheFileCollection(),
            canUseFastPath: false,
        );
        $rebuildPlan = new MemberDependencyGraphFactoryRebuildPlan(
            mode: MemberDependencyGraphFactoryRebuildMode::PARTIAL_BUILD_CANDIDATE,
            reason: MemberDependencyGraphFactoryRebuildReason::PARTIAL_REBUILD_CANDIDATE,
            cachePlan: $cachePlan,
            filesToBuild: $workingSet->filesToRebuildGraph,
            fragmentsToReuse: new MemberGraphCacheFileCollection(),
        );

        return new MemberDependencyGraphFactoryBuildReport(
            buildMode: MemberDependencyGraphFactoryBuildMode::IN_MEMORY_PARTIAL_REFRESH,
            cacheLoadResult: MemberGraphCacheLoadResult::notLoaded(MemberGraphCacheLoadStatus::CACHE_FILE_MISSING),
            cacheWriteResult: MemberGraphCacheWriteResult::notWritten('memory://member-graph'),
            cachePlan: $cachePlan,
            rebuildPlan: $rebuildPlan,
            scannedFileCount: 0,
            loadedVirtualFileCount: $loadedVirtualFileCount,
            virtualFileReferenceCount: $virtualFileReferenceCount,
            inMemoryRefreshWorkingSet: $workingSet,
        );
    }
}
