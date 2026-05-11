# Public Usage

Navigation: [Back to README](README.md) | [Previous: Overview](01-overview.md) | [Next: Build Pipeline](03-build-pipeline.md)

This page describes how the component is expected to be used by the rest of the system.

## Directory Entry Point

The main entry point for project directories is `MemberDependencyGraphFactory`.

Current path:

```text
src/Test/DependencyGraph/MemberGraph/Application/Build/Factory/MemberDependencyGraphFactory.php
```

The factory uses `MemberGraphPhpFileScanner` to scan PHP files and apply directory exclusions, manages cache metadata, and returns a `MemberDependencyGraphBuild`.

```php
$build = MemberDependencyGraphFactory::fromDirectory(
    directories: ['/project/src'],
    cacheFilePath: '/project/var/member-graph.cache',
    excludedDirectories: ['/project/src/Generated'],
);

$graph = $build->memberDependencyGraph;
```

`MemberDependencyGraphBuild` exposes:

- `memberDependencyGraph`;
- `virtualFiles`;
- `virtualFileReferences`;
- `knownOwners`;
- `dependencyGraphIssues`;
- `buildReport`.

`virtualFiles` contains virtual files loaded during the current run.

`virtualFileReferences` contains lightweight source metadata and remains available when the factory uses the cache fast path.

## In-Memory Virtual-File Rebuild

When a caller already owns virtual files, it can rebuild a fresh graph directly from those in-memory ASTs:

```php
$freshBuild = MemberDependencyGraphFactory::fromVirtualFiles(
    virtualFiles: $build->virtualFiles,
);
```

Use this entry point for transactional source-modification workflows. For example, a refactoring tool can locate exact nodes, mutate the `VirtualPhpSourceFile` ASTs, call `update()` on touched virtual files, then rebuild semantic facts before planning the next action:

```php
$virtualFile->update($virtualFile->nodes);

$build = MemberDependencyGraphFactory::fromVirtualFiles($build->virtualFiles);
```

This rebuild does not scan directories, does not read physical files, and does not write the persistent cache.

If a virtual file reports `isUpdated()`, the factory refreshes structural PHPParser attributes before recomputing known owners and rebuilding the graph.

## Projected Build

When a transaction only applies supported semantic identity updates, callers can project a build instead of rebuilding from all virtual-file ASTs:

```php
$overlay = MemberGraphBuildOverlay::empty()
    ->withOwnerUpdate('App\\Mailer', 'App\\Infrastructure\\Sender')
    ->withMethodUpdate('App\\Infrastructure\\Sender', 'send', 'deliver');

$build = MemberGraphProjectedBuildFactory::fromBuild($build, $overlay);
```

The projected build is a normal `MemberDependencyGraphBuild`.
It preserves virtual files, PHPParser nodes, source-node identifiers, and file paths while projecting graph identities.

The first supported slice covers owner FQCN updates, method updates, and chained owner + method updates.
The method update can be expressed against the current projected owner identity.

The projection does not mutate source, does not write files, does not refresh cache, and does not implement refactoring policy.
If a transaction action is not covered by the projection slice, use `MemberDependencyGraphFactory::fromVirtualFiles()` to rebuild a fully fresh in-memory graph.

## Builder Input

`MemberDependencyGraphBuilder` remains the lower-level build orchestrator.

It receives a `MemberGraphBuildInput`:

```php
$builder = new MemberDependencyGraphBuilder($dependencyGraphIssues);
$graph = $builder->build($input);
```

`MemberGraphBuildInput` carries known owners and virtual files to analyze.

The caller is responsible for adapting the file registry into this input. The builder does not depend directly on the global registry.

Most consumers should prefer `MemberDependencyGraphFactory::fromDirectory()` unless they already own a prepared `MemberGraphBuildInput`.

## Reading Declarations

Declarations are available through `MemberDeclarationCollection`.

Each declaration has a `MemberId`, composed mainly of:

- owner;
- member type;
- member name.

Examples of member types:

- `FUNCTION_`;
- `METHOD`;
- `PROPERTY`;
- `CLASS_CONSTANT`.

## Reading Usages

Member usages are stored in `MemberUsageCollection`.

They are indexed by target. A target is also a `MemberId`.

Typical cases:

- `$service->send()` targets `Mailer::send`;
- `Factory::create()` targets `Factory::create`;
- `$this->mailer` targets the declared property;
- `self::NAME` targets the resolved constant.

## Reading Parameter Usages

Parameter usages are stored in `ParameterUsageCollection`.

They are useful for named arguments:

```php
$service->send(message: $message);
```

The graph can then connect `message:` to the parameter declared by the target method.

## Reading Available Members

`AvailableMemberCollection` represents members available on an owner after projection.

This projection accounts for:

- directly declared members;
- inherited members;
- interface-provided members;
- trait-provided members;
- trait aliases;
- `insteadof` rules;
- visibility adaptations.

The `declaredIns` field preserves the real sources of a projected member.

## Typical Consumer Flow

A typical consumer:

1. creates a `MemberDependencyGraphBuild` with `MemberDependencyGraphFactory`;
2. creates a `MemberGraphQueryService` from the graph;
3. identifies a declaration, usage, dependency, impact target, or source file;
4. consumes the returned facts in an external tool or diagnostic rule.

The component does not mutate code. It provides read-only dependency facts.

For application-level impact queries, use `MemberGraphImpactService`:

```php
$impactService = MemberGraphImpactService::fromBuild($build);

$impact = $impactService->method('App\\Service\\UserService', 'send');
```

The returned `MemberGraphImpact` contains graph files, physical files, virtual files, owners, declarations, member usages, parameter usages, and available members.

## Topology Usage

Topology queries provide graph projections for inspection or visualization.

Typical flow:

```php
$build = MemberDependencyGraphFactory::fromDirectory(
    directories: ['/project/src'],
    cacheFilePath: '/project/var/member-graph.cache',
    excludedDirectories: ['/project/src/Generated'],
);

$api = MemberGraphTopologyApi::fromGraph($build->memberDependencyGraph);

$filter = new MemberGraphTopologyFilter(
    ownerPrefixes: ['App\\'],
    excludedOwnerPrefixes: ['App\\Generated\\'],
    memberTypes: [MemberType::METHOD],
    files: ['src/'],
);

$topology = $api->codebase(filter: $filter);

$mermaid = $api->export(
    topology: $topology,
    exporter: new MemberGraphTopologyMermaidExporter(),
);
```

For a focused class-like view:

```php
$ownerTopology = $api->owner(
    owner: 'App\\Service\\UserService',
    direction: MemberGraphTopologyDirection::BOTH,
    maxDepth: 2,
    filter: $filter,
);
```

The topology API is read-only. It does not mutate the graph or source files.

## Important Contract

The graph exposes dependency facts. It must not hide useful concrete types behind abstract constraints.

For example, if template substitution concretely resolves to `Mailer`, the graph must keep `Mailer` as the usage owner, even when the template had a `T of Sendable` bound.

Navigation: [Back to README](README.md) | [Previous: Overview](01-overview.md) | [Next: Build Pipeline](03-build-pipeline.md)
