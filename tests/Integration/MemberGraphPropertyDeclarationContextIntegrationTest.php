<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Tests\Integration;

use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphFactory;
use PhpNoobs\MemberGraph\Application\Source\Node\MemberGraphSourceNodeLocator;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use PHPUnit\Framework\TestCase;

/**
 * Covers structural property declaration contexts for type-change callers.
 */
final class MemberGraphPropertyDeclarationContextIntegrationTest extends TestCase
{
    private string $workspace;

    /**
     * Creates a temporary integration workspace.
     */
    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/member-graph-property-context-'.bin2hex(random_bytes(6));
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
     * Ensures grouped property declaration contexts expose parent and sibling data.
     */
    public function testItLocatesGroupedPropertyDeclarationContext(): void
    {
        $locator = $this->createLocatorFromSource(<<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                /** @var non-empty-string */
                private string $transport, $backupTransport, $fallbackTransport;
            }
            PHP);

        $context = $locator->propertyDeclarationContext('App\\Mailer', ['transport', 'fallbackTransport']);
        $items = $context->items();
        $transport = $items->byPropertyName('transport')->first();
        $fallbackTransport = $items->byPropertyName('fallbackTransport')->first();

        self::assertFalse($context->hasDiagnostics());
        self::assertCount(2, $items);
        self::assertNotNull($transport);
        self::assertNotNull($fallbackTransport);
        self::assertInstanceOf(PropertyProperty::class, $transport->targetNode);
        self::assertInstanceOf(Property::class, $transport->parentProperty);
        self::assertInstanceOf(Class_::class, $transport->parentClassLike);
        self::assertSame(0, $transport->parentPropertyStatementIndex);
        self::assertCount(3, $transport->siblingProperties);
        self::assertFalse($transport->promoted);
        self::assertSame($transport->parentProperty, $transport->phpDocOwner);
        self::assertFalse($transport->allSiblingsTargeted);
        self::assertSame($transport->parentProperty, $fallbackTransport->parentProperty);
    }

    /**
     * Ensures grouped property context marks complete sibling coverage.
     */
    public function testItMarksAllGroupedPropertySiblingsAsTargeted(): void
    {
        $locator = $this->createLocatorFromSource(<<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                private string $transport, $backupTransport;
            }
            PHP);

        $context = $locator->propertyDeclarationContext('App\\Mailer', ['transport', 'backupTransport']);
        $transport = $context->items()->byPropertyName('transport')->first();

        self::assertFalse($context->hasDiagnostics());
        self::assertNotNull($transport);
        self::assertTrue($transport->allSiblingsTargeted);
    }

    /**
     * Ensures promoted property declaration contexts expose constructor parameter data.
     */
    public function testItLocatesPromotedPropertyDeclarationContext(): void
    {
        $locator = $this->createLocatorFromSource(<<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                public function __construct(
                    public string $transport,
                ) {
                }
            }
            PHP);

        $context = $locator->propertyDeclarationContext('App\\Mailer', 'transport');
        $transport = $context->items()->first();

        self::assertFalse($context->hasDiagnostics());
        self::assertNotNull($transport);
        self::assertInstanceOf(Param::class, $transport->targetNode);
        self::assertInstanceOf(Class_::class, $transport->parentClassLike);
        self::assertNull($transport->parentProperty);
        self::assertNull($transport->parentPropertyStatementIndex);
        self::assertSame([], $transport->siblingProperties);
        self::assertTrue($transport->promoted);
        self::assertSame($transport->targetNode, $transport->phpDocOwner);
        self::assertTrue($transport->allSiblingsTargeted);
    }

    /**
     * Ensures diagnostics are emitted when requested properties span several declarations.
     */
    public function testItReportsPropertiesSplitAcrossDeclarations(): void
    {
        $locator = $this->createLocatorFromSource(<<<'PHP'
            <?php

            namespace App;

            final class Mailer
            {
                private string $transport;
                private string $backupTransport;
            }
            PHP);

        $context = $locator->propertyDeclarationContext('App\\Mailer', ['transport', 'backupTransport']);

        self::assertTrue($context->hasDiagnostics());
        self::assertSame('PROPERTIES_SPLIT_ACROSS_DECLARATIONS', $context->diagnostics()->all()[0]->code);
    }

    /**
     * Creates a source node locator from one source fixture.
     *
     * @param string $source the PHP source code
     */
    private function createLocatorFromSource(string $source): MemberGraphSourceNodeLocator
    {
        $srcDirectory = $this->workspace.'/src';

        mkdir($srcDirectory, 0o777, true);
        file_put_contents($srcDirectory.'/Mailer.php', $source);

        $build = MemberDependencyGraphFactory::fromDirectory(
            directories: [$srcDirectory],
            cacheFilePath: $this->workspace.'/member-graph.cache',
        );

        return MemberGraphSourceNodeLocator::fromBuild($build);
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
