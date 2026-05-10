<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Tests\Integration;

use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphFactory;
use PhpNoobs\MemberGraph\Application\Source\Node\MemberGraphSourceNodeLocator;
use PhpNoobs\MemberGraph\Application\Source\Node\VirtualPhpSourceFileNodeMatchCollection;
use PhpNoobs\MemberGraph\Application\Source\Node\VirtualPhpSourceFileNodeMatchRole;
use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterId;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\PropertyProperty;
use PHPUnit\Framework\TestCase;

/**
 * Covers source-node lookup against real factory builds.
 */
final class MemberGraphSourceNodeLocatorIntegrationTest extends TestCase
{
    private string $workspace;

    /**
     * Creates a temporary integration workspace.
     */
    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/member-graph-source-node-locator-'.bin2hex(random_bytes(6));
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
     * Ensures real factory builds attach source-node identifiers used by strict source lookup.
     */
    public function testFactoryBuildSourceNodeIdsDriveStrictSourceNodeLookup(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $cacheFilePath = $this->workspace.'/member-graph.cache';
        $mailerFilePath = $srcDirectory.'/Mailer.php';
        $runnerFilePath = $srcDirectory.'/Runner.php';
        $send = new MemberId('App\\Mailer', 'send', MemberType::METHOD);
        $run = new MemberId('App\\Runner', 'run', MemberType::METHOD);
        $message = new ParameterId('App\\Mailer', 'send', 'message');

        mkdir($srcDirectory, 0o777, true);
        $this->writeMailerFile($mailerFilePath);
        $this->writeRunnerFile($runnerFilePath);

        $build = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $sendDeclaration = $build->memberDependencyGraph->declarations->get($send);
        $runDeclaration = $build->memberDependencyGraph->declarations->get($run);
        $sendUsages = $build->memberDependencyGraph->usages->getByTarget($send);
        $messageUsages = $build->memberDependencyGraph->parameterUsages->getByTarget($message);
        $locator = MemberGraphSourceNodeLocator::fromBuild($build);

        self::assertNotNull($sendDeclaration);
        self::assertNotNull($runDeclaration);
        self::assertNotNull($sendDeclaration->sourceNodeId);
        self::assertNotNull($runDeclaration->sourceNodeId);
        self::assertCount(1, $sendUsages);
        self::assertNotNull($sendUsages[0]->sourceNodeId);
        self::assertCount(1, $messageUsages);
        self::assertNotNull($messageUsages[0]->sourceNodeId);

        $methodMatches = $locator->method('App\\Mailer', 'send');
        $parameterMatches = $locator->parameter('App\\Mailer', 'send', 'message');

        self::assertCount(2, $methodMatches);
        self::assertSame(1, $this->countMatches($methodMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_DECLARATION, ClassMethod::class));
        self::assertSame(1, $this->countMatches($methodMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_USAGE, MethodCall::class));
        self::assertCount(2, $parameterMatches);
        self::assertSame(1, $this->countMatches($parameterMatches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_DECLARATION, Param::class));
        self::assertSame(1, $this->countMatches($parameterMatches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_USAGE, Arg::class));
    }

    /**
     * Ensures method parameter lookup keeps the existing name-only behavior when no index is provided.
     */
    public function testFactoryBuildLocatesMethodParameterByNameWithoutIndex(): void
    {
        $locator = $this->createLocatorFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $message): void
                    {
                    }
                }
                PHP,
        ]);

        $matches = $locator->parameter('App\\Mailer', 'send', 'message');

        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_DECLARATION, Param::class));
        self::assertSame(0, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_USAGE, Arg::class));
    }

    /**
     * Ensures function parameter lookup keeps the existing name-only behavior when no index is provided.
     */
    public function testFactoryBuildLocatesFunctionParameterByNameWithoutIndex(): void
    {
        $locator = $this->createLocatorFromSources([
            'functions.php' => <<<'PHP'
                <?php

                namespace App;

                function send_mail(string $message): void
                {
                }
                PHP,
        ]);

        $matches = $locator->parameter('', 'App\\send_mail', 'message');

        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_DECLARATION, Param::class));
        self::assertSame(0, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_USAGE, Arg::class));
    }

    /**
     * Ensures indexed method parameter lookup returns only the parameter matching both name and index.
     */
    public function testFactoryBuildLocatesMethodParameterByNameAndExactIndex(): void
    {
        $locator = $this->createLocatorFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $a, string $b): void
                    {
                    }
                }
                PHP,
        ]);

        $matches = $locator->parameter('App\\Mailer', 'send', 'b', 1);

        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_DECLARATION, Param::class));
        self::assertSame(1, $this->firstParameterDeclarationIndex($matches));
    }

    /**
     * Ensures indexed method parameter lookup rejects a matching name at the wrong index.
     */
    public function testFactoryBuildRejectsMethodParameterWhenNameMatchesButIndexDoesNot(): void
    {
        $locator = $this->createLocatorFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $a, string $b): void
                    {
                    }
                }
                PHP,
        ]);

        $matches = $locator->parameter('App\\Mailer', 'send', 'b', 0);

        self::assertSame(0, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_DECLARATION, Param::class));
    }

    /**
     * Ensures indexed method parameter lookup rejects a matching index with the wrong name.
     */
    public function testFactoryBuildRejectsMethodParameterWhenIndexMatchesButNameDoesNot(): void
    {
        $locator = $this->createLocatorFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $a, string $b): void
                    {
                    }
                }
                PHP,
        ]);

        $matches = $locator->parameter('App\\Mailer', 'send', 'a', 1);

        self::assertSame(0, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_DECLARATION, Param::class));
    }

    /**
     * Ensures indexed lookup can target the second parameter after a temporary duplicate-name swap state.
     */
    public function testFactoryBuildLocatesSecondParameterWhenNamesAreTemporarilyDuplicated(): void
    {
        $locator = $this->createLocatorFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $b, string $b): void
                    {
                    }
                }
                PHP,
        ]);

        $matches = $locator->parameterAt('App\\Mailer', 'send', 'b', 1);

        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_DECLARATION, Param::class));
        self::assertSame(1, $this->firstParameterDeclarationIndex($matches));
    }

    /**
     * Ensures indexed parameter lookup keeps named-argument usages when the graph links them to the target.
     */
    public function testFactoryBuildLocatesIndexedParameterDeclarationAndNamedArgumentUsage(): void
    {
        $locator = $this->createLocatorFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $a, string $b): void
                    {
                    }
                }
                PHP,
            'Runner.php' => <<<'PHP'
                <?php

                namespace App;

                final class Runner
                {
                    public function run(Mailer $mailer): void
                    {
                        $mailer->send(b: 'x');
                    }
                }
                PHP,
        ]);

        $matches = $locator->parameter('App\\Mailer', 'send', 'b', 1);

        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_DECLARATION, Param::class));
        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_USAGE, Arg::class));
        self::assertSame(1, $this->firstParameterDeclarationIndex($matches));

        foreach ($matches as $match) {
            self::assertSame(1, $match->target->parameterId?->parameterIndex);
        }
    }

    /**
     * Ensures parameter lookup exposes local variable usages inside the declaring method body.
     */
    public function testFactoryBuildLocatesMethodParameterLocalUsages(): void
    {
        $locator = $this->createLocatorFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $message): void
                    {
                        $normalized = trim($message);
                        $this->log($message);
                    }

                    private function log(string $message): void
                    {
                    }
                }
                PHP,
        ]);

        $matches = $locator->parameter('App\\Mailer', 'send', 'message');

        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_DECLARATION, Param::class));
        self::assertSame(2, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_LOCAL_USAGE, Variable::class));
        self::assertSame(0, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_USAGE, Arg::class));
        self::assertCount(2, $matches->parameterLocalUsages());
    }

    /**
     * Ensures parameter lookup exposes local variable usages inside the declaring function body.
     */
    public function testFactoryBuildLocatesFunctionParameterLocalUsages(): void
    {
        $locator = $this->createLocatorFromSources([
            'functions.php' => <<<'PHP'
                <?php

                namespace App;

                function send_mail(string $message): void
                {
                    $normalized = trim($message);
                    echo $message;
                }
                PHP,
        ]);

        $matches = $locator->parameter('', 'App\\send_mail', 'message');

        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_DECLARATION, Param::class));
        self::assertSame(2, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_LOCAL_USAGE, Variable::class));
        self::assertSame(0, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_USAGE, Arg::class));
    }

    /**
     * Ensures parameter local lookup respects nested closure and arrow-function shadowing.
     */
    public function testFactoryBuildLocatesParameterLocalUsagesWithoutShadowedNestedVariables(): void
    {
        $locator = $this->createLocatorFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $message): void
                    {
                        $outer = $message;
                        $shadowedClosure = static function (string $message): string {
                            return $message;
                        };
                        $capturedClosure = static function () use ($message): string {
                            return $message;
                        };
                        $capturedArrow = static fn (): string => $message;
                        $shadowedArrow = static fn (string $message): string => $message;
                    }
                }
                PHP,
        ]);

        $matches = $locator->parameter('App\\Mailer', 'send', 'message');

        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_DECLARATION, Param::class));
        self::assertSame(4, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::PARAMETER_LOCAL_USAGE, Variable::class));
    }

    /**
     * Ensures method parameter scope exposes same-signature parameters and assigned local variables.
     */
    public function testFactoryBuildLocatesMethodParameterScopeFacts(): void
    {
        $locator = $this->createLocatorFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $message, string $subject): void
                    {
                        $data = trim($message);
                        $normalized = strtoupper($subject);
                    }
                }
                PHP,
        ]);

        $scope = $locator->parameterScope('App\\Mailer', 'send', 'message', 0);

        self::assertCount(2, $scope->parameters());
        self::assertTrue($scope->parameters()->hasName('message'));
        self::assertTrue($scope->parameters()->hasName('subject'));
        self::assertCount(2, $scope->localVariables());
        self::assertTrue($scope->localVariables()->hasName('data'));
        self::assertTrue($scope->localVariables()->hasName('normalized'));
        self::assertCount(1, $scope->parameterLocalUsages());
    }

    /**
     * Ensures function parameter scope exposes same-signature parameters and assigned local variables.
     */
    public function testFactoryBuildLocatesFunctionParameterScopeFacts(): void
    {
        $locator = $this->createLocatorFromSources([
            'functions.php' => <<<'PHP'
                <?php

                namespace App;

                function send_mail(string $message, string $subject): void
                {
                    $data = trim($message);
                    $normalized = strtoupper($subject);
                }
                PHP,
        ]);

        $scope = $locator->parameterScope('', 'App\\send_mail', 'message');

        self::assertCount(2, $scope->parameters());
        self::assertTrue($scope->parameters()->hasName('message'));
        self::assertTrue($scope->parameters()->hasName('subject'));
        self::assertCount(2, $scope->localVariables());
        self::assertTrue($scope->localVariables()->hasName('data'));
        self::assertTrue($scope->localVariables()->hasName('normalized'));
        self::assertCount(1, $scope->parameterLocalUsages());
    }

    /**
     * Ensures indexed parameter scope is attached only to the exact matched declaration.
     */
    public function testFactoryBuildLocatesParameterScopeWithExactIndex(): void
    {
        $locator = $this->createLocatorFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $a, string $b): void
                    {
                        $data = $b;
                    }
                }
                PHP,
        ]);

        $exactScope = $locator->parameterScopeAt('App\\Mailer', 'send', 'b', 1);
        $wrongIndexScope = $locator->parameterScopeAt('App\\Mailer', 'send', 'b', 0);

        self::assertCount(2, $exactScope->parameters());
        self::assertTrue($exactScope->parameters()->hasName('a'));
        self::assertTrue($exactScope->parameters()->hasName('b'));
        self::assertCount(1, $exactScope->localVariables());
        self::assertTrue($exactScope->localVariables()->hasName('data'));
        self::assertCount(0, $wrongIndexScope->matches());
    }

    /**
     * Ensures parameter scope local variables ignore nested closure and arrow-function scopes.
     */
    public function testFactoryBuildLocatesParameterScopeWithoutNestedLocalVariables(): void
    {
        $locator = $this->createLocatorFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(string $message): void
                    {
                        $data = trim($message);
                        $closure = static function () use ($message): void {
                            $nestedData = $message;
                        };
                        $arrow = static fn (): string => $message;
                    }
                }
                PHP,
        ]);

        $scope = $locator->parameterScope('App\\Mailer', 'send', 'message');

        self::assertCount(1, $scope->parameters());
        self::assertCount(3, $scope->localVariables());
        self::assertTrue($scope->localVariables()->hasName('data'));
        self::assertTrue($scope->localVariables()->hasName('closure'));
        self::assertTrue($scope->localVariables()->hasName('arrow'));
        self::assertFalse($scope->localVariables()->hasName('nestedData'));
    }

    /**
     * Ensures real factory builds expose class-like owner declarations and usages.
     */
    public function testFactoryBuildSourceNodeIdsDriveStrictOwnerLookup(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $cacheFilePath = $this->workspace.'/member-graph.cache';
        $mailerFilePath = $srcDirectory.'/Mailer.php';
        $runnerFilePath = $srcDirectory.'/Runner.php';

        mkdir($srcDirectory, 0o777, true);
        $this->writeMailerFile($mailerFilePath);
        $this->writeOwnerUsageRunnerFile($runnerFilePath);

        $build = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $declaration = $build->memberDependencyGraph->ownerDeclarations->get('App\\Mailer');
        $usages = $build->memberDependencyGraph->ownerUsages->getByTarget('App\\Mailer');
        $locator = MemberGraphSourceNodeLocator::fromBuild($build);

        self::assertNotNull($declaration);
        self::assertNotNull($declaration->sourceNodeId);
        self::assertCount(4, $usages);
        self::assertNotNull($usages[0]->sourceNodeId);

        $matches = $locator->owner('App\\Mailer');

        self::assertCount(5, $matches);
        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::OWNER_DECLARATION, Class_::class));
        self::assertSame(4, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::OWNER_USAGE, Name::class));
        self::assertCount(1, $matches->ownerDeclarations());
        self::assertCount(4, $matches->ownerUsages());
        self::assertCount(2, $matches->virtualFiles());
    }

    /**
     * Ensures real factory builds drive strict property and class-constant source lookup.
     */
    public function testFactoryBuildSourceNodeIdsDriveStrictPropertyAndClassConstantLookup(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $cacheFilePath = $this->workspace.'/member-graph.cache';
        $mailerFilePath = $srcDirectory.'/Mailer.php';
        $runnerFilePath = $srcDirectory.'/Runner.php';

        mkdir($srcDirectory, 0o777, true);
        $this->writeInspectableMailerFile($mailerFilePath);
        $this->writeInspectableRunnerFile($runnerFilePath);

        $build = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $locator = MemberGraphSourceNodeLocator::fromBuild($build);

        $propertyMatches = $locator->property('App\\Mailer', 'transport');
        $classConstantMatches = $locator->classConstant('App\\Mailer', 'DEFAULT_TRANSPORT');

        self::assertCount(2, $propertyMatches);
        self::assertSame(1, $this->countMatches($propertyMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_DECLARATION, PropertyProperty::class));
        self::assertSame(1, $this->countMatches($propertyMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_USAGE, PropertyFetch::class));
        self::assertCount(2, $classConstantMatches);
        self::assertSame(1, $this->countMatches($classConstantMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_DECLARATION, Const_::class));
        self::assertSame(1, $this->countMatches($classConstantMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_USAGE, ClassConstFetch::class));
    }

    /**
     * Ensures real factory builds keep promoted properties as member declarations.
     */
    public function testFactoryBuildSourceNodeIdsDriveStrictPromotedPropertyLookup(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $cacheFilePath = $this->workspace.'/member-graph.cache';
        $mailerFilePath = $srcDirectory.'/Mailer.php';

        mkdir($srcDirectory, 0o777, true);
        $this->writePromotedPropertyMailerFile($mailerFilePath);

        $build = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $locator = MemberGraphSourceNodeLocator::fromBuild($build);

        $matches = $locator->property('App\\Mailer', 'transport');

        self::assertCount(2, $matches);
        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::MEMBER_DECLARATION, Param::class));
        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::MEMBER_USAGE, PropertyFetch::class));
        self::assertCount(1, $matches->memberDeclarations());
        self::assertCount(0, $matches->parameterDeclarations());
    }

    /**
     * Ensures real factory builds drive strict function, static-call, and nullsafe-call source lookup.
     */
    public function testFactoryBuildSourceNodeIdsDriveStrictFunctionStaticCallAndNullsafeCallLookup(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $cacheFilePath = $this->workspace.'/member-graph.cache';
        $mailerFilePath = $srcDirectory.'/Mailer.php';
        $functionsFilePath = $srcDirectory.'/functions.php';
        $runnerFilePath = $srcDirectory.'/Runner.php';

        mkdir($srcDirectory, 0o777, true);
        $this->writeCallableMailerFile($mailerFilePath);
        $this->writeFunctionsFile($functionsFilePath);
        $this->writeCallableRunnerFile($runnerFilePath);

        $build = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $locator = MemberGraphSourceNodeLocator::fromBuild($build);

        $functionMatches = $locator->function('App\\send_mail');
        $staticMethodMatches = $locator->method('App\\Mailer', 'sendStatic');
        $nullsafeMethodMatches = $locator->method('App\\Mailer', 'send');

        self::assertCount(2, $functionMatches);
        self::assertSame(1, $this->countMatches($functionMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_DECLARATION, Function_::class));
        self::assertSame(1, $this->countMatches($functionMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_USAGE, FuncCall::class));
        self::assertCount(2, $staticMethodMatches);
        self::assertSame(1, $this->countMatches($staticMethodMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_DECLARATION, ClassMethod::class));
        self::assertSame(1, $this->countMatches($staticMethodMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_USAGE, StaticCall::class));
        self::assertCount(2, $nullsafeMethodMatches);
        self::assertSame(1, $this->countMatches($nullsafeMethodMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_DECLARATION, ClassMethod::class));
        self::assertSame(1, $this->countMatches($nullsafeMethodMatches, VirtualPhpSourceFileNodeMatchRole::MEMBER_USAGE, NullsafeMethodCall::class));
    }

    /**
     * Ensures real factory builds locate enum-case declarations as class-constant members.
     */
    public function testFactoryBuildSourceNodeIdsDriveStrictEnumCaseLookup(): void
    {
        $srcDirectory = $this->workspace.'/src';
        $cacheFilePath = $this->workspace.'/member-graph.cache';
        $transportFilePath = $srcDirectory.'/Transport.php';
        $runnerFilePath = $srcDirectory.'/Runner.php';

        mkdir($srcDirectory, 0o777, true);
        $this->writeTransportEnumFile($transportFilePath);
        $this->writeEnumRunnerFile($runnerFilePath);

        $build = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $cacheFilePath,
        );
        $locator = MemberGraphSourceNodeLocator::fromBuild($build);

        $matches = $locator->classConstant('App\\Transport', 'SMTP');

        self::assertCount(2, $matches);
        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::MEMBER_DECLARATION, EnumCase::class));
        self::assertSame(1, $this->countMatches($matches, VirtualPhpSourceFileNodeMatchRole::MEMBER_USAGE, ClassConstFetch::class));
    }

    /**
     * Writes the mailer fixture.
     *
     * @param string $filePath the file path
     */
    private function writeMailerFile(string $filePath): void
    {
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                public function send(string $message): void
                {
                }
            }
            PHP);
    }

    /**
     * Writes the runner fixture.
     *
     * @param string $filePath the file path
     */
    private function writeRunnerFile(string $filePath): void
    {
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Runner
            {
                public function run(Mailer $mailer): void
                {
                    $mailer->send(message: 'hello');
                }
            }
            PHP);
    }

    /**
     * Writes a runner fixture using an owner through native class references.
     *
     * @param string $filePath the file path
     */
    private function writeOwnerUsageRunnerFile(string $filePath): void
    {
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Runner
            {
                public function run(Mailer $mailer): Mailer
                {
                    new Mailer();
                    Mailer::class;

                    return $mailer;
                }
            }
            PHP);
    }

    /**
     * Writes a mailer fixture exposing a normal property and class constant.
     *
     * @param string $filePath the file path
     */
    private function writeInspectableMailerFile(string $filePath): void
    {
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                public string $transport = 'smtp';

                public const DEFAULT_TRANSPORT = 'smtp';
            }
            PHP);
    }

    /**
     * Writes a runner fixture using a normal property and class constant.
     *
     * @param string $filePath the file path
     */
    private function writeInspectableRunnerFile(string $filePath): void
    {
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Runner
            {
                public function run(Mailer $mailer): string
                {
                    return $mailer->transport . Mailer::DEFAULT_TRANSPORT;
                }
            }
            PHP);
    }

    /**
     * Writes a mailer fixture exposing a promoted property used inside its owner.
     *
     * @param string $filePath the file path
     */
    private function writePromotedPropertyMailerFile(string $filePath): void
    {
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                public function __construct(private string $transport)
                {
                }

                public function transport(): string
                {
                    return $this->transport;
                }
            }
            PHP);
    }

    /**
     * Writes a mailer fixture exposing methods used through static and nullsafe calls.
     *
     * @param string $filePath the file path
     */
    private function writeCallableMailerFile(string $filePath): void
    {
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                public function send(string $message): void
                {
                }

                public static function sendStatic(string $message): void
                {
                }
            }
            PHP);
    }

    /**
     * Writes a function fixture.
     *
     * @param string $filePath the file path
     */
    private function writeFunctionsFile(string $filePath): void
    {
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            function send_mail(string $message): void
            {
            }
            PHP);
    }

    /**
     * Writes a runner fixture using function, static method, and nullsafe method calls.
     *
     * @param string $filePath the file path
     */
    private function writeCallableRunnerFile(string $filePath): void
    {
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Runner
            {
                public function run(?Mailer $mailer): void
                {
                    send_mail('hello');
                    Mailer::sendStatic('hello');
                    $mailer?->send('hello');
                }
            }
            PHP);
    }

    /**
     * Writes an enum fixture exposing enum cases as class-constant members.
     *
     * @param string $filePath the file path
     */
    private function writeTransportEnumFile(string $filePath): void
    {
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            enum Transport
            {
                case SMTP;
            }
            PHP);
    }

    /**
     * Writes a runner fixture using an enum case fetch.
     *
     * @param string $filePath the file path
     */
    private function writeEnumRunnerFile(string $filePath): void
    {
        file_put_contents($filePath, <<<'PHP'
            <?php

            namespace App;

            final class Runner
            {
                public function run(): Transport
                {
                    return Transport::SMTP;
                }
            }
            PHP);
    }

    /**
     * Creates a source-node locator from fixture sources.
     *
     * @param array<string, string> $sources the source code indexed by relative file name
     */
    private function createLocatorFromSources(array $sources): MemberGraphSourceNodeLocator
    {
        $srcDirectory = $this->workspace.'/src';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

        mkdir($srcDirectory, 0o777, true);

        foreach ($sources as $relativeFilePath => $source) {
            file_put_contents($srcDirectory.'/'.$relativeFilePath, $source);
        }

        return MemberGraphSourceNodeLocator::fromBuild(
            MemberDependencyGraphFactory::fromDirectory(
                directories: [$srcDirectory],
                cacheFilePath: $cacheFilePath,
            ),
        );
    }

    /**
     * Returns the index of the first parameter declaration match.
     *
     * @param VirtualPhpSourceFileNodeMatchCollection $matches the match collection
     */
    private function firstParameterDeclarationIndex(VirtualPhpSourceFileNodeMatchCollection $matches): ?int
    {
        foreach ($matches->parameterDeclarations() as $match) {
            if (!$match->node instanceof Param) {
                continue;
            }

            return $this->parameterDeclarationIndex($match->node);
        }

        return null;
    }

    /**
     * Resolves a parameter declaration index from its parent signature.
     *
     * @param Param $parameter the parameter node
     */
    private function parameterDeclarationIndex(Param $parameter): ?int
    {
        $parent = $parameter->getAttribute('parent');

        if (!$parent instanceof ClassMethod && !$parent instanceof Function_) {
            return null;
        }

        foreach ($parent->params as $index => $candidate) {
            if ($candidate === $parameter) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Counts matches by role and node class.
     *
     * @param VirtualPhpSourceFileNodeMatchCollection $matches   the match collection
     * @param VirtualPhpSourceFileNodeMatchRole       $role      the expected role
     * @param class-string<Node>                      $nodeClass the expected node class
     */
    private function countMatches(
        VirtualPhpSourceFileNodeMatchCollection $matches,
        VirtualPhpSourceFileNodeMatchRole $role,
        string $nodeClass,
    ): int {
        return $matches->byRole($role)->byNodeClass($nodeClass)->count();
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
