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
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterId;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\PropertyProperty;
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
     * Ensures property identity updates are coherent across locator, scope, and impact APIs.
     */
    public function testItProjectsPropertyIdentityUpdates(): void
    {
        $baseBuild = $this->createBuildFromSources([
            'PropertyFixture.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public string $transport;

                    public function run(): string
                    {
                        return $this->transport;
                    }
                }
                PHP,
        ]);
        $projectedBuild = MemberGraphProjectedBuildFactory::fromBuild(
            build: $baseBuild,
            overlay: MemberGraphBuildOverlay::empty()
                ->withOwnerUpdate('App\\Mailer', 'App\\Infrastructure\\Sender')
                ->withPropertyUpdate('App\\Infrastructure\\Sender', 'transport', 'mailerTransport'),
        );
        $projectedProperty = new MemberId('App\\Infrastructure\\Sender', 'mailerTransport', MemberType::PROPERTY);
        $locator = MemberGraphSourceNodeLocator::fromBuild($projectedBuild);
        $scopeLocator = MemberGraphSymbolScopeLocator::fromBuild($projectedBuild);
        $impactService = MemberGraphImpactService::fromBuild($projectedBuild);

        $propertyMatches = $locator->property('App\\Infrastructure\\Sender', 'mailerTransport');
        $propertyScope = $scopeLocator->propertyScope('App\\Infrastructure\\Sender', 'mailerTransport');
        $propertyImpact = $impactService->property('App\\Infrastructure\\Sender', 'mailerTransport');

        self::assertNotNull($projectedBuild->memberDependencyGraph->declarations->get($projectedProperty));
        self::assertNull($projectedBuild->memberDependencyGraph->declarations->get(new MemberId('App\\Mailer', 'transport', MemberType::PROPERTY)));
        self::assertSame(1, count($propertyMatches->memberDeclarations()->byNodeClass(PropertyProperty::class)));
        self::assertSame(1, count($propertyMatches->memberUsages()->byNodeClass(PropertyFetch::class)));
        self::assertTrue($propertyScope->propertyDeclarations()->hasName('mailerTransport'));
        self::assertFalse($propertyScope->propertyDeclarations()->hasName('transport'));
        self::assertNotNull($propertyImpact->declarations->get($projectedProperty));
        self::assertCount(1, $propertyImpact->usages->getByTarget($projectedProperty));
    }

    /**
     * Ensures class-constant identity updates are coherent across locator, scope, and impact APIs.
     */
    public function testItProjectsClassConstantIdentityUpdates(): void
    {
        $baseBuild = $this->createBuildFromSources([
            'ConstantFixture.php' => <<<'PHP'
                <?php

                namespace App;

                final class Status
                {
                    public const ACTIVE = 'active';

                    public function value(): string
                    {
                        return self::ACTIVE;
                    }
                }
                PHP,
        ]);
        $projectedBuild = MemberGraphProjectedBuildFactory::fromBuild(
            build: $baseBuild,
            overlay: MemberGraphBuildOverlay::empty()
                ->withClassConstantUpdate('App\\Status', 'ACTIVE', 'ENABLED'),
        );
        $projectedConstant = new MemberId('App\\Status', 'ENABLED', MemberType::CLASS_CONSTANT);
        $locator = MemberGraphSourceNodeLocator::fromBuild($projectedBuild);
        $scopeLocator = MemberGraphSymbolScopeLocator::fromBuild($projectedBuild);
        $impactService = MemberGraphImpactService::fromBuild($projectedBuild);

        $constantMatches = $locator->classConstant('App\\Status', 'ENABLED');
        $constantScope = $scopeLocator->classConstantScope('App\\Status', 'ENABLED');
        $constantImpact = $impactService->classConstant('App\\Status', 'ENABLED');

        self::assertNotNull($projectedBuild->memberDependencyGraph->declarations->get($projectedConstant));
        self::assertNull($projectedBuild->memberDependencyGraph->declarations->get(new MemberId('App\\Status', 'ACTIVE', MemberType::CLASS_CONSTANT)));
        self::assertSame(1, count($constantMatches->memberDeclarations()->byNodeClass(Const_::class)));
        self::assertSame(1, count($constantMatches->memberUsages()->byNodeClass(ClassConstFetch::class)));
        self::assertTrue($constantScope->classConstantDeclarations()->hasName('ENABLED'));
        self::assertFalse($constantScope->classConstantDeclarations()->hasName('ACTIVE'));
        self::assertNotNull($constantImpact->declarations->get($projectedConstant));
        self::assertCount(1, $constantImpact->usages->getByTarget($projectedConstant));
    }

    /**
     * Ensures enum-case identity updates are projected as class-constant member identities.
     */
    public function testItProjectsEnumCaseIdentityUpdates(): void
    {
        $baseBuild = $this->createBuildFromSources([
            'EnumFixture.php' => <<<'PHP'
                <?php

                namespace App;

                enum Status
                {
                    case ACTIVE;

                    public function selected(): self
                    {
                        return self::ACTIVE;
                    }
                }
                PHP,
        ]);
        $projectedBuild = MemberGraphProjectedBuildFactory::fromBuild(
            build: $baseBuild,
            overlay: MemberGraphBuildOverlay::empty()
                ->withEnumCaseUpdate('App\\Status', 'ACTIVE', 'ENABLED'),
        );
        $projectedCase = new MemberId('App\\Status', 'ENABLED', MemberType::CLASS_CONSTANT);
        $locator = MemberGraphSourceNodeLocator::fromBuild($projectedBuild);
        $scopeLocator = MemberGraphSymbolScopeLocator::fromBuild($projectedBuild);
        $impactService = MemberGraphImpactService::fromBuild($projectedBuild);

        $caseMatches = $locator->classConstant('App\\Status', 'ENABLED');
        $caseScope = $scopeLocator->classConstantScope('App\\Status', 'ENABLED');
        $caseImpact = $impactService->classConstant('App\\Status', 'ENABLED');

        self::assertNotNull($projectedBuild->memberDependencyGraph->declarations->get($projectedCase));
        self::assertSame(1, count($caseMatches->memberDeclarations()->byNodeClass(EnumCase::class)));
        self::assertSame(1, count($caseMatches->memberUsages()->byNodeClass(ClassConstFetch::class)));
        self::assertTrue($caseScope->enumCaseDeclarations()->hasName('ENABLED'));
        self::assertFalse($caseScope->enumCaseDeclarations()->hasName('ACTIVE'));
        self::assertNotNull($caseImpact->declarations->get($projectedCase));
        self::assertCount(1, $caseImpact->usages->getByTarget($projectedCase));
    }

    /**
     * Ensures function identity updates are coherent across locator and impact APIs.
     */
    public function testItProjectsFunctionIdentityUpdates(): void
    {
        $baseBuild = $this->createBuildFromSources([
            'FunctionFixture.php' => <<<'PHP'
                <?php

                namespace App;

                function send_mail(): void
                {
                }

                final class Runner
                {
                    public function run(): void
                    {
                        send_mail();
                    }
                }
                PHP,
        ]);
        $projectedBuild = MemberGraphProjectedBuildFactory::fromBuild(
            build: $baseBuild,
            overlay: MemberGraphBuildOverlay::empty()
                ->withFunctionUpdate('App\\send_mail', 'App\\deliver_mail'),
        );
        $projectedFunction = new MemberId('', 'App\\deliver_mail', MemberType::FUNCTION_);
        $locator = MemberGraphSourceNodeLocator::fromBuild($projectedBuild);
        $impactService = MemberGraphImpactService::fromBuild($projectedBuild);

        $functionMatches = $locator->function('App\\deliver_mail');
        $functionImpact = $impactService->function('App\\deliver_mail');

        self::assertNotNull($projectedBuild->memberDependencyGraph->declarations->get($projectedFunction));
        self::assertNull($projectedBuild->memberDependencyGraph->declarations->get(new MemberId('', 'App\\send_mail', MemberType::FUNCTION_)));
        self::assertSame(1, count($functionMatches->memberDeclarations()->byNodeClass(Function_::class)));
        self::assertSame(1, count($functionMatches->memberUsages()->byNodeClass(FuncCall::class)));
        self::assertNotNull($functionImpact->declarations->get($projectedFunction));
        self::assertCount(1, $functionImpact->usages->getByTarget($projectedFunction));
    }

    /**
     * Ensures namespace-level constant identity updates are coherent across locator and impact APIs.
     */
    public function testItProjectsNamespaceConstantIdentityUpdates(): void
    {
        $baseBuild = $this->createBuildFromSources([
            'NamespaceConstantFixture.php' => <<<'PHP'
                <?php

                namespace App\Config;

                const ENABLED = true;

                final class Reader
                {
                    public function read(): bool
                    {
                        return ENABLED;
                    }
                }
                PHP,
        ]);
        $projectedBuild = MemberGraphProjectedBuildFactory::fromBuild(
            build: $baseBuild,
            overlay: MemberGraphBuildOverlay::empty()
                ->withNamespaceConstantUpdate('App\\Config\\ENABLED', 'App\\Config\\ACTIVE'),
        );
        $projectedConstant = new MemberId('', 'App\\Config\\ACTIVE', MemberType::CONSTANT);
        $locator = MemberGraphSourceNodeLocator::fromBuild($projectedBuild);
        $impactService = MemberGraphImpactService::fromBuild($projectedBuild);

        $constantMatches = $locator->constant('App\\Config\\ACTIVE');
        $constantImpact = $impactService->constant('App\\Config\\ACTIVE');

        self::assertNotNull($projectedBuild->memberDependencyGraph->declarations->get($projectedConstant));
        self::assertNull($projectedBuild->memberDependencyGraph->declarations->get(new MemberId('', 'App\\Config\\ENABLED', MemberType::CONSTANT)));
        self::assertSame(1, count($constantMatches->memberDeclarations()->byNodeClass(Const_::class)));
        self::assertSame(1, count($constantMatches->memberUsages()->byNodeClass(ConstFetch::class)));
        self::assertNotNull($constantImpact->declarations->get($projectedConstant));
        self::assertCount(1, $constantImpact->usages->getByTarget($projectedConstant));
    }

    /**
     * Ensures parameter identity updates can use current projected owner and method identities.
     */
    public function testItProjectsParameterIdentityUpdatesWithCurrentFunctionLikeIdentity(): void
    {
        $baseBuild = $this->createBuildFromSources([
            'ParameterFixture.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $message, string $copy): void
                    {
                    }
                }

                final class Runner
                {
                    public function run(Mailer $mailer): void
                    {
                        $mailer->send(message: 'hello');
                    }
                }
                PHP,
        ]);
        foreach ($baseBuild->virtualFiles as $virtualFile) {
            $this->updateProjectedTransactionSourceNames($virtualFile->nodes);
            $virtualFile->update($virtualFile->nodes);
        }

        $projectedBuild = MemberGraphProjectedBuildFactory::fromBuild(
            build: $baseBuild,
            overlay: MemberGraphBuildOverlay::empty()
                ->withOwnerUpdate('App\\Mailer', 'App\\Infrastructure\\Sender')
                ->withMethodUpdate('App\\Infrastructure\\Sender', 'send', 'deliver')
                ->withParameterUpdate('App\\Infrastructure\\Sender', 'deliver', 'message', 'emailMessage', 0),
        );
        $projectedParameter = new ParameterId('App\\Infrastructure\\Sender', 'deliver', 'emailMessage', 0);
        $locator = MemberGraphSourceNodeLocator::fromBuild($projectedBuild);
        $impactService = MemberGraphImpactService::fromBuild($projectedBuild);

        $parameterMatches = $locator->parameter('App\\Infrastructure\\Sender', 'deliver', 'emailMessage', 0);
        $parameterImpact = $impactService->parameter('App\\Infrastructure\\Sender', 'deliver', 'emailMessage', 0);

        self::assertSame(1, count($parameterMatches->parameterDeclarations()->byNodeClass(Param::class)));
        self::assertSame(1, count($parameterMatches->parameterUsages()->byNodeClass(Arg::class)));
        self::assertCount(1, $parameterImpact->parameterUsages->getByTarget($projectedParameter));
        self::assertCount(0, $projectedBuild->memberDependencyGraph->parameterUsages->getByTarget(new ParameterId('App\\Mailer', 'send', 'message', 0)));
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
     * Builds a member graph from source fixtures.
     *
     * @param array<string, string> $sources the source code indexed by relative file name
     */
    private function createBuildFromSources(array $sources): MemberDependencyGraphBuild
    {
        $srcDirectory = $this->workspace.'/src-'.bin2hex(random_bytes(4));
        $cacheFilePath = $this->workspace.'/member-graph-'.bin2hex(random_bytes(4)).'.cache';

        mkdir($srcDirectory, 0o777, true);

        foreach ($sources as $relativeFilePath => $source) {
            file_put_contents($srcDirectory.'/'.$relativeFilePath, $source);
        }

        return MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
    }

    /**
     * Updates transaction-related source names in fixture AST nodes.
     *
     * @param array<Node> $nodes the nodes to update
     */
    private function updateProjectedTransactionSourceNames(array $nodes): void
    {
        foreach ($nodes as $node) {
            $this->updateProjectedTransactionSourceNamesInNode($node);
        }
    }

    /**
     * Updates transaction-related source names in one fixture node.
     *
     * @param Node $node the node to update
     */
    private function updateProjectedTransactionSourceNamesInNode(Node $node): void
    {
        if ($node instanceof Namespace_ && null !== $node->name && 'App' === $node->name->toString()) {
            $node->name = new Name('App\\Infrastructure');
        }

        if ($node instanceof Class_ && null !== $node->name && 'Mailer' === $node->name->toString()) {
            $node->name = new Identifier('Sender');
        }

        if ($node instanceof ClassMethod && 'send' === $node->name->toString()) {
            $node->name = new Identifier('deliver');
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier && 'send' === $node->name->toString()) {
            $node->name = new Identifier('deliver');
        }

        if (
            $node instanceof Param
            && $node->var instanceof Variable
            && 'message' === $node->var->name
        ) {
            $node->var->name = 'emailMessage';
        }

        if ($node instanceof Arg && $node->name instanceof Identifier && 'message' === $node->name->toString()) {
            $node->name = new Identifier('emailMessage');
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node) {
                $this->updateProjectedTransactionSourceNamesInNode($subNode);

                continue;
            }

            if (!is_array($subNode)) {
                continue;
            }

            foreach ($subNode as $childNode) {
                if ($childNode instanceof Node) {
                    $this->updateProjectedTransactionSourceNamesInNode($childNode);
                }
            }
        }
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
