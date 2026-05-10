<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Impact;

use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphBuild;
use PhpNoobs\MemberGraph\Application\Query\MemberGraphQueryService;
use PhpNoobs\MemberGraph\Application\Query\MemberGraphSourceQueryService;
use PhpNoobs\MemberGraph\Domain\Availability\AvailableMemberCollection;
use PhpNoobs\MemberGraph\Domain\Declaration\MemberDeclarationCollection;
use PhpNoobs\MemberGraph\Domain\Graph\MemberDependencyGraph;
use PhpNoobs\MemberGraph\Domain\Owner\KnownOwnerCollection;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerDeclarationCollection;
use PhpNoobs\MemberGraph\Domain\Owner\OwnerUsageCollection;
use PhpNoobs\MemberGraph\Domain\Parameter\ParameterUsageCollection;
use PhpNoobs\MemberGraph\Domain\Usage\MemberUsageCollection;
use PhpNoobs\PhpSource\VirtualPhpSourceFileCollection;

/**
 * Provides refactoring-oriented impact projections over a member dependency graph.
 */
final readonly class MemberGraphImpactService
{
    /**
     * Constructor.
     *
     * @param MemberGraphQueryService       $graphQuery  the graph query service
     * @param MemberGraphSourceQueryService $sourceQuery the source-aware query service
     */
    public function __construct(
        private MemberGraphQueryService $graphQuery,
        private MemberGraphSourceQueryService $sourceQuery,
    ) {
    }

    /**
     * Creates an impact service from a factory build result.
     *
     * @param MemberDependencyGraphBuild $build the member dependency graph build result
     */
    public static function fromBuild(MemberDependencyGraphBuild $build): self
    {
        return self::fromGraphAndVirtualFiles(
            graph: $build->memberDependencyGraph,
            virtualFiles: $build->virtualFiles,
        );
    }

    /**
     * Creates an impact service from a graph and its virtual files.
     *
     * @param MemberDependencyGraph          $graph        the member dependency graph
     * @param VirtualPhpSourceFileCollection $virtualFiles the virtual files to index
     */
    public static function fromGraphAndVirtualFiles(
        MemberDependencyGraph $graph,
        VirtualPhpSourceFileCollection $virtualFiles,
    ): self {
        $graphQuery = MemberGraphQueryService::fromGraph($graph);

        return self::fromQueryAndVirtualFiles($graphQuery, $virtualFiles);
    }

    /**
     * Creates an impact service from an existing graph query service and virtual files.
     *
     * @param MemberGraphQueryService        $graphQuery   the graph query service
     * @param VirtualPhpSourceFileCollection $virtualFiles the virtual files to index
     */
    public static function fromQueryAndVirtualFiles(
        MemberGraphQueryService $graphQuery,
        VirtualPhpSourceFileCollection $virtualFiles,
    ): self {
        return new self(
            graphQuery: $graphQuery,
            sourceQuery: MemberGraphSourceQueryService::fromQueryAndVirtualFiles($graphQuery, $virtualFiles),
        );
    }

    /**
     * Resolves impact information for one method.
     *
     * @param string $owner the method owner FQCN
     * @param string $name  the method name
     */
    public function method(string $owner, string $name): MemberGraphImpact
    {
        return $this->target(MemberImpactTarget::method($owner, $name));
    }

    /**
     * Resolves impact information for one class-like owner.
     *
     * @param string $owner the class-like owner FQCN
     */
    public function owner(string $owner): MemberGraphImpact
    {
        return $this->target(MemberImpactTarget::owner($owner));
    }

    /**
     * Resolves impact information for one property.
     *
     * @param string $owner the property owner FQCN
     * @param string $name  the property name
     */
    public function property(string $owner, string $name): MemberGraphImpact
    {
        return $this->target(MemberImpactTarget::property($owner, $name));
    }

    /**
     * Resolves impact information for one class constant.
     *
     * @param string $owner the class-constant owner FQCN
     * @param string $name  the class-constant name
     */
    public function classConstant(string $owner, string $name): MemberGraphImpact
    {
        return $this->target(MemberImpactTarget::classConstant($owner, $name));
    }

    /**
     * Resolves impact information for one function.
     *
     * @param string $name the fully-qualified function name
     */
    public function function(string $name): MemberGraphImpact
    {
        return $this->target(MemberImpactTarget::forFunction($name));
    }

    /**
     * Resolves impact information for one parameter.
     *
     * @param string   $owner            the owner FQCN, or an empty string for functions
     * @param string   $functionLikeName the method name or fully-qualified function name
     * @param string   $parameterName    the parameter name without "$"
     * @param int|null $parameterIndex   the optional zero-based declaration index
     */
    public function parameter(
        string $owner,
        string $functionLikeName,
        string $parameterName,
        ?int $parameterIndex = null,
    ): MemberGraphImpact {
        return $this->target(MemberImpactTarget::parameter($owner, $functionLikeName, $parameterName, $parameterIndex));
    }

    /**
     * Resolves rich impact information for one target.
     *
     * @param MemberImpactTarget $target the impact target
     */
    public function target(MemberImpactTarget $target): MemberGraphImpact
    {
        $memberImpact = $this->graphQuery->impactOf($target);
        $graphFiles = $memberImpact->impactedFiles;
        $virtualFiles = $this->sourceQuery->virtualFilesImpactedBy($target);
        $physicalFiles = $this->physicalFilesFromVirtualFiles($virtualFiles, $graphFiles);
        $impactedOwners = $this->impactedOwnersFromImpactAndFiles($memberImpact, $graphFiles);

        return new MemberGraphImpact(
            target: $target,
            memberImpact: $memberImpact,
            graphFiles: $graphFiles,
            physicalFiles: $physicalFiles,
            virtualFiles: $virtualFiles,
            impactedOwners: $impactedOwners,
            owners: $this->knownOwnersFor($impactedOwners),
            declarations: $this->declarationsInFiles($graphFiles),
            usages: $this->memberUsagesInFiles($graphFiles),
            parameterUsages: $this->parameterUsagesInFiles($graphFiles),
            availableMembers: $this->availableMembersFor($impactedOwners),
            ownerDeclarations: $this->ownerDeclarationsInFiles($graphFiles),
            ownerUsages: $this->ownerUsagesInFiles($graphFiles),
        );
    }

    /**
     * Builds the physical file list backing impacted virtual files.
     *
     * @param VirtualPhpSourceFileCollection $virtualFiles the impacted virtual files
     * @param ImpactedFileCollection         $graphFiles   the impacted graph files used as fallback
     */
    private function physicalFilesFromVirtualFiles(
        VirtualPhpSourceFileCollection $virtualFiles,
        ImpactedFileCollection $graphFiles,
    ): ImpactedFileCollection {
        $physicalFiles = new ImpactedFileCollection();

        foreach ($virtualFiles as $virtualFile) {
            $physicalFiles->add($virtualFile->fullFilePath);
        }

        if (0 < count($physicalFiles)) {
            return $physicalFiles;
        }

        foreach ($graphFiles as $graphFile) {
            $physicalFiles->add($graphFile);
        }

        return $physicalFiles;
    }

    /**
     * Builds the impacted owner list from direct impact and file-index projections.
     *
     * @param MemberImpact           $memberImpact the low-level impact result
     * @param ImpactedFileCollection $graphFiles   the impacted graph files
     */
    private function impactedOwnersFromImpactAndFiles(
        MemberImpact $memberImpact,
        ImpactedFileCollection $graphFiles,
    ): ImpactedOwnerCollection {
        $owners = new ImpactedOwnerCollection();

        foreach ($memberImpact->impactedOwners as $owner) {
            $owners->add($owner);
        }

        foreach ($graphFiles as $graphFile) {
            foreach ($this->graphQuery->ownersInFile($graphFile) as $owner) {
                $owners->add($owner);
            }
        }

        return $owners;
    }

    /**
     * Returns known owner DTOs matching impacted owner symbols.
     *
     * @param ImpactedOwnerCollection $impactedOwners the impacted owner symbols
     */
    private function knownOwnersFor(ImpactedOwnerCollection $impactedOwners): KnownOwnerCollection
    {
        $owners = new KnownOwnerCollection();
        $knownOwners = $this->graphQuery->allOwners();

        foreach ($impactedOwners as $impactedOwner) {
            $knownOwner = $knownOwners->get($impactedOwner);

            if (null !== $knownOwner) {
                $owners->add($knownOwner);
            }
        }

        return $owners;
    }

    /**
     * Returns declarations located in impacted graph files.
     *
     * @param ImpactedFileCollection $graphFiles the impacted graph files
     */
    private function declarationsInFiles(ImpactedFileCollection $graphFiles): MemberDeclarationCollection
    {
        $declarations = new MemberDeclarationCollection();

        foreach ($this->graphQuery->allDeclarations()->all() as $declaration) {
            if ($graphFiles->contains($declaration->file)) {
                $declarations->add($declaration);
            }
        }

        return $declarations;
    }

    /**
     * Returns member usages located in impacted graph files.
     *
     * @param ImpactedFileCollection $graphFiles the impacted graph files
     */
    private function memberUsagesInFiles(ImpactedFileCollection $graphFiles): MemberUsageCollection
    {
        $usages = new MemberUsageCollection();

        foreach ($this->graphQuery->allMemberUsages()->all() as $usagesByTarget) {
            foreach ($usagesByTarget as $usage) {
                if ($graphFiles->contains($usage->file)) {
                    $usages->add($usage);
                }
            }
        }

        return $usages;
    }

    /**
     * Returns parameter usages located in impacted graph files.
     *
     * @param ImpactedFileCollection $graphFiles the impacted graph files
     */
    private function parameterUsagesInFiles(ImpactedFileCollection $graphFiles): ParameterUsageCollection
    {
        $usages = new ParameterUsageCollection();

        foreach ($this->graphQuery->allParameterUsages()->all() as $usagesByTarget) {
            foreach ($usagesByTarget as $usage) {
                if ($graphFiles->contains($usage->file)) {
                    $usages->add($usage);
                }
            }
        }

        return $usages;
    }

    /**
     * Returns owner declarations located in impacted graph files.
     *
     * @param ImpactedFileCollection $graphFiles the impacted graph files
     */
    private function ownerDeclarationsInFiles(ImpactedFileCollection $graphFiles): OwnerDeclarationCollection
    {
        $declarations = new OwnerDeclarationCollection();

        foreach ($this->graphQuery->allOwnerDeclarations()->all() as $declaration) {
            if ($graphFiles->contains($declaration->file)) {
                $declarations->add($declaration);
            }
        }

        return $declarations;
    }

    /**
     * Returns owner usages located in impacted graph files.
     *
     * @param ImpactedFileCollection $graphFiles the impacted graph files
     */
    private function ownerUsagesInFiles(ImpactedFileCollection $graphFiles): OwnerUsageCollection
    {
        $usages = new OwnerUsageCollection();

        foreach ($this->graphQuery->allOwnerUsages()->all() as $usagesByTarget) {
            foreach ($usagesByTarget as $usage) {
                if ($graphFiles->contains($usage->file)) {
                    $usages->add($usage);
                }
            }
        }

        return $usages;
    }

    /**
     * Returns available members exposed by impacted owners.
     *
     * @param ImpactedOwnerCollection $impactedOwners the impacted owner symbols
     */
    private function availableMembersFor(ImpactedOwnerCollection $impactedOwners): AvailableMemberCollection
    {
        $availableMembers = new AvailableMemberCollection();

        foreach ($impactedOwners as $owner) {
            foreach ($this->graphQuery->availableMembersOf($owner)->iterateMembers() as $availableMember) {
                $availableMembers->add($availableMember);
            }
        }

        return $availableMembers;
    }
}
