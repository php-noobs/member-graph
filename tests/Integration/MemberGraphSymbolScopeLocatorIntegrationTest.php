<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Tests\Integration;

use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphBuild;
use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphFactory;
use PhpNoobs\MemberGraph\Application\Source\Node\MemberGraphSymbolScopeLocator;
use PhpNoobs\PhpSource\VirtualPhpSourceFile;
use PhpParser\Node\Const_;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\Node\UseItem;
use PHPUnit\Framework\TestCase;

/**
 * Covers neutral symbol-scope lookup against real factory builds.
 */
final class MemberGraphSymbolScopeLocatorIntegrationTest extends TestCase
{
    private string $workspace;

    /**
     * Creates a temporary integration workspace.
     */
    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/member-graph-symbol-scope-locator-'.bin2hex(random_bytes(6));
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
     * Ensures method scope exposes same-owner method declarations.
     */
    public function testFactoryBuildLocatesMethodScopeFacts(): void
    {
        $build = $this->createBuildFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public function send(): void {}

                    public function queue(): void {}
                }
                PHP,
        ]);
        $scope = MemberGraphSymbolScopeLocator::fromBuild($build)->methodScope('App\\Mailer', 'send');

        self::assertCount(2, $scope->methodDeclarations());
        self::assertTrue($scope->methodDeclarations()->hasName('send'));
        self::assertTrue($scope->methodDeclarations()->hasName('queue'));
        self::assertInstanceOf(ClassMethod::class, $scope->methodDeclarations()->all()[0]->node);
    }

    /**
     * Ensures method scope exposes available parent, interface, trait, and alias method declarations.
     */
    public function testFactoryBuildLocatesMethodScopeFamilyFacts(): void
    {
        $build = $this->createBuildFromSources([
            'MethodFamily.php' => <<<'PHP'
                <?php

                namespace App;

                interface MailerContract
                {
                    public function contractSend(): void;
                }

                trait MailerTrait
                {
                    public function traitSend(): void {}
                }

                class BaseMailer
                {
                    public function parentSend(): void {}
                }

                final class Mailer extends BaseMailer implements MailerContract
                {
                    use MailerTrait {
                        traitSend as aliasedTraitSend;
                    }

                    public function send(): void {}

                    public function contractSend(): void {}
                }
                PHP,
        ]);
        $scope = MemberGraphSymbolScopeLocator::fromBuild($build)->methodScope('App\\Mailer', 'send');

        self::assertTrue($scope->methodDeclarations()->hasName('send'));
        self::assertTrue($scope->methodDeclarations()->hasName('contractSend'));
        self::assertTrue($scope->methodDeclarations()->hasName('parentSend'));
        self::assertTrue($scope->methodDeclarations()->hasName('traitSend'));
        self::assertTrue($scope->methodDeclarations()->hasName('aliasedTraitSend'));
    }

    /**
     * Ensures property scope exposes same-owner properties and promoted properties.
     */
    public function testFactoryBuildLocatesPropertyScopeFacts(): void
    {
        $build = $this->createBuildFromSources([
            'Mailer.php' => <<<'PHP'
                <?php

                namespace App;

                final class Mailer
                {
                    public string $transport;

                    public function __construct(private string $mailerName)
                    {
                    }
                }
                PHP,
        ]);
        $scope = MemberGraphSymbolScopeLocator::fromBuild($build)->propertyScope('App\\Mailer', 'transport');

        self::assertCount(2, $scope->propertyDeclarations());
        self::assertTrue($scope->propertyDeclarations()->hasName('transport'));
        self::assertTrue($scope->propertyDeclarations()->hasName('mailerName'));
        self::assertInstanceOf(PropertyProperty::class, $scope->propertyDeclarations()->all()[0]->node);
        self::assertInstanceOf(Param::class, $scope->propertyDeclarations()->all()[1]->node);
    }

    /**
     * Ensures property scope exposes available parent and trait property declarations.
     */
    public function testFactoryBuildLocatesPropertyScopeFamilyFacts(): void
    {
        $build = $this->createBuildFromSources([
            'PropertyFamily.php' => <<<'PHP'
                <?php

                namespace App;

                trait MailerProperties
                {
                    public string $traitTransport;
                }

                class BaseMailer
                {
                    public string $parentTransport;
                }

                final class Mailer extends BaseMailer
                {
                    use MailerProperties;

                    public string $transport;
                }
                PHP,
        ]);
        $scope = MemberGraphSymbolScopeLocator::fromBuild($build)->propertyScope('App\\Mailer', 'transport');

        self::assertTrue($scope->propertyDeclarations()->hasName('transport'));
        self::assertTrue($scope->propertyDeclarations()->hasName('parentTransport'));
        self::assertTrue($scope->propertyDeclarations()->hasName('traitTransport'));
    }

    /**
     * Ensures class-constant scope exposes constants and enum cases.
     */
    public function testFactoryBuildLocatesClassConstantAndEnumCaseScopeFacts(): void
    {
        $build = $this->createBuildFromSources([
            'Status.php' => <<<'PHP'
                <?php

                namespace App;

                enum Status
                {
                    public const DEFAULT = self::ACTIVE;

                    case ACTIVE;
                }
                PHP,
        ]);
        $scope = MemberGraphSymbolScopeLocator::fromBuild($build)->classConstantScope('App\\Status', 'ACTIVE');

        self::assertCount(1, $scope->classConstantDeclarations());
        self::assertTrue($scope->classConstantDeclarations()->hasName('DEFAULT'));
        self::assertInstanceOf(Const_::class, $scope->classConstantDeclarations()->all()[0]->node);
        self::assertCount(1, $scope->enumCaseDeclarations());
        self::assertTrue($scope->enumCaseDeclarations()->hasName('ACTIVE'));
        self::assertInstanceOf(EnumCase::class, $scope->enumCaseDeclarations()->all()[0]->node);
    }

    /**
     * Ensures class-constant scope exposes available parent and interface constants.
     */
    public function testFactoryBuildLocatesClassConstantScopeFamilyFacts(): void
    {
        $build = $this->createBuildFromSources([
            'ConstantFamily.php' => <<<'PHP'
                <?php

                namespace App;

                interface StatusContract
                {
                    public const INTERFACE_STATUS = 'interface';
                }

                class BaseStatus
                {
                    public const PARENT_STATUS = 'parent';
                }

                final class Status extends BaseStatus implements StatusContract
                {
                    public const ACTIVE = 'active';
                }
                PHP,
        ]);
        $scope = MemberGraphSymbolScopeLocator::fromBuild($build)->classConstantScope('App\\Status', 'ACTIVE');

        self::assertTrue($scope->classConstantDeclarations()->hasName('ACTIVE'));
        self::assertTrue($scope->classConstantDeclarations()->hasName('PARENT_STATUS'));
        self::assertTrue($scope->classConstantDeclarations()->hasName('INTERFACE_STATUS'));
    }

    /**
     * Ensures class-like namespace scope exposes declarations and short names.
     */
    public function testFactoryBuildLocatesClassLikeNamespaceScopeFacts(): void
    {
        $build = $this->createBuildFromSources([
            'Types.php' => <<<'PHP'
                <?php

                namespace App\Domain;

                class User {}

                interface UserContract {}

                trait UserTrait {}

                enum UserStatus {}
                PHP,
        ]);
        $scope = MemberGraphSymbolScopeLocator::fromBuild($build)->classLikeNamespaceScope('App\\Domain');

        self::assertCount(4, $scope->classLikeDeclarations());
        self::assertTrue($scope->classLikeDeclarations()->hasShortName('User'));
        self::assertTrue($scope->classLikeDeclarations()->hasShortName('UserContract'));
        self::assertTrue($scope->classLikeDeclarations()->hasShortName('UserTrait'));
        self::assertTrue($scope->classLikeDeclarations()->hasShortName('UserStatus'));
        self::assertInstanceOf(Class_::class, $scope->classLikeDeclarations()->all()[0]->node);
    }

    /**
     * Ensures function namespace scope exposes declarations and short names.
     */
    public function testFactoryBuildLocatesFunctionNamespaceScopeFacts(): void
    {
        $build = $this->createBuildFromSources([
            'functions.php' => <<<'PHP'
                <?php

                namespace App\Domain;

                function send_mail(): void {}

                function queue_mail(): void {}
                PHP,
        ]);
        $scope = MemberGraphSymbolScopeLocator::fromBuild($build)->functionNamespaceScope('App\\Domain');

        self::assertCount(2, $scope->functionDeclarations());
        self::assertTrue($scope->functionDeclarations()->hasShortName('send_mail'));
        self::assertTrue($scope->functionDeclarations()->hasShortName('queue_mail'));
        self::assertInstanceOf(Function_::class, $scope->functionDeclarations()->all()[0]->node);
    }

    /**
     * Ensures constant namespace scope exposes declarations and short names.
     */
    public function testFactoryBuildLocatesConstantNamespaceScopeFacts(): void
    {
        $build = $this->createBuildFromSources([
            'constants.php' => <<<'PHP'
                <?php

                namespace App\Domain;

                const ENABLED = true;
                const DISABLED = false;
                PHP,
        ]);
        $scope = MemberGraphSymbolScopeLocator::fromBuild($build)->constantNamespaceScope('App\\Domain');

        self::assertCount(2, $scope->constantDeclarations());
        self::assertTrue($scope->constantDeclarations()->hasShortName('ENABLED'));
        self::assertTrue($scope->constantDeclarations()->hasShortName('DISABLED'));
        self::assertInstanceOf(Const_::class, $scope->constantDeclarations()->all()[0]->node);
    }

    /**
     * Ensures file import scope exposes class-like, function, and constant imports with aliases.
     */
    public function testFactoryBuildLocatesFileImportScopeFacts(): void
    {
        $build = $this->createBuildFromSources([
            'Runner.php' => <<<'PHP'
                <?php

                namespace App;

                use App\Domain\User;
                use App\Service\{Mailer, Sender as MailSender};
                use function App\Util\{send_mail, queue_mail as queue_mail_alias};
                use const App\Config\{ENABLED as CONFIG_ENABLED};

                final class Runner {}
                PHP,
        ]);
        $virtualFile = $this->firstVirtualFile($build);
        $scope = MemberGraphSymbolScopeLocator::fromBuild($build)->fileImportScope($virtualFile);

        self::assertCount(3, $scope->classLikeImports());
        self::assertTrue($scope->classLikeImports()->hasAlias('User'));
        self::assertTrue($scope->classLikeImports()->hasAlias('Mailer'));
        self::assertTrue($scope->classLikeImports()->hasAlias('MailSender'));
        self::assertCount(2, $scope->functionImports());
        self::assertTrue($scope->functionImports()->hasAlias('send_mail'));
        self::assertTrue($scope->functionImports()->hasAlias('queue_mail_alias'));
        self::assertCount(1, $scope->constantImports());
        self::assertTrue($scope->constantImports()->hasAlias('CONFIG_ENABLED'));
        self::assertInstanceOf(UseItem::class, $scope->classLikeImports()->all()[0]->node);
    }

    /**
     * Builds a member graph from source fixtures.
     *
     * @param array<string, string> $sources the source code indexed by relative file name
     */
    private function createBuildFromSources(array $sources): MemberDependencyGraphBuild
    {
        $srcDirectory = $this->workspace.'/src';
        $cacheFilePath = $this->workspace.'/member-graph.cache';

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
     * Returns the first virtual file from one build.
     *
     * @param MemberDependencyGraphBuild $build the build result
     */
    private function firstVirtualFile(MemberDependencyGraphBuild $build): VirtualPhpSourceFile
    {
        $virtualFile = $build->virtualFiles->get(0);

        self::assertNotNull($virtualFile);

        return $virtualFile;
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

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
