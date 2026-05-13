<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Tests\Unit;

use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphBuild;
use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphFactory;
use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphFactoryOptions;
use PhpNoobs\MemberGraph\Application\Build\Factory\Mode\MemberDependencyGraphFactoryBuildMode;
use PhpNoobs\MemberGraph\Application\Build\Factory\Mode\MemberDependencyGraphFactoryRebuildMode;
use PhpNoobs\MemberGraph\Application\Build\Factory\Mode\MemberDependencyGraphFactoryRebuildReason;
use PhpNoobs\MemberGraph\Application\Issue\MemberGraphIssueCollection;
use PhpNoobs\MemberGraph\Application\Query\MemberDependency;
use PhpNoobs\MemberGraph\Application\Query\MemberGraphQueryService;
use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsageType;
use PhpNoobs\PhpSource\VirtualPhpSourceFile;
use PhpNoobs\PhpSource\VirtualPhpSourceFileCollection;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Covers basic member dependency graph factory build behavior.
 */
final class MemberDependencyGraphFactoryBasicTest extends MemberDependencyGraphFactoryTestCase
{
    /**
     * Ensures directory builds include recursive PHP files and exclude configured directories.
     */
    public function testItBuildsFromDirectoryWithExclusions(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $excludedDirectory = $srcDirectory.'/Excluded';

        mkdir($excludedDirectory, 0o777, true);
        file_put_contents($srcDirectory.'/Included.php', <<<'PHP'
            <?php

            namespace App;

            final class Included
            {
                public function run(): void
                {
                }
            }
            PHP);
        file_put_contents($excludedDirectory.'/Skipped.php', <<<'PHP'
            <?php

            namespace App\Excluded;

            final class Skipped
            {
                public function run(): void
                {
                }
            }
            PHP);
        file_put_contents($srcDirectory.'/notes.txt', 'ignored');

        $issues = new MemberGraphIssueCollection();
        $factory = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $this->workspace.'/member-graph.cache',
            excludedDirectories: [$excludedDirectory],
            dependencyGraphIssues: $issues,
        );

        self::assertInstanceOf(MemberDependencyGraphBuild::class, $factory);
        self::assertSame($issues, $factory->dependencyGraphIssues);
        self::assertSame(MemberDependencyGraphFactoryBuildMode::FULL_BUILD, $factory->buildReport->buildMode);
        self::assertSame(MemberDependencyGraphFactoryRebuildMode::FULL_BUILD, $factory->buildReport->rebuildPlan->mode);
        self::assertSame(
            MemberDependencyGraphFactoryRebuildReason::GLOBAL_INDEX_REBUILD_REQUIRED,
            $factory->buildReport->rebuildPlan->reason,
        );
        self::assertTrue($factory->usedFullBuild());
        self::assertFalse($factory->usedFastPath());
        self::assertTrue($factory->hasLoadedVirtualFiles());
        self::assertSame($factory->virtualFiles, $factory->loadedVirtualFiles());
        self::assertSame(1, $factory->buildReport->scannedFileCount);
        self::assertSame(1, $factory->buildReport->loadedVirtualFileCount);
        self::assertSame(1, $factory->buildReport->virtualFileReferenceCount);
        self::assertCount(1, $factory->virtualFiles);
        self::assertCount(1, $factory->virtualFileReferences);
        self::assertNotNull($factory->knownOwners->get('App\\Included'));
        self::assertNull($factory->knownOwners->get('App\\Excluded\\Skipped'));
        self::assertNotNull($factory->memberDependencyGraph->declarations->get(
            new MemberId('App\\Included', 'run', MemberType::METHOD),
        ));
        self::assertNull($factory->memberDependencyGraph->declarations->get(
            new MemberId('App\\Excluded\\Skipped', 'run', MemberType::METHOD),
        ));
        self::assertNotNull($factory->virtualFileReferences->getByVirtualFilePath(
            (realpath($srcDirectory.'/Included.php') ?: $srcDirectory.'/Included.php').'.virtual.0',
        ));
        self::assertCount(1, $factory->virtualFileReferences->getByFullFilePath(
            realpath($srcDirectory.'/Included.php') ?: $srcDirectory.'/Included.php',
        ));
    }

    /**
     * Ensures factory results expose the graph recomposed from fragments.
     */
    public function testItReturnsMergedGraphFromFragments(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';
        $aRun = new MemberId('App\\A', 'run', MemberType::METHOD);
        $bSend = new MemberId('App\\B', 'send', MemberType::METHOD);

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($aFilePath, <<<'PHP'
            <?php

            namespace App;

            final class A
            {
                public function run(): void
                {
                    B::send();
                }
            }
            PHP);
        file_put_contents($bFilePath, <<<'PHP'
            <?php

            namespace App;

            final class B
            {
                public static function send(): void
                {
                }
            }
            PHP);

        $factory = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $this->workspace.'/member-graph.cache',
        );
        $query = MemberGraphQueryService::fromGraph($factory->memberDependencyGraph);

        self::assertNotNull($factory->memberDependencyGraph->declarations->get($aRun));
        self::assertNotNull($factory->memberDependencyGraph->declarations->get($bSend));
        self::assertCount(1, $factory->memberDependencyGraph->usages);
        self::assertTrue($query->dependenciesOfMember($aRun)->contains(new MemberDependency(
            source: $aRun,
            target: $bSend,
            usageType: MemberUsageType::STATIC_METHOD_CALL,
            file: (realpath($aFilePath) ?: $aFilePath).'.virtual.0',
        )));
    }

    /**
     * Ensures in-memory virtual-file builds do not read changed physical files.
     */
    public function testItBuildsFromVirtualFilesWithoutReadingPhysicalFiles(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $filePath = $srcDirectory.'/Mailer.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                public function send(): void
                {
                }
            }
            PHP);

        $initialBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );

        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class PhysicalFileChanged
            {
                public function changed(): void
                {
                }
            }
            PHP);

        $rebuilt = MemberDependencyGraphFactory::fromVirtualFiles($initialBuild->virtualFiles);

        self::assertSame(MemberDependencyGraphFactoryBuildMode::FULL_BUILD, $rebuilt->buildReport->buildMode);
        self::assertSame(MemberDependencyGraphFactoryRebuildMode::FULL_BUILD, $rebuilt->buildReport->rebuildPlan->mode);
        self::assertSame(0, $rebuilt->buildReport->scannedFileCount);
        self::assertSame(1, $rebuilt->buildReport->loadedVirtualFileCount);
        self::assertFalse($rebuilt->buildReport->cacheWriteResult->isWritten());
        self::assertNotNull($rebuilt->knownOwners->get('App\\Mailer'));
        self::assertNull($rebuilt->knownOwners->get('App\\PhysicalFileChanged'));
        self::assertNotNull($rebuilt->memberDependencyGraph->declarations->get(
            new MemberId('App\\Mailer', 'send', MemberType::METHOD),
        ));
        self::assertNull($rebuilt->memberDependencyGraph->declarations->get(
            new MemberId('App\\PhysicalFileChanged', 'changed', MemberType::METHOD),
        ));
    }

    /**
     * Ensures in-memory virtual-file builds use the current mutated AST nodes.
     */
    public function testItBuildsFromMutatedVirtualFileAst(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $filePath = $srcDirectory.'/Mailer.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                public function send(): void
                {
                }
            }
            PHP);

        $initialBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $virtualFile = $initialBuild->virtualFiles->get(0);

        self::assertNotNull($virtualFile);

        $class = self::firstClass($virtualFile->nodes);
        $method = self::firstClassMethod($class);
        $class->name = new Identifier('Sender');
        $method->name = new Identifier('deliver');
        $virtualFile->update($virtualFile->nodes);

        $rebuilt = MemberDependencyGraphFactory::fromVirtualFiles($initialBuild->virtualFiles);

        self::assertNotNull($rebuilt->knownOwners->get('App\\Sender'));
        self::assertNull($rebuilt->knownOwners->get('App\\Mailer'));
        self::assertNotNull($rebuilt->memberDependencyGraph->declarations->get(
            new MemberId('App\\Sender', 'deliver', MemberType::METHOD),
        ));
        self::assertNull($rebuilt->memberDependencyGraph->declarations->get(
            new MemberId('App\\Mailer', 'send', MemberType::METHOD),
        ));
    }

    /**
     * Ensures touched virtual files refresh a complete in-memory source view.
     */
    public function testItRefreshesFromTouchedVirtualFilesWithACompleteSourceView(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
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
                public function send(): void
                {
                }
            }
            PHP);

        $initialBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $touchedVirtualFile = $initialBuild->virtualFiles->get(0);

        self::assertNotNull($touchedVirtualFile);

        $class = self::firstClass($touchedVirtualFile->nodes);
        $method = self::firstClassMethod($class);
        $class->name = new Identifier('C');
        $method->name = new Identifier('changed');

        $refreshedBuild = MemberDependencyGraphFactory::refreshFromTouchedVirtualFiles(
            previousBuild: $initialBuild,
            touchedVirtualFiles: new VirtualPhpSourceFileCollection()->add($touchedVirtualFile),
        );

        self::assertSame(
            MemberDependencyGraphFactoryBuildMode::IN_MEMORY_PARTIAL_REFRESH,
            $refreshedBuild->buildReport->buildMode,
        );
        self::assertFalse($refreshedBuild->usedFullBuild());
        self::assertFalse($refreshedBuild->usedInMemoryFullFallback());
        self::assertTrue($refreshedBuild->usedInMemoryPartialRefresh());
        self::assertNotNull($refreshedBuild->buildReport->inMemoryRefreshWorkingSet);
        self::assertTrue($refreshedBuild->buildReport->inMemoryRefreshWorkingSet->hasFileToRebuildGraph(
            realpath($aFilePath) ?: $aFilePath,
        ));
        self::assertSame(0, $refreshedBuild->buildReport->scannedFileCount);
        self::assertSame(1, $refreshedBuild->buildReport->loadedVirtualFileCount);
        self::assertCount(2, $refreshedBuild->virtualFiles);
        self::assertTrue($refreshedBuild->sourceRegistry()->hasFile(realpath($aFilePath) ?: $aFilePath));
        self::assertTrue($refreshedBuild->sourceRegistry()->hasFile(realpath($bFilePath) ?: $bFilePath));
        self::assertNotNull($refreshedBuild->knownOwners->get('App\\C'));
        self::assertNotNull($refreshedBuild->knownOwners->get('App\\B'));
        self::assertNull($refreshedBuild->knownOwners->get('App\\A'));
        self::assertNotNull($refreshedBuild->memberDependencyGraph->declarations->get(
            new MemberId('App\\C', 'changed', MemberType::METHOD),
        ));
        self::assertNotNull($refreshedBuild->memberDependencyGraph->declarations->get(
            new MemberId('App\\B', 'send', MemberType::METHOD),
        ));
        self::assertNull($refreshedBuild->memberDependencyGraph->declarations->get(
            new MemberId('App\\A', 'run', MemberType::METHOD),
        ));
    }

    /**
     * Ensures in-memory partial refresh rebuilds touched and impacted files.
     */
    public function testItRefreshesImpactedFilesPartiallyFromTouchedVirtualFiles(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
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

        $initialBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $touchedVirtualFile = self::virtualFileForPhysicalFile($initialBuild, $bFilePath);
        $method = self::firstClassMethod(self::firstClass($touchedVirtualFile->nodes));
        $method->name = new Identifier('next');

        $refreshedBuild = MemberDependencyGraphFactory::refreshFromTouchedVirtualFiles(
            previousBuild: $initialBuild,
            touchedVirtualFiles: new VirtualPhpSourceFileCollection()->add($touchedVirtualFile),
        );
        $fullBuild = MemberDependencyGraphFactory::fromVirtualFiles($refreshedBuild->virtualFiles);

        self::assertSame(
            MemberDependencyGraphFactoryBuildMode::IN_MEMORY_PARTIAL_REFRESH,
            $refreshedBuild->buildReport->buildMode,
        );
        self::assertNotNull($refreshedBuild->buildReport->inMemoryRefreshWorkingSet);
        self::assertTrue($refreshedBuild->buildReport->inMemoryRefreshWorkingSet->hasFileToRebuildGraph(
            realpath($aFilePath) ?: $aFilePath,
        ));
        self::assertTrue($refreshedBuild->buildReport->inMemoryRefreshWorkingSet->hasFileToRebuildGraph(
            realpath($bFilePath) ?: $bFilePath,
        ));
        self::assertSame(2, $refreshedBuild->buildReport->loadedVirtualFileCount);
        self::assertSame(
            $this->declarationHashes($fullBuild->memberDependencyGraph),
            $this->declarationHashes($refreshedBuild->memberDependencyGraph),
        );
        self::assertSame(
            $this->usageSignatures($fullBuild->memberDependencyGraph),
            $this->usageSignatures($refreshedBuild->memberDependencyGraph),
        );
    }

    /**
     * Ensures in-memory partial refresh does not expand owner usages when owner structure is unchanged.
     */
    public function testItDoesNotExpandOwnerUsagesWhenTouchedOwnerStructureIsUnchanged(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($aFilePath, <<<'PHP'
            <?php

            namespace App;

            final class A
            {
                public function run(B $b): void
                {
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

        $initialBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $touchedVirtualFile = self::virtualFileForPhysicalFile($initialBuild, $bFilePath);
        $method = self::firstClassMethod(self::firstClass($touchedVirtualFile->nodes));
        $method->name = new Identifier('next');

        $refreshedBuild = MemberDependencyGraphFactory::refreshFromTouchedVirtualFiles(
            previousBuild: $initialBuild,
            touchedVirtualFiles: new VirtualPhpSourceFileCollection()->add($touchedVirtualFile),
        );
        $fullBuild = MemberDependencyGraphFactory::fromVirtualFiles($refreshedBuild->virtualFiles);

        self::assertSame(
            MemberDependencyGraphFactoryBuildMode::IN_MEMORY_PARTIAL_REFRESH,
            $refreshedBuild->buildReport->buildMode,
        );
        self::assertNotNull($refreshedBuild->buildReport->inMemoryRefreshWorkingSet);
        self::assertFalse($refreshedBuild->buildReport->inMemoryRefreshWorkingSet->hasFileToRebuildGraph(
            realpath($aFilePath) ?: $aFilePath,
        ));
        self::assertTrue($refreshedBuild->buildReport->inMemoryRefreshWorkingSet->hasFileToRebuildGraph(
            realpath($bFilePath) ?: $bFilePath,
        ));
        self::assertSame(1, $refreshedBuild->buildReport->loadedVirtualFileCount);
        self::assertSame(
            $this->declarationHashes($fullBuild->memberDependencyGraph),
            $this->declarationHashes($refreshedBuild->memberDependencyGraph),
        );
        self::assertSame(
            $this->usageSignatures($fullBuild->memberDependencyGraph),
            $this->usageSignatures($refreshedBuild->memberDependencyGraph),
        );
    }

    /**
     * Ensures refresh falls back to a full in-memory build when the previous build has no virtual files.
     */
    public function testItFallsBackToFullInMemoryRefreshWhenPreviousBuildHasNoVirtualFiles(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $filePath = $srcDirectory.'/Mailer.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                public function send(): void
                {
                }
            }
            PHP);

        $initialBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $fastPathBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );

        self::assertTrue($fastPathBuild->usedFastPath());
        self::assertCount(0, $fastPathBuild->virtualFiles);

        $refreshedBuild = MemberDependencyGraphFactory::refreshFromTouchedVirtualFiles(
            previousBuild: $fastPathBuild,
            touchedVirtualFiles: $initialBuild->virtualFiles,
        );

        self::assertSame(
            MemberDependencyGraphFactoryBuildMode::IN_MEMORY_FULL_FALLBACK,
            $refreshedBuild->buildReport->buildMode,
        );
        self::assertTrue($refreshedBuild->usedFullBuild());
        self::assertTrue($refreshedBuild->usedInMemoryFullFallback());
        self::assertFalse($refreshedBuild->usedInMemoryPartialRefresh());
        self::assertSame(1, $refreshedBuild->buildReport->loadedVirtualFileCount);
        self::assertNotNull($refreshedBuild->memberDependencyGraph->declarations->get(
            new MemberId('App\\Mailer', 'send', MemberType::METHOD),
        ));
    }

    /**
     * Ensures in-memory partial refresh recomputes global facts from the merged source view.
     */
    public function testItRefreshesGlobalFactsFromTouchedVirtualFiles(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $contractFilePath = $srcDirectory.'/Contract.php';
        $serviceFilePath = $srcDirectory.'/Service.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($contractFilePath, <<<'PHP'
            <?php

            namespace App;

            interface Contract
            {
                public function process(): void;
            }
            PHP);
        file_put_contents($serviceFilePath, <<<'PHP'
            <?php

            namespace App;

            final class Service
            {
                public function process(): void
                {
                }
            }
            PHP);

        $initialBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $touchedVirtualFile = self::virtualFileContainingClass($initialBuild, 'Service');
        $class = self::firstClass($touchedVirtualFile->nodes);
        $class->implements[] = new Name('Contract');

        $refreshedBuild = MemberDependencyGraphFactory::refreshFromTouchedVirtualFiles(
            previousBuild: $initialBuild,
            touchedVirtualFiles: new VirtualPhpSourceFileCollection()->add($touchedVirtualFile),
        );
        $fullBuild = MemberDependencyGraphFactory::fromVirtualFiles($refreshedBuild->virtualFiles);

        self::assertSame(
            MemberDependencyGraphFactoryBuildMode::IN_MEMORY_PARTIAL_REFRESH,
            $refreshedBuild->buildReport->buildMode,
        );
        self::assertSame(
            $this->availableMemberSignatures($fullBuild->memberDependencyGraph),
            $this->availableMemberSignatures($refreshedBuild->memberDependencyGraph),
        );
        self::assertEquals(
            $fullBuild->memberDependencyGraph->interfaceImplementationsIndex,
            $refreshedBuild->memberDependencyGraph->interfaceImplementationsIndex,
        );
        self::assertNotNull($refreshedBuild->knownOwners->get('App\\Service'));
        self::assertNotNull($refreshedBuild->knownOwners->get('App\\Contract'));
    }

    /**
     * Ensures one touched virtual file rebuilds every virtual file from the same physical file.
     */
    public function testItRefreshesAllVirtualFilesFromTouchedPhysicalFile(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $comboFilePath = $srcDirectory.'/Combo.php';
        $runnerFilePath = $srcDirectory.'/Runner.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($comboFilePath, <<<'PHP'
            <?php

            namespace App;

            final class A
            {
                public function keep(): void
                {
                }
            }

            final class B
            {
                public function changed(): void
                {
                }
            }
            PHP);
        file_put_contents($runnerFilePath, <<<'PHP'
            <?php

            namespace App;

            final class Runner
            {
                public function run(B $b): void
                {
                    $b->changed();
                }
            }
            PHP);

        $initialBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $touchedVirtualFile = self::virtualFileContainingClass($initialBuild, 'B');
        $method = self::firstClassMethod(self::firstClass($touchedVirtualFile->nodes));
        $method->name = new Identifier('next');

        $refreshedBuild = MemberDependencyGraphFactory::refreshFromTouchedVirtualFiles(
            previousBuild: $initialBuild,
            touchedVirtualFiles: new VirtualPhpSourceFileCollection()->add($touchedVirtualFile),
        );
        $fullBuild = MemberDependencyGraphFactory::fromVirtualFiles($refreshedBuild->virtualFiles);

        self::assertSame(
            MemberDependencyGraphFactoryBuildMode::IN_MEMORY_PARTIAL_REFRESH,
            $refreshedBuild->buildReport->buildMode,
        );
        self::assertNotNull($refreshedBuild->buildReport->inMemoryRefreshWorkingSet);
        self::assertTrue($refreshedBuild->buildReport->inMemoryRefreshWorkingSet->hasFileToRebuildGraph(
            realpath($comboFilePath) ?: $comboFilePath,
        ));
        self::assertTrue($refreshedBuild->buildReport->inMemoryRefreshWorkingSet->hasFileToRebuildGraph(
            realpath($runnerFilePath) ?: $runnerFilePath,
        ));
        self::assertSame(3, $refreshedBuild->buildReport->loadedVirtualFileCount);
        self::assertCount(2, $refreshedBuild->virtualFileReferences->getByFullFilePath(
            realpath($comboFilePath) ?: $comboFilePath,
        ));
        self::assertSame(
            $this->declarationHashes($fullBuild->memberDependencyGraph),
            $this->declarationHashes($refreshedBuild->memberDependencyGraph),
        );
        self::assertSame(
            $this->usageSignatures($fullBuild->memberDependencyGraph),
            $this->usageSignatures($refreshedBuild->memberDependencyGraph),
        );
    }

    /**
     * Ensures unchanged files can rebuild the graph from cached fragments without loading virtual files.
     */
    public function testItUsesFastPathFromFreshCachedFragments(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';
        $aRun = new MemberId('App\\A', 'run', MemberType::METHOD);
        $bSend = new MemberId('App\\B', 'send', MemberType::METHOD);

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($aFilePath, <<<'PHP'
            <?php

            namespace App;

            final class A
            {
                public function run(): void
                {
                    B::send();
                }
            }
            PHP);
        file_put_contents($bFilePath, <<<'PHP'
            <?php

            namespace App;

            final class B
            {
                public static function send(): void
                {
                }
            }
            PHP);

        MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );

        $factory = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $query = MemberGraphQueryService::fromGraph($factory->memberDependencyGraph);

        self::assertInstanceOf(MemberDependencyGraphBuild::class, $factory);
        self::assertSame(MemberDependencyGraphFactoryBuildMode::FAST_PATH, $factory->buildReport->buildMode);
        self::assertSame(MemberDependencyGraphFactoryRebuildMode::FAST_PATH, $factory->buildReport->rebuildPlan->mode);
        self::assertTrue($factory->usedFastPath());
        self::assertFalse($factory->usedFullBuild());
        self::assertFalse($factory->hasLoadedVirtualFiles());
        self::assertSame(
            MemberDependencyGraphFactoryRebuildReason::CACHE_FAST_PATH_AVAILABLE,
            $factory->buildReport->rebuildPlan->reason,
        );
        self::assertNull($factory->buildReport->partialRebuildInput);
        self::assertSame(2, $factory->buildReport->scannedFileCount);
        self::assertSame(0, $factory->buildReport->loadedVirtualFileCount);
        self::assertSame(2, $factory->buildReport->virtualFileReferenceCount);
        self::assertCount(2, $factory->buildReport->cachePlan->freshFiles);
        self::assertCount(0, $factory->buildReport->rebuildPlan->filesToBuild);
        self::assertCount(2, $factory->buildReport->rebuildPlan->fragmentsToReuse);
        self::assertCount(0, $factory->virtualFiles);
        self::assertCount(0, $factory->loadedVirtualFiles());
        self::assertCount(2, $factory->virtualFileReferences);
        self::assertNotNull($factory->knownOwners->get('App\\A'));
        self::assertNotNull($factory->knownOwners->get('App\\B'));
        self::assertNotNull($factory->memberDependencyGraph->declarations->get($aRun));
        self::assertNotNull($factory->memberDependencyGraph->declarations->get($bSend));
        self::assertTrue($query->dependenciesOfMember($aRun)->contains(new MemberDependency(
            source: $aRun,
            target: $bSend,
            usageType: MemberUsageType::STATIC_METHOD_CALL,
            file: (realpath($aFilePath) ?: $aFilePath).'.virtual.0',
        )));
    }

    /**
     * Ensures changed-only cache plans can be reported as partial candidates while still executing full builds by default.
     */
    public function testBuildReportExposesPartialBuildCandidateWhenNoFragmentsCanBeReused(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $filePath = $srcDirectory.'/A.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class A
            {
                public function run(): void
                {
                }
            }
            PHP);

        MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );

        sleep(1);
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class A
            {
                public function changed(): void
                {
                }
            }
            PHP);

        $factory = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );

        self::assertSame(MemberDependencyGraphFactoryBuildMode::FULL_BUILD, $factory->buildReport->buildMode);
        self::assertSame(
            MemberDependencyGraphFactoryRebuildMode::PARTIAL_BUILD_CANDIDATE,
            $factory->buildReport->rebuildPlan->mode,
        );
        self::assertTrue($factory->usedFullBuild());
        self::assertFalse($factory->usedFastPath());
        self::assertTrue($factory->hasLoadedVirtualFiles());
        self::assertSame(
            MemberDependencyGraphFactoryRebuildReason::PARTIAL_REBUILD_CANDIDATE,
            $factory->buildReport->rebuildPlan->reason,
        );
        self::assertSame(1, $factory->buildReport->scannedFileCount);
        self::assertSame(1, $factory->buildReport->loadedVirtualFileCount);
        self::assertSame(1, $factory->buildReport->virtualFileReferenceCount);
        self::assertCount(1, $factory->buildReport->cachePlan->staleFiles);
        self::assertCount(1, $factory->buildReport->rebuildPlan->filesToBuild);
        self::assertCount(0, $factory->buildReport->rebuildPlan->fragmentsToReuse);
    }

    /**
     * Ensures partial rebuild candidates remain executed as full builds for now.
     */
    public function testBuildReportExposesPartialBuildCandidateAfterOneFileChanges(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
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
                public function run(): void
                {
                }
            }
            PHP);

        MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );

        sleep(1);
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

        $factory = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );

        self::assertSame(MemberDependencyGraphFactoryBuildMode::FULL_BUILD, $factory->buildReport->buildMode);
        self::assertSame(
            MemberDependencyGraphFactoryRebuildMode::PARTIAL_BUILD_CANDIDATE,
            $factory->buildReport->rebuildPlan->mode,
        );
        self::assertSame(
            MemberDependencyGraphFactoryRebuildReason::PARTIAL_REBUILD_CANDIDATE,
            $factory->buildReport->rebuildPlan->reason,
        );
        self::assertTrue($factory->usedFullBuild());
        self::assertFalse($factory->usedFastPath());
        self::assertNotNull($factory->buildReport->partialRebuildInput);
        self::assertNotNull($factory->buildReport->partialRebuildWorkingSet);
        self::assertCount(1, $factory->buildReport->rebuildPlan->filesToBuild);
        self::assertCount(1, $factory->buildReport->rebuildPlan->fragmentsToReuse);
        self::assertTrue($factory->buildReport->rebuildPlan->filesToBuild->contains(
            realpath($bFilePath) ?: $bFilePath,
        ));
        self::assertTrue($factory->buildReport->rebuildPlan->fragmentsToReuse->contains(
            realpath($aFilePath) ?: $aFilePath,
        ));
        self::assertTrue($factory->buildReport->partialRebuildWorkingSet->hasFileToParseForContext(
            realpath($bFilePath) ?: $bFilePath,
        ));
        self::assertTrue($factory->buildReport->partialRebuildWorkingSet->hasFileToRebuildGraph(
            realpath($bFilePath) ?: $bFilePath,
        ));
        self::assertCount(1, $factory->buildReport->partialRebuildWorkingSet->filesToParseForContext);
        self::assertCount(1, $factory->buildReport->partialRebuildWorkingSet->filesToRebuildGraph);
        self::assertCount(1, $factory->buildReport->partialRebuildWorkingSet->fragmentsToReuse);
    }

    /**
     * Ensures partial rebuild execution can be enabled explicitly.
     */
    public function testItUsesPartialBuildWhenOptionIsEnabled(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
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

        MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );

        sleep(1);
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

        $factory = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
            options: new MemberDependencyGraphFactoryOptions(enablePartialRebuild: true),
        );

        self::assertSame(MemberDependencyGraphFactoryBuildMode::PARTIAL_BUILD, $factory->buildReport->buildMode);
        self::assertTrue($factory->usedPartialBuild());
        self::assertFalse($factory->usedFullBuild());
        self::assertNotNull($factory->buildReport->partialRebuildInput);
        self::assertNotNull($factory->buildReport->partialRebuildWorkingSet);
        self::assertNotNull($factory->memberDependencyGraph->declarations->get(new MemberId(
            owner: 'App\\A',
            name: 'run',
            type: MemberType::METHOD,
        )));
        self::assertNotNull($factory->memberDependencyGraph->declarations->get(new MemberId(
            owner: 'App\\B',
            name: 'changed',
            type: MemberType::METHOD,
        )));
        self::assertNull($factory->memberDependencyGraph->declarations->get(new MemberId(
            owner: 'App\\B',
            name: 'old',
            type: MemberType::METHOD,
        )));
    }

    /**
     * Ensures opt-in partial factory builds match a fresh full factory build.
     */
    public function testPartialBuildMatchesFreshFullBuildFacts(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';
        $partialCacheFilePath = $this->workspace.'/partial-member-graph.cache';
        $fullCacheFilePath = $this->workspace.'/full-member-graph.cache';

        mkdir($srcDirectory, 0o777, true);
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

        MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $partialCacheFilePath,
        );

        sleep(1);
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

        $partialBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $partialCacheFilePath,
            options: new MemberDependencyGraphFactoryOptions(enablePartialRebuild: true),
        );
        $fullBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $fullCacheFilePath,
            clearCache: true,
        );

        self::assertTrue($partialBuild->usedPartialBuild());
        self::assertTrue($fullBuild->usedFullBuild());
        self::assertSame(
            $this->declarationHashes($fullBuild->memberDependencyGraph),
            $this->declarationHashes($partialBuild->memberDependencyGraph),
        );
        self::assertSame(
            $this->usageSignatures($fullBuild->memberDependencyGraph),
            $this->usageSignatures($partialBuild->memberDependencyGraph),
        );
        self::assertSame(
            $this->availableMemberSignatures($fullBuild->memberDependencyGraph),
            $this->availableMemberSignatures($partialBuild->memberDependencyGraph),
        );
    }

    /**
     * Ensures partial cache updates preserve rebuilt physical files that produce several virtual files.
     */
    public function testPartialBuildPersistsRebuiltVirtualFileReferences(): void
    {
        $srcDirectory = $this->workspace.'/src-partial-virtual-references';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';
        $cacheFilePath = $this->workspace.'/partial-virtual-references.cache';

        mkdir($srcDirectory, 0o777, true);
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

        MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );

        sleep(1);
        file_put_contents($bFilePath, <<<'PHP'
            <?php

            namespace App;

            final class B
            {
                public function changed(): void
                {
                }
            }

            final class C
            {
                public function added(): void
                {
                }
            }
            PHP);

        $partialBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
            options: new MemberDependencyGraphFactoryOptions(enablePartialRebuild: true),
        );
        $fastPathBuild = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
            options: new MemberDependencyGraphFactoryOptions(enablePartialRebuild: true),
        );

        self::assertTrue($partialBuild->usedPartialBuild());
        self::assertSame(2, $partialBuild->buildReport->loadedVirtualFileCount);
        self::assertSame(3, $partialBuild->buildReport->virtualFileReferenceCount);
        self::assertCount(2, $partialBuild->virtualFileReferences->getByFullFilePath(realpath($bFilePath) ?: $bFilePath));
        self::assertNotNull($partialBuild->memberDependencyGraph->declarations->get(new MemberId(
            owner: 'App\\C',
            name: 'added',
            type: MemberType::METHOD,
        )));
        self::assertTrue($fastPathBuild->usedFastPath());
        self::assertSame(3, $fastPathBuild->buildReport->virtualFileReferenceCount);
        self::assertNotNull($fastPathBuild->memberDependencyGraph->declarations->get(new MemberId(
            owner: 'App\\C',
            name: 'added',
            type: MemberType::METHOD,
        )));
    }

    /**
     * Returns the first class declaration found in an AST.
     *
     * @param array<Node> $nodes the AST nodes to inspect
     *
     * @throws \RuntimeException when no class declaration exists
     */
    private static function firstClass(array $nodes): Class_
    {
        foreach ($nodes as $node) {
            $class = self::firstClassInNode($node);

            if (null !== $class) {
                return $class;
            }
        }

        throw new \RuntimeException('Expected a class declaration in the fixture AST.');
    }

    /**
     * Returns the first class declaration found below one node.
     *
     * @param Node $node the node to inspect
     */
    private static function firstClassInNode(Node $node): ?Class_
    {
        if ($node instanceof Class_) {
            return $node;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node) {
                $class = self::firstClassInNode($subNode);

                if (null !== $class) {
                    return $class;
                }
            }

            if (!is_array($subNode)) {
                continue;
            }

            foreach ($subNode as $childNode) {
                if (!$childNode instanceof Node) {
                    continue;
                }

                $class = self::firstClassInNode($childNode);

                if (null !== $class) {
                    return $class;
                }
            }
        }

        return null;
    }

    /**
     * Returns the first class method declared by one class.
     *
     * @param Class_ $class the class to inspect
     *
     * @throws \RuntimeException when no method declaration exists
     */
    private static function firstClassMethod(Class_ $class): ClassMethod
    {
        foreach ($class->stmts as $statement) {
            if ($statement instanceof ClassMethod) {
                return $statement;
            }
        }

        throw new \RuntimeException('Expected a class method declaration in the fixture AST.');
    }

    /**
     * Returns the virtual file associated with one physical file path.
     *
     * @param MemberDependencyGraphBuild $build    the build to inspect
     * @param string                     $filePath the expected physical file path
     *
     * @throws \RuntimeException when no matching virtual file exists
     */
    private static function virtualFileForPhysicalFile(
        MemberDependencyGraphBuild $build,
        string $filePath,
    ): VirtualPhpSourceFile {
        $normalizedFilePath = realpath($filePath) ?: $filePath;

        foreach ($build->virtualFiles as $virtualFile) {
            if ($normalizedFilePath === $virtualFile->fullFilePath) {
                return $virtualFile;
            }
        }

        throw new \RuntimeException('Expected a virtual file for the provided physical file path.');
    }

    /**
     * Returns the virtual file containing one class short name.
     *
     * @param MemberDependencyGraphBuild $build          the build to inspect
     * @param string                     $classShortName the expected class short name
     *
     * @throws \RuntimeException when no matching virtual file exists
     */
    private static function virtualFileContainingClass(
        MemberDependencyGraphBuild $build,
        string $classShortName,
    ): VirtualPhpSourceFile {
        foreach ($build->virtualFiles as $virtualFile) {
            $class = self::firstClassOrNull($virtualFile->nodes);

            if ($classShortName === $class?->name?->toString()) {
                return $virtualFile;
            }
        }

        throw new \RuntimeException('Expected a virtual file containing the provided class.');
    }

    /**
     * Returns the first class declaration found in an AST, or null when none exists.
     *
     * @param array<Node> $nodes the AST nodes to inspect
     */
    private static function firstClassOrNull(array $nodes): ?Class_
    {
        foreach ($nodes as $node) {
            $class = self::firstClassInNode($node);

            if (null !== $class) {
                return $class;
            }
        }

        return null;
    }
}
