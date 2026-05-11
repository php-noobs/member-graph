<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Tests\Integration;

use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphBuild;
use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphFactory;
use PhpNoobs\MemberGraph\Application\Build\Projection\MemberGraphBuildOverlay;
use PhpNoobs\MemberGraph\Application\Build\Projection\MemberGraphProjectedBuildFactory;
use PhpNoobs\MemberGraph\Application\Impact\MemberGraphImpactService;
use PhpNoobs\MemberGraph\Application\Source\Node\MemberGraphSourceNodeLocator;
use PhpNoobs\MemberGraph\Application\Source\Node\MemberGraphSymbolScopeLocator;
use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPUnit\Framework\TestCase;

/**
 * Covers projected build behavior against real factory builds.
 */
final class MemberGraphProjectedBuildFactoryIntegrationTest extends TestCase
{
    private string $workspace;

    /**
     * Creates a temporary integration workspace.
     */
    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/member-graph-projected-build-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0o777, true);
    }

    /**
     * Removes the temporary integration workspace.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    /**
     * Ensures owner identity updates are coherent across locator, scope, and impact APIs.
     */
    public function testItProjectsOwnerIdentityAcrossSourceScopeAndImpactApis(): void
    {
        $baseBuild = $this->createBaseBuild();
        $projectedBuild = MemberGraphProjectedBuildFactory::fromBuild(
            build: $baseBuild,
            overlay: MemberGraphBuildOverlay::empty()
                ->withOwnerUpdate('App\\Mailer', 'App\\Infrastructure\\Sender'),
        );
        $projectedMethod = new MemberId('App\\Infrastructure\\Sender', 'send', MemberType::METHOD);

        $locator = MemberGraphSourceNodeLocator::fromBuild($projectedBuild);
        $scopeLocator = MemberGraphSymbolScopeLocator::fromBuild($projectedBuild);
        $impactService = MemberGraphImpactService::fromBuild($projectedBuild);

        $ownerMatches = $locator->owner('App\\Infrastructure\\Sender');
        $methodMatches = $locator->method('App\\Infrastructure\\Sender', 'send');
        $methodScope = $scopeLocator->methodScope('App\\Infrastructure\\Sender', 'send');
        $methodImpact = $impactService->method('App\\Infrastructure\\Sender', 'send');

        self::assertNotNull($projectedBuild->knownOwners->get('App\\Infrastructure\\Sender'));
        self::assertNull($projectedBuild->knownOwners->get('App\\Mailer'));
        self::assertNotNull($projectedBuild->memberDependencyGraph->declarations->get($projectedMethod));
        self::assertNull($projectedBuild->memberDependencyGraph->declarations->get(new MemberId('App\\Mailer', 'send', MemberType::METHOD)));
        self::assertSame(1, count($ownerMatches->ownerDeclarations()->byNodeClass(Class_::class)));
        self::assertSame(1, count($ownerMatches->ownerUsages()->byNodeClass(Name::class)));
        self::assertSame(1, count($methodMatches->memberDeclarations()->byNodeClass(ClassMethod::class)));
        self::assertSame(1, count($methodMatches->memberUsages()->byNodeClass(MethodCall::class)));
        self::assertTrue($methodScope->methodDeclarations()->hasName('send'));
        self::assertNotNull($methodImpact->declarations->get($projectedMethod));
        self::assertCount(1, $methodImpact->usages->getByTarget($projectedMethod));
    }

    /**
     * Ensures member updates can be recorded with current projected owner identities.
     */
    public function testItProjectsChainedOwnerAndMethodIdentityUpdates(): void
    {
        $baseBuild = $this->createBaseBuild();
        $projectedBuild = MemberGraphProjectedBuildFactory::fromBuild(
            build: $baseBuild,
            overlay: MemberGraphBuildOverlay::empty()
                ->withOwnerUpdate('App\\Mailer', 'App\\Infrastructure\\Sender')
                ->withMethodUpdate('App\\Infrastructure\\Sender', 'send', 'deliver'),
        );
        $projectedMethod = new MemberId('App\\Infrastructure\\Sender', 'deliver', MemberType::METHOD);

        $locator = MemberGraphSourceNodeLocator::fromBuild($projectedBuild);
        $scopeLocator = MemberGraphSymbolScopeLocator::fromBuild($projectedBuild);
        $impactService = MemberGraphImpactService::fromBuild($projectedBuild);

        $methodMatches = $locator->method('App\\Infrastructure\\Sender', 'deliver');
        $methodScope = $scopeLocator->methodScope('App\\Infrastructure\\Sender', 'deliver');
        $methodImpact = $impactService->method('App\\Infrastructure\\Sender', 'deliver');

        self::assertNotNull($projectedBuild->memberDependencyGraph->declarations->get($projectedMethod));
        self::assertNull($projectedBuild->memberDependencyGraph->declarations->get(new MemberId('App\\Infrastructure\\Sender', 'send', MemberType::METHOD)));
        self::assertNull($projectedBuild->memberDependencyGraph->declarations->get(new MemberId('App\\Mailer', 'send', MemberType::METHOD)));
        self::assertSame(1, count($methodMatches->memberDeclarations()->byNodeClass(ClassMethod::class)));
        self::assertSame(1, count($methodMatches->memberUsages()->byNodeClass(MethodCall::class)));
        self::assertTrue($methodScope->methodDeclarations()->hasName('deliver'));
        self::assertFalse($methodScope->methodDeclarations()->hasName('send'));
        self::assertNotNull($methodImpact->declarations->get($projectedMethod));
        self::assertCount(1, $methodImpact->usages->getByTarget($projectedMethod));
    }

    /**
     * Creates the base member graph build used by projection tests.
     */
    private function createBaseBuild(): MemberDependencyGraphBuild
    {
        $srcDirectory = $this->workspace.'/src';

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($srcDirectory.'/Mailer.php', <<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                public function send(): void
                {
                }
            }
            PHP);
        file_put_contents($srcDirectory.'/Runner.php', <<<'PHP'
            <?php

            namespace App;

            final class Runner
            {
                public function run(Mailer $mailer): void
                {
                    $mailer->send();
                }
            }
            PHP);

        return MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $this->workspace.'/member-graph.cache',
        );
    }

    /**
     * Recursively removes one directory.
     *
     * @param string $directory the directory to remove
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                rmdir($file->getPathname());

                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
