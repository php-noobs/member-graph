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

Use `fromVirtualFiles()` when the caller already owns virtual files and needs a fresh in-memory graph from the current AST state:

```php
$freshBuild = MemberDependencyGraphFactory::fromVirtualFiles(
    virtualFiles: $build->virtualFiles,
);
```

Use this entry point after in-memory AST mutations. The caller should call `update()` on touched virtual files before rebuilding:

```php
$virtualFile->update($virtualFile->nodes);

$freshBuild = MemberDependencyGraphFactory::fromVirtualFiles($build->virtualFiles);
```

This path returns a normal `MemberDependencyGraphBuild`, but it does not scan directories, does not read physical files, and does not write the persistent cache.
Updated virtual files refresh their structural PHPParser attributes before the graph is rebuilt.
The returned build exposes the source registry that owns its virtual files:

```php
$freshBuild->sourceRegistry()->save();
```

Use `MemberGraphProjectedBuildFactory` when the caller only needs supported semantic identity updates and wants to avoid a full AST rebuild:

```php
$overlay = MemberGraphBuildOverlay::empty()
    ->withOwnerUpdate('App\\Mailer', 'App\\Infrastructure\\Sender')
    ->withMethodUpdate('App\\Infrastructure\\Sender', 'send', 'deliver')
    ->withPropertyUpdate('App\\Infrastructure\\Sender', 'transport', 'mailerTransport')
    ->withParameterUpdate('App\\Infrastructure\\Sender', 'deliver', 'message', 'emailMessage', 0);

$projectedBuild = MemberGraphProjectedBuildFactory::fromBuild($build, $overlay);
```

The projected build is a normal `MemberDependencyGraphBuild`.
It preserves the base build source registry instance.
Supported projections cover owner FQCN updates, method updates, property updates, class-constant updates, enum-case updates, function FQCN updates, namespace-level constant FQCN updates, and parameter updates with an optional declaration index.
Member and parameter updates can be expressed with current projected owner/function-like identities.
The projection is policy-free: it does not mutate AST nodes, write files, refresh cache, decide conflicts, or infer refactoring intent.
Unsupported update families should keep using `MemberDependencyGraphFactory::fromVirtualFiles()`.

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
$constantMatches = $locator->constant('App\\Config\\ENABLED');
$parameterMatches = $locator->parameter('App\\Service\\UserService', 'send', 'message', 0);
$parameterScope = $locator->parameterScope('App\\Service\\UserService', 'send', 'message', 0);
$propertyContext = $locator->propertyDeclarationContext('App\\Service\\UserService', ['mailer', 'backupMailer']);
$ownerMatches = $locator->owner('App\\Service\\UserService');
```

Use this entry point when the caller needs AST nodes for owner declarations, owner usages, member declarations, member usages, parameter declarations, named-argument usages, or local usages of parameters that are exposed as source-level facts.
The locator is strict by default and uses `SourceNodeId` when graph facts carry parser-position metadata.
Parameter lookup accepts an optional zero-based declaration index so refactoring tools can target the exact `Param` node during rename swaps.
That index is carried by the underlying `ParameterId`; indexed targets have an exact indexed hash, while named-argument usages remain discoverable through the compatible name-scoped lookup.
Parameter lookup also returns `PARAMETER_LOCAL_USAGE` matches for local `Variable` nodes inside the declaring body.
Those matches are computed on demand from the already loaded AST and are not persisted in the graph cache.
Property lookup returns promoted-property `Param` nodes as `MEMBER_DECLARATION`.
When the property target is a promoted property, lookup also returns `PROMOTED_PROPERTY_PARAMETER_LOCAL_USAGE` matches for local `Variable` nodes that refer to the promoted constructor parameter.
Method lookup follows trait-projected available-member families.
For trait source methods, consumer calls resolved through a consuming class are returned as `MEMBER_USAGE`, alias adaptation source references are returned as `TRAIT_ALIAS_ADAPTATION_SOURCE`, and precedence adaptation method references are returned as `TRAIT_PRECEDENCE_ADAPTATION_METHOD`.
Constant lookup returns namespace-level `const` items as `MEMBER_DECLARATION` and resolved constant fetches as `MEMBER_USAGE`.
`use const` items are exposed by import scopes rather than by source-node usage lookup.
Use `parameterScope()` when a caller needs neutral facts about the declaring scope: same-signature `Param` nodes, assigned local `Variable` nodes, and targeted parameter local usages.
Use `propertyDeclarationContext()` when a caller needs neutral structural facts around grouped property declarations or promoted-property parameters.
MemberGraph exposes those facts without deciding whether they are rename conflicts.

## Symbol Scopes

Use `MemberGraphSymbolScopeLocator` to inspect neutral symbol facts around declarations, namespaces, and imports:

```php
$scopeLocator = MemberGraphSymbolScopeLocator::fromBuild($build);

$methodScope = $scopeLocator->methodScope('App\\Mailer', 'send');
$propertyScope = $scopeLocator->propertyScope('App\\Mailer', 'transport');
$constantScope = $scopeLocator->classConstantScope('App\\Status', 'ACTIVE');
$classLikeScope = $scopeLocator->classLikeNamespaceScope('App\\Domain');
$functionScope = $scopeLocator->functionNamespaceScope('App\\Domain');
$namespaceConstantScope = $scopeLocator->constantNamespaceScope('App\\Domain');
$importScope = $scopeLocator->fileImportScope($virtualFile);
```

The scope locator returns facts, not decisions. Callers can inspect names, short names, aliases, exact nodes, and virtual files, then apply their own policy.

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

These entry points are the stable public surface for package consumers.

Navigation: [Back to README](README.md) | [Previous: Topology Service](13-topology-service.md)
