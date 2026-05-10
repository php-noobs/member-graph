<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Tests\Unit;

use PhpNoobs\MemberGraph\Application\Impact\MemberImpactResolver;
use PhpNoobs\MemberGraph\Application\Impact\MemberImpactTarget;
use PhpNoobs\MemberGraph\Domain\Availability\AvailableMemberCollection;
use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclaration;
use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclarationCollection;
use PhpNoobs\MemberGraph\Domain\Graph\MemberDependencyGraph;
use PhpNoobs\MemberGraph\Domain\Graph\MemberId;
use PhpNoobs\MemberGraph\Domain\Graph\MemberType;
use PhpNoobs\MemberGraph\Domain\Index\Polymorphism\PolymorphicImplementationsIndex;
use PhpNoobs\MemberGraph\Domain\Owner\KnownOwnerCollection;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterId;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterUsage;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterUsageCollection;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterUsageType;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsage;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsageCollection;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsageType;
use PHPUnit\Framework\TestCase;

/**
 * Covers member graph impact queries.
 */
final class MemberImpactResolverTest extends TestCase
{
    /**
     * Ensures a method impact includes its declaration, direct usages, owners, and files.
     */
    public function testItResolvesMethodImpact(): void
    {
        $target = new MemberId('App\\Mailer', 'send', MemberType::METHOD);
        $graph = $this->createGraph(
            declarations: [
                new MemberDeclaration($target, 'src/Mailer.php'),
            ],
            memberUsages: [
                new MemberUsage(
                    sourceSymbol: 'App\\Runner::run',
                    target: $target,
                    type: MemberUsageType::METHOD_CALL,
                    file: 'src/Runner.php',
                ),
            ],
        );

        $impact = new MemberImpactResolver()->resolve(
            $graph,
            MemberImpactTarget::method('App\\Mailer', 'send'),
        );

        self::assertNotNull($impact->declarations->get($target));
        self::assertCount(1, $impact->memberUsages->getByTarget($target));
        self::assertTrue($impact->impactedOwners->contains('App\\Mailer'));
        self::assertTrue($impact->impactedOwners->contains('App\\Runner'));
        self::assertTrue($impact->impactedFiles->contains('src/Mailer.php'));
        self::assertTrue($impact->impactedFiles->contains('src/Runner.php'));
    }

    /**
     * Ensures property, class-constant, and function impact targets use their exact member identities.
     */
    public function testItResolvesPropertyClassConstantAndFunctionImpact(): void
    {
        $property = new MemberId('App\\Config', 'mailer', MemberType::PROPERTY);
        $constant = new MemberId('App\\Config', 'DEFAULT_MAILER', MemberType::CLASS_CONSTANT);
        $function = new MemberId('', 'App\\send_mail', MemberType::FUNCTION_);
        $graph = $this->createGraph(
            declarations: [
                new MemberDeclaration($property, 'src/Config.php'),
                new MemberDeclaration($constant, 'src/Config.php'),
                new MemberDeclaration($function, 'src/functions.php'),
            ],
            memberUsages: [
                new MemberUsage('App\\Runner::run', $property, MemberUsageType::PROPERTY_FETCH, 'src/Runner.php'),
                new MemberUsage('App\\Runner::run', $constant, MemberUsageType::CLASS_CONST_FETCH, 'src/Runner.php'),
                new MemberUsage('App\\Runner::run', $function, MemberUsageType::FUNCTION_CALL, 'src/Runner.php'),
            ],
        );
        $resolver = new MemberImpactResolver();

        $propertyImpact = $resolver->resolve($graph, MemberImpactTarget::property('App\\Config', 'mailer'));
        $constantImpact = $resolver->resolve($graph, MemberImpactTarget::classConstant('App\\Config', 'DEFAULT_MAILER'));
        $functionImpact = $resolver->resolve($graph, MemberImpactTarget::forFunction('App\\send_mail'));

        self::assertCount(1, $propertyImpact->memberUsages->getByTarget($property));
        self::assertCount(1, $constantImpact->memberUsages->getByTarget($constant));
        self::assertCount(1, $functionImpact->memberUsages->getByTarget($function));
        self::assertTrue($functionImpact->impactedFiles->contains('src/functions.php'));
        self::assertTrue($functionImpact->impactedFiles->contains('src/Runner.php'));
    }

    /**
     * Ensures a parameter impact includes named-argument usages and impacted usage locations.
     */
    public function testItResolvesParameterImpact(): void
    {
        $target = new ParameterId('App\\Mailer', 'send', 'message');
        $graph = $this->createGraph(
            parameterUsages: [
                new ParameterUsage(
                    sourceSymbol: 'App\\Runner::run',
                    target: $target,
                    type: ParameterUsageType::NAMED_ARGUMENT,
                    file: 'src/Runner.php',
                ),
            ],
        );

        $impact = new MemberImpactResolver()->resolve(
            $graph,
            MemberImpactTarget::parameter('App\\Mailer', 'send', 'message'),
        );

        self::assertCount(1, $impact->parameterUsages->getByTarget($target));
        self::assertTrue($impact->impactedOwners->contains('App\\Mailer'));
        self::assertTrue($impact->impactedOwners->contains('App\\Runner'));
        self::assertTrue($impact->impactedFiles->contains('src/Runner.php'));
    }

    /**
     * Ensures indexed parameter targets keep exact identity while still resolving name-scoped named arguments.
     */
    public function testItResolvesIndexedParameterImpactFromNameScopedUsages(): void
    {
        $nameScopedTarget = new ParameterId('App\\Mailer', 'send', 'message');
        $indexedTarget = new ParameterId('App\\Mailer', 'send', 'message', 1);
        $graph = $this->createGraph(
            parameterUsages: [
                new ParameterUsage(
                    sourceSymbol: 'App\\Runner::run',
                    target: $nameScopedTarget,
                    type: ParameterUsageType::NAMED_ARGUMENT,
                    file: 'src/Runner.php',
                ),
            ],
        );

        $impact = new MemberImpactResolver()->resolve(
            $graph,
            MemberImpactTarget::parameter('App\\Mailer', 'send', 'message', 1),
        );

        self::assertNotSame($nameScopedTarget->hash(), $indexedTarget->hash());
        self::assertSame($nameScopedTarget->hash(), $indexedTarget->nameHash());
        self::assertCount(1, $impact->parameterUsages->getByTarget($indexedTarget));
        self::assertTrue($impact->impactedFiles->contains('src/Runner.php'));
    }

    /**
     * Creates a member dependency graph for impact resolver tests.
     *
     * @param list<MemberDeclaration> $declarations    the declarations to add
     * @param list<MemberUsage>       $memberUsages    the member usages to add
     * @param list<ParameterUsage>    $parameterUsages the parameter usages to add
     */
    private function createGraph(
        array $declarations = [],
        array $memberUsages = [],
        array $parameterUsages = [],
    ): MemberDependencyGraph {
        $declarationCollection = new MemberDeclarationCollection();
        $memberUsageCollection = new MemberUsageCollection();
        $parameterUsageCollection = new ParameterUsageCollection();

        foreach ($declarations as $declaration) {
            $declarationCollection->add($declaration);
        }

        foreach ($memberUsages as $memberUsage) {
            $memberUsageCollection->add($memberUsage);
        }

        foreach ($parameterUsages as $parameterUsage) {
            $parameterUsageCollection->add($parameterUsage);
        }

        return new MemberDependencyGraph(
            declarations: $declarationCollection,
            usages: $memberUsageCollection,
            parameterUsages: $parameterUsageCollection,
            availableMembers: new AvailableMemberCollection(),
            knownOwners: new KnownOwnerCollection(),
            interfaceImplementationsIndex: new PolymorphicImplementationsIndex(),
            dependencyGraphIssues: null,
        );
    }
}
