<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Tests\Unit;

use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphBuild;
use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphFactory;
use PhpNoobs\MemberGraph\Application\Build\InMemoryRefresh\MemberGraphInMemoryRefreshWorkingSetResolver;
use PhpNoobs\PhpSource\VirtualPhpSourceFile;
use PhpNoobs\PhpSource\VirtualPhpSourceFileCollection;

/**
 * Covers in-memory refresh working-set resolution.
 */
final class MemberGraphInMemoryRefreshWorkingSetResolverTest extends MemberDependencyGraphFactoryTestCase
{
    /**
     * Ensures touched declaration files expand to direct impacted usage files.
     */
    public function testItExpandsTouchedFilesToDirectImpactedFiles(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';

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

        $build = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $this->workspace.'/member-graph.cache',
        );
        $workingSet = new MemberGraphInMemoryRefreshWorkingSetResolver()->resolve(
            previousBuild: $build,
            touchedVirtualFiles: new VirtualPhpSourceFileCollection()->add($this->virtualFileForPhysicalFile(
                build: $build,
                filePath: $bFilePath,
            )),
        );

        self::assertTrue($workingSet->hasFileToRebuildGraph(realpath($aFilePath) ?: $aFilePath));
        self::assertTrue($workingSet->hasFileToRebuildGraph(realpath($bFilePath) ?: $bFilePath));
        self::assertTrue($workingSet->hasFileToParseForContext(realpath($aFilePath) ?: $aFilePath));
        self::assertTrue($workingSet->hasFileToParseForContext(realpath($bFilePath) ?: $bFilePath));
        self::assertSame(2, $workingSet->iterations);
        self::assertCount(2, $workingSet->filesToRebuildGraph);
    }

    /**
     * Ensures impacted files are expanded transitively through their declarations.
     */
    public function testItExpandsTouchedFilesThroughTransitiveImpactedFiles(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';
        $cFilePath = $srcDirectory.'/C.php';

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($aFilePath, <<<'PHP'
            <?php

            namespace App;

            final class A
            {
                public function run(B $b): void
                {
                    $b->run(new C());
                }
            }
            PHP);
        file_put_contents($bFilePath, <<<'PHP'
            <?php

            namespace App;

            final class B
            {
                public function run(C $c): void
                {
                    $c->changed();
                }
            }
            PHP);
        file_put_contents($cFilePath, <<<'PHP'
            <?php

            namespace App;

            final class C
            {
                public function changed(): void
                {
                }
            }
            PHP);

        $build = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $this->workspace.'/member-graph.cache',
        );
        $workingSet = new MemberGraphInMemoryRefreshWorkingSetResolver()->resolve(
            previousBuild: $build,
            touchedVirtualFiles: new VirtualPhpSourceFileCollection()->add($this->virtualFileForPhysicalFile(
                build: $build,
                filePath: $cFilePath,
            )),
        );

        self::assertTrue($workingSet->hasFileToRebuildGraph(realpath($aFilePath) ?: $aFilePath));
        self::assertTrue($workingSet->hasFileToRebuildGraph(realpath($bFilePath) ?: $bFilePath));
        self::assertTrue($workingSet->hasFileToRebuildGraph(realpath($cFilePath) ?: $cFilePath));
        self::assertSame(3, $workingSet->iterations);
        self::assertCount(3, $workingSet->filesToRebuildGraph);
    }

    /**
     * Ensures isolated touched files stay limited to their own physical file.
     */
    public function testItKeepsIsolatedTouchedFilesLimitedToTheirPhysicalFile(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $aFilePath = $srcDirectory.'/A.php';
        $bFilePath = $srcDirectory.'/B.php';

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

        $build = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $this->workspace.'/member-graph.cache',
        );
        $workingSet = new MemberGraphInMemoryRefreshWorkingSetResolver()->resolve(
            previousBuild: $build,
            touchedVirtualFiles: new VirtualPhpSourceFileCollection()->add($this->virtualFileForPhysicalFile(
                build: $build,
                filePath: $bFilePath,
            )),
        );

        self::assertFalse($workingSet->hasFileToRebuildGraph(realpath($aFilePath) ?: $aFilePath));
        self::assertTrue($workingSet->hasFileToRebuildGraph(realpath($bFilePath) ?: $bFilePath));
        self::assertSame(1, $workingSet->iterations);
        self::assertCount(1, $workingSet->filesToRebuildGraph);
    }

    /**
     * Returns the virtual file associated with one physical file path.
     *
     * @param MemberDependencyGraphBuild $build    the build to inspect
     * @param string                     $filePath the expected physical file path
     *
     * @throws \RuntimeException when no matching virtual file exists
     */
    private function virtualFileForPhysicalFile(
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
}
