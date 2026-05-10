# Public API Entry Points

Navigation: [Back to README](README.md) | [Previous: Topology Service](13-topology-service.md)

This page lists the current public entry points of the `MemberGraph` component.
It is intentionally focused on read-only graph usage and build orchestration.

## Build

Use `MemberDependencyGraphFactory` to build a graph from project directories:

```php
$build = MemberDependencyGraphFactory::fromDirectory(
    directories: ['/project/src'],
    cacheFilePath: '/project/var/member-graph.cache',
);
```

The factory returns a `MemberDependencyGraphBuild` containing the graph, loaded virtual files, cache report, and build report.

Use this entry point when the caller owns physical directories and wants the component to scan, cache, and build the graph.

## Query

Use `MemberGraphQueryService` for direct read-side graph queries:

```php
$query = MemberGraphQueryService::fromGraph($build->memberDependencyGraph);

$members = $query->membersOfOwner('App\\Service\\UserService');
$incoming = $query->incoming($memberId);
$outgoing = $query->outgoing($memberId);
```

Use this entry point when the caller needs exact graph facts without file or source-node projection.

## Impact

Use `MemberGraphImpactService` for application-level impact views:

```php
$impactService = MemberGraphImpactService::fromBuild($build);

$impact = $impactService->method('App\\Service\\UserService', 'send');
$ownerImpact = $impactService->owner('App\\Service\\UserService');
```

Use this entry point when the caller needs owner declarations, owner usages, member declarations, member usages, parameter usages, impacted owners, impacted files, virtual files, and available members for one target.

## Source Nodes

Use `MemberGraphSourceNodeLocator` to locate exact PHPParser nodes inside impacted virtual files:

```php
$locator = MemberGraphSourceNodeLocator::fromBuild($build);

$matches = $locator->method('App\\Service\\UserService', 'send');
$parameterMatches = $locator->parameter('App\\Service\\UserService', 'send', 'message', 0);
$ownerMatches = $locator->owner('App\\Service\\UserService');
```

Use this entry point when the caller needs AST nodes for owner declarations, owner usages, member declarations, member usages, parameter declarations, or named-argument usages.
The locator is strict by default and uses `SourceNodeId` when graph facts carry parser-position metadata.
Parameter lookup accepts an optional zero-based declaration index so refactoring tools can target the exact `Param` node during rename swaps.
That index is carried by the underlying `ParameterId`; indexed targets have an exact indexed hash, while named-argument usages remain discoverable through the compatible name-scoped lookup.

## Topology

Use `MemberGraphTopologyService` for bounded graph projections:

```php
$topologyService = MemberGraphTopologyService::fromGraph($build->memberDependencyGraph);

$topology = $topologyService->member($memberId);
```

Use `MemberGraphTopologyApi` when the caller also wants filters and exporter orchestration:

```php
$api = MemberGraphTopologyApi::fromGraph($build->memberDependencyGraph);

$result = $api->exportCodebase(new MemberGraphTopologyJsonExporter());
```

Use these entry points when the caller needs graph visualization or a portable topology DTO.

## Boundary

The component builds and exposes dependency facts.
It does not mutate source code, apply transformation rules, or reassemble physical files.
Those responsibilities belong to higher-level tools built above the graph.

If `MemberGraph` is later extracted into a standalone library, these entry points should remain the stable public surface unless a dedicated facade is introduced.

Navigation: [Back to README](README.md) | [Previous: Topology Service](13-topology-service.md)
