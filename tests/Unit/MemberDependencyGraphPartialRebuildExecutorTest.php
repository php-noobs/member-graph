<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Tests\Unit;

use PhpNoobs\MemberGraph\Application\Build\GlobalIndex\MemberGraphPartialGlobalIndexes;
use PhpNoobs\MemberGraph\Application\Build\GlobalIndexRebuild\MemberGraphGlobalIndexRebuildInput;
use PhpNoobs\MemberGraph\Application\Build\GlobalIndexRebuild\MemberGraphLoadedSourceMetadata;
use PhpNoobs\MemberGraph\Application\Build\Input\MemberGraphBuildInput;
use PhpNoobs\MemberGraph\Application\Build\MemberDependencyGraphBuilder;
use PhpNoobs\MemberGraph\Application\Build\PartialGraph\Execution\MemberDependencyGraphPartialRebuildExecutor;
use PhpNoobs\MemberGraph\Application\Build\PartialGraph\Input\MemberDependencyGraphPartialRebuildInput;
use PhpNoobs\MemberGraph\Application\Build\PartialGraph\Input\MemberDependencyGraphPartialRebuildPreparedInput;
use PhpNoobs\MemberGraph\Application\Build\PartialGraph\Loading\MemberDependencyGraphPartialRebuildLoadedInput;
use PhpNoobs\MemberGraph\Application\Build\PartialGraph\SourceView\MemberDependencyGraphPartialRebuildSourceView;
use PhpNoobs\MemberGraph\Application\Build\PartialGraph\WorkingSet\MemberDependencyGraphPartialRebuildWorkingSet;
use PhpNoobs\MemberGraph\Application\Cache\Fragment\MemberGraphFragmentCollection;
use PhpNoobs\MemberGraph\Application\Cache\Fragment\MemberGraphFragmenter;
use PhpNoobs\MemberGraph\Application\Cache\Plan\MemberGraphCacheFileCollection;
use PhpNoobs\MemberGraph\Application\Cache\Snapshot\Declaration\MemberGraphDeclarationSnapshot;
use PhpNoobs\MemberGraph\Application\Cache\Snapshot\MemberGraphGlobalIndexInputSnapshot;
use PhpNoobs\MemberGraph\Application\Cache\Snapshot\MemberGraphVirtualSourceMetadataCollection;
use PhpNoobs\MemberGraph\Application\Cache\VirtualFile\MemberGraphVirtualFileReferenceCollection;
use PhpNoobs\MemberGraph\Application\Source\MemberGraphPhpSourceRegistryInstance;
use PhpNoobs\MemberGraph\Domain\Availability\AvailableMemberCollection;
use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclaration;
use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclarationCollection;
use PhpNoobs\MemberGraph\Domain\Graph\MemberDependencyGraph;
use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;
use PhpNoobs\MemberGraph\Domain\Index\Constant\ClassConstantTypeIndex;
use PhpNoobs\MemberGraph\Domain\Index\Constant\ClassConstantValueIndex;
use PhpNoobs\MemberGraph\Domain\Index\Function\FunctionParameterTypeIndex;
use PhpNoobs\MemberGraph\Domain\Index\Function\FunctionReturnTypeIndex;
use PhpNoobs\MemberGraph\Domain\Index\Method\MethodParameterTypeIndex;
use PhpNoobs\MemberGraph\Domain\Index\Method\MethodReturnTypeIndex;
use PhpNoobs\MemberGraph\Domain\Index\Polymorphism\PolymorphicImplementationsIndex;
use PhpNoobs\MemberGraph\Domain\Index\Property\PropertyTypeIndex;
use PhpNoobs\MemberGraph\Domain\Owner\KnownOwner;
use PhpNoobs\MemberGraph\Domain\Owner\KnownOwnerCollection;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerKind;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterUsageCollection;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsageCollection;
use PhpNoobs\PhpSource\VirtualPhpSourceFileCollection;
use PHPUnit\Framework\TestCase;

/**
 * Covers isolated partial rebuild execution.
 */
final class MemberDependencyGraphPartialRebuildExecutorTest extends TestCase
{
    private string $workspace;

    private VirtualPhpSourceFileCollection $lastFullBuildVirtualFiles;

    /**
     * Prepares an isolated filesystem workspace.
     */
    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/member-graph-partial-executor-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0o777, true);
    }

    /**
     * Removes the isolated filesystem workspace.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    /**
     * Ensures rebuilt fragments are merged with reusable cached fragments.
     */
    public function testItExecutesPartialRebuildFromWorkingSet(): void
    {
        $changedFilePath = $this->workspace.'/A.php';
        $reusableFilePath = $this->workspace.'/B.php';
        $knownOwners = new KnownOwnerCollection();
        $fragmentsToReuse = new MemberGraphFragmentCollection();
        $workingSet = new MemberDependencyGraphPartialRebuildWorkingSet();

        file_put_contents($changedFilePath, <<<'PHP'
            <?php

            namespace App;

            final class A
            {
                public function changed(): void
                {
                }
            }
            PHP);
        $knownOwners->add(new KnownOwner('App\\A', null, OwnerKind::CLASS_));
        $knownOwners->add(new KnownOwner('App\\B', null, OwnerKind::CLASS_));
        $fragmentsToReuse->add($reusableFilePath, $this->fragmentWithDeclaration(
            new MemberDeclaration(
                id: new MemberId('App\\B', 'run', MemberType::METHOD),
                file: $reusableFilePath.'.virtual.0',
            ),
            $knownOwners,
        ));
        $workingSet
            ->addFileToParseForContext($changedFilePath)
            ->addFileToRebuildGraph($changedFilePath)
            ->setFragmentsToReuse($fragmentsToReuse)
            ->setIterations(1);

        $graph = new MemberDependencyGraphPartialRebuildExecutor(new MemberGraphPhpSourceRegistryInstance())->execute(
            preparedInput: $this->preparedInput($knownOwners, $fragmentsToReuse),
            workingSet: $workingSet,
        );

        self::assertNotNull($graph->declarations->get(new MemberId('App\\A', 'changed', MemberType::METHOD)));
        self::assertNotNull($graph->declarations->get(new MemberId('App\\B', 'run', MemberType::METHOD)));
        self::assertNull($graph->declarations->get(new MemberId('App\\A', 'old', MemberType::METHOD)));
    }

    /**
     * Ensures a simple partial rebuild produces the same declarations as a fresh full build.
     */
    public function testItMatchesFullBuildDeclarationsAfterOneFileChanges(): void
    {
        $aFilePath = $this->workspace.'/A.php';
        $bFilePath = $this->workspace.'/B.php';

        file_put_contents($aFilePath, <<<'PHP'
            <?php

            namespace App;

            final class A
            {
                public function run(): void
                {
                }
            }
            PHP);
        file_put_contents($bFilePath, <<<'PHP'
            <?php

            namespace App;

            final class B
            {
                public function old(): void
                {
                }
            }
            PHP);

        $initialGraph = $this->fullBuild([$aFilePath, $bFilePath]);
        $initialFragments = new MemberGraphFragmenter()->fragment(
            graph: $initialGraph,
            virtualFiles: $this->lastFullBuildVirtualFiles,
        );
        $fragmentsToReuse = new MemberGraphFragmentCollection();
        $aFragment = $initialFragments->get($this->normalizePath($aFilePath));

        self::assertInstanceOf(MemberDependencyGraph::class, $aFragment);
        $fragmentsToReuse->add($aFilePath, $aFragment);

        file_put_contents($bFilePath, <<<'PHP'
            <?php

            namespace App;

            final class B
            {
                public function changed(): void
                {
                }
            }
            PHP);

        $workingSet = new MemberDependencyGraphPartialRebuildWorkingSet();
        $workingSet
            ->addFileToParseForContext($bFilePath)
            ->addFileToRebuildGraph($bFilePath)
            ->setFragmentsToReuse($fragmentsToReuse)
            ->setIterations(1);

        $partialGraph = new MemberDependencyGraphPartialRebuildExecutor(new MemberGraphPhpSourceRegistryInstance())->execute(
            preparedInput: $this->preparedInput($initialGraph->knownOwners, $fragmentsToReuse),
            workingSet: $workingSet,
        );
        $fullGraph = $this->fullBuild([$aFilePath, $bFilePath]);

        self::assertSame(
            $this->declarationHashes($fullGraph),
            $this->declarationHashes($partialGraph),
        );
    }

    /**
     * Ensures a simple partial rebuild preserves the same member usages as a fresh full build.
     */
    public function testItMatchesFullBuildUsagesAfterOneFileChanges(): void
    {
        $aFilePath = $this->workspace.'/A.php';
        $bFilePath = $this->workspace.'/B.php';

        file_put_contents($aFilePath, <<<'PHP'
            <?php

            namespace App;

            final class A
            {
                public function run(B $b): void
                {
                    $b->changed();
                }
            }
            PHP);
        file_put_contents($bFilePath, <<<'PHP'
            <?php

            namespace App;

            final class B
            {
                public function changed(): void
                {
                }
            }
            PHP);

        $initialGraph = $this->fullBuild([$aFilePath, $bFilePath]);
        $initialFragments = new MemberGraphFragmenter()->fragment(
            graph: $initialGraph,
            virtualFiles: $this->lastFullBuildVirtualFiles,
        );
        $fragmentsToReuse = new MemberGraphFragmentCollection();
        $aFragment = $initialFragments->get($this->normalizePath($aFilePath));

        self::assertInstanceOf(MemberDependencyGraph::class, $aFragment);
        $fragmentsToReuse->add($aFilePath, $aFragment);

        file_put_contents($bFilePath, <<<'PHP'
            <?php

            namespace App;

            final class B
            {
                public function changed(): void
                {
                    $value = 1;
                }
            }
            PHP);

        $workingSet = new MemberDependencyGraphPartialRebuildWorkingSet();
        $workingSet
            ->addFileToParseForContext($bFilePath)
            ->addFileToRebuildGraph($bFilePath)
            ->setFragmentsToReuse($fragmentsToReuse)
            ->setIterations(1);

        $partialGraph = new MemberDependencyGraphPartialRebuildExecutor(new MemberGraphPhpSourceRegistryInstance())->execute(
            preparedInput: $this->preparedInput($initialGraph->knownOwners, $fragmentsToReuse),
            workingSet: $workingSet,
        );
        $fullGraph = $this->fullBuild([$aFilePath, $bFilePath]);

        self::assertSame(
            $this->declarationHashes($fullGraph),
            $this->declarationHashes($partialGraph),
        );
        self::assertSame(
            $this->usageSignatures($fullGraph),
            $this->usageSignatures($partialGraph),
        );
    }

    /**
     * Creates a prepared input for isolated executor tests.
     *
     * @param KnownOwnerCollection          $knownOwners      the known owners
     * @param MemberGraphFragmentCollection $fragmentsToReuse the reusable fragments
     */
    private function preparedInput(
        KnownOwnerCollection $knownOwners,
        MemberGraphFragmentCollection $fragmentsToReuse,
    ): MemberDependencyGraphPartialRebuildPreparedInput {
        $filesToBuild = new MemberGraphCacheFileCollection();
        $virtualFileReferences = new MemberGraphVirtualFileReferenceCollection();
        $partialRebuildInput = new MemberDependencyGraphPartialRebuildInput(
            filesToBuild: $filesToBuild,
            fragmentsToReuse: $fragmentsToReuse,
            globalIndexInputSnapshot: new MemberGraphGlobalIndexInputSnapshot(),
            virtualFileReferences: $virtualFileReferences,
            knownOwners: $knownOwners,
        );

        return new MemberDependencyGraphPartialRebuildPreparedInput(
            partialRebuildInput: $partialRebuildInput,
            sourceView: new MemberDependencyGraphPartialRebuildSourceView(
                globalIndexRebuildInput: new MemberGraphGlobalIndexRebuildInput(
                    reusableSources: new MemberGraphVirtualSourceMetadataCollection(),
                    filesToBuild: $filesToBuild,
                    fragmentsToReuse: $fragmentsToReuse,
                    knownOwners: $knownOwners,
                    virtualFileReferences: $virtualFileReferences,
                ),
                loadedInput: new MemberDependencyGraphPartialRebuildLoadedInput(
                    loadedVirtualFiles: new VirtualPhpSourceFileCollection(),
                    loadedDeclarationSnapshot: new MemberGraphDeclarationSnapshot(),
                    loadedSourceMetadata: new MemberGraphLoadedSourceMetadata(),
                ),
                allSourceMetadata: new MemberGraphVirtualSourceMetadataCollection(),
            ),
            partialGlobalIndexes: $this->partialGlobalIndexes($knownOwners),
            fragmentsToReuse: $fragmentsToReuse,
        );
    }

    /**
     * Creates partial global indexes for executor tests.
     *
     * @param KnownOwnerCollection $knownOwners the known owners
     */
    private function partialGlobalIndexes(KnownOwnerCollection $knownOwners): MemberGraphPartialGlobalIndexes
    {
        return new MemberGraphPartialGlobalIndexes(
            knownOwners: $knownOwners,
            polymorphicImplementationsIndex: new PolymorphicImplementationsIndex(),
            propertyTypeIndex: new PropertyTypeIndex(),
            classConstantTypeIndex: new ClassConstantTypeIndex(),
            classConstantValueIndex: new ClassConstantValueIndex(),
            methodReturnTypeIndex: new MethodReturnTypeIndex(),
            methodParameterTypeIndex: new MethodParameterTypeIndex(),
            functionReturnTypeIndex: new FunctionReturnTypeIndex(),
            functionParameterTypeIndex: new FunctionParameterTypeIndex(),
            mergedDeclarationSnapshot: new MemberGraphDeclarationSnapshot(),
        );
    }

    /**
     * Creates a reusable graph fragment with one declaration.
     *
     * @param MemberDeclaration    $declaration the declaration to include
     * @param KnownOwnerCollection $knownOwners the known owners
     */
    private function fragmentWithDeclaration(
        MemberDeclaration $declaration,
        KnownOwnerCollection $knownOwners,
    ): MemberDependencyGraph {
        $declarations = new MemberDeclarationCollection();
        $declarations->add($declaration);

        return new MemberDependencyGraph(
            declarations: $declarations,
            usages: new MemberUsageCollection(),
            parameterUsages: new ParameterUsageCollection(),
            availableMembers: new AvailableMemberCollection(),
            knownOwners: $knownOwners,
            interfaceImplementationsIndex: new PolymorphicImplementationsIndex(),
            dependencyGraphIssues: null,
        );
    }

    /**
     * Builds a full graph from physical files.
     *
     * @param list<string> $filePaths the physical file paths
     */
    private function fullBuild(array $filePaths): MemberDependencyGraph
    {
        $fileRegistry = new MemberGraphPhpSourceRegistryInstance();

        foreach ($filePaths as $filePath) {
            $fileRegistry->getVirtualFiles($filePath);
        }

        $this->lastFullBuildVirtualFiles = $fileRegistry->getAllVirtualFiles();

        return new MemberDependencyGraphBuilder($fileRegistry)->build(new MemberGraphBuildInput(
            knownOwners: $fileRegistry->getKnownOwners(),
            virtualFiles: $this->lastFullBuildVirtualFiles,
        ));
    }

    /**
     * Normalizes one filesystem path.
     *
     * @param string $path the path to normalize
     */
    private function normalizePath(string $path): string
    {
        return realpath($path) ?: $path;
    }

    /**
     * Returns sorted declaration hashes.
     *
     * @param MemberDependencyGraph $graph the graph to inspect
     *
     * @return list<string>
     */
    private function declarationHashes(MemberDependencyGraph $graph): array
    {
        $hashes = array_map(
            static fn (MemberDeclaration $declaration): string => $declaration->id->hash(),
            $graph->declarations->all(),
        );

        sort($hashes);

        return $hashes;
    }

    /**
     * Returns sorted member usage signatures.
     *
     * @param MemberDependencyGraph $graph the graph to inspect
     *
     * @return list<string>
     */
    private function usageSignatures(MemberDependencyGraph $graph): array
    {
        $signatures = [];

        foreach ($graph->usages->all() as $usagesByTarget) {
            foreach ($usagesByTarget as $usage) {
                $signatures[] = implode('|', [
                    $usage->sourceSymbol,
                    $usage->target->hash(),
                    $usage->type->name,
                    basename($usage->file),
                ]);
            }
        }

        sort($signatures);

        return $signatures;
    }

    /**
     * Removes a directory recursively.
     *
     * @param string $directory the directory to remove
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
