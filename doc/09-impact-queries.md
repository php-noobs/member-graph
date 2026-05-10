# Impact Queries

Navigation: [Back to README](README.md) | [Previous: Maintenance Guide](08-maintenance-guide.md) | [Next: Query Service](10-query-service.md)

Impact queries provide a focused API above `MemberDependencyGraph`.

They answer: "if this owner, member, or parameter changes, which declarations, usages, owners, and files are impacted?"

## Graph-Level Resolver

The graph-level resolver is `MemberImpactResolver`.

Path:

```text
Application/Impact/MemberImpactResolver.php
```

It receives:

- a `MemberDependencyGraph`;
- a `MemberImpactTarget`.

It returns a `MemberImpact`.

Use this resolver when the caller only needs exact graph facts directly related to a target.

## Application-Level Service

The application-level service is `MemberGraphImpactService`.

Path:

```text
Application/Impact/MemberGraphImpactService.php
```

It composes:

- `MemberGraphQueryService`;
- `MemberGraphSourceQueryService`;
- `VirtualPhpSourceFileCollection`.

It is the preferred API when callers need a read-only impact view suitable for analysis tools:

```php
$impactService = MemberGraphImpactService::fromBuild($build);

$impact = $impactService->method('App\\Service\\UserService', 'send');
```

`fromGraphAndVirtualFiles()` remains available for lower-level callers that already own a graph and a virtual-file collection without using `MemberDependencyGraphFactory`.

It also supports direct helpers for other target families:

```php
$impactService->property('App\\Config', 'mailer');
$impactService->classConstant('App\\Config', 'DEFAULT_MAILER');
$impactService->function('App\\send_mail');
$impactService->parameter('App\\Mailer', 'send', 'message');
$impactService->owner('App\\Mailer');
```

## Targets

`MemberImpactTarget` provides named constructors for supported target families:

```php
MemberImpactTarget::method('App\\Mailer', 'send');
MemberImpactTarget::property('App\\Config', 'mailer');
MemberImpactTarget::classConstant('App\\Config', 'DEFAULT_MAILER');
MemberImpactTarget::forFunction('App\\send_mail');
MemberImpactTarget::parameter('App\\Mailer', 'send', 'message');
MemberImpactTarget::owner('App\\Mailer');
```

Function targets use an empty member owner internally, matching how function declarations and usages are represented by the graph.

## Result DTO

`MemberImpact` contains:

- `target`: the queried target;
- `declarations`: declarations directly matching the target;
- `memberUsages`: member usages directly matching the target;
- `parameterUsages`: parameter usages directly matching the target;
- `ownerDeclarations`: owner declarations directly matching the target;
- `ownerUsages`: owner usages directly matching the target;
- `impactedOwners`: target and source owners inferred from declarations and usages;
- `impactedFiles`: files inferred from declarations and usages.

`MemberGraphImpact` wraps `MemberImpact` and adds the richer projection expected by application code:

- `target`: the queried target;
- `memberImpact`: the low-level graph impact;
- `graphFiles`: graph file paths referenced by impacted facts;
- `physicalFiles`: physical files backing impacted virtual files;
- `virtualFiles`: impacted `VirtualPhpSourceFile` instances;
- `impactedOwners`: impacted owner symbols;
- `owners`: known-owner DTOs matching impacted owner symbols;
- `declarations`: declarations located in impacted graph files;
- `usages`: member usages located in impacted graph files;
- `parameterUsages`: parameter usages located in impacted graph files;
- `ownerDeclarations`: owner declarations located in impacted graph files;
- `ownerUsages`: owner usages located in impacted graph files;
- `availableMembers`: available members exposed by impacted owners.

This richer DTO is read-only. It does not mutate or reassemble source files.

## Source Node Locator

`MemberGraphSourceNodeLocator` locates AST nodes related to an impact target inside impacted `VirtualPhpSourceFile` instances.

Path:

```text
Application/Source/Node/MemberGraphSourceNodeLocator.php
```

It is the source-level companion to impact queries:

```php
$locator = MemberGraphSourceNodeLocator::fromBuild($build);

$matches = $locator->method('App\\Service\\UserService', 'send');
```

The locator is strict by default:

```php
$locator = MemberGraphSourceNodeLocator::fromBuild(
    build: $build,
    allowFallbackMatching: false,
);
```

`allowFallbackMatching` enables name-based fallback matching only when graph facts do not carry `SourceNodeId`.
It should be used only for graphs or focused tests built manually without source-node metadata.
Production source lookup should keep it disabled so returned nodes are matched by parser position.

It also supports the same target families as `MemberGraphImpactService`:

```php
$locator->property('App\\Config', 'mailer');
$locator->classConstant('App\\Config', 'DEFAULT_MAILER');
$locator->function('App\\send_mail');
$locator->parameter('App\\Mailer', 'send', 'message');
$locator->parameter('App\\Mailer', 'send', 'message', 0);
$locator->parameterAt('App\\Mailer', 'send', 'message', 0);
$locator->owner('App\\Mailer');
```

The locator returns a `VirtualPhpSourceFileNodeMatchCollection`.

Each `VirtualPhpSourceFileNodeMatch` contains:

- `virtualFile`: the `VirtualPhpSourceFile` containing the node;
- `node`: the matched PHPParser `Node`;
- `target`: the original `MemberImpactTarget`;
- `role`: why the node was returned.

The collection can be filtered and projected without writing custom loops:

```php
$matches = $locator->method('App\\Service\\UserService', 'send');

$declarations = $matches->memberDeclarations();
$usages = $matches->memberUsages();
$ownerDeclarations = $matches->ownerDeclarations();
$ownerUsages = $matches->ownerUsages();
$sourceFiles = $matches->virtualFiles();
$nodes = $matches->nodes();
```

Available filters are `byRole()`, `ownerDeclarations()`, `ownerUsages()`, `memberDeclarations()`, `memberUsages()`, `parameterDeclarations()`, `parameterUsages()`, `byVirtualFilePath()`, and `byNodeClass()`.

Match roles are intentionally split between owners, members, and parameters.
Owners are class-like symbols such as classes, interfaces, traits, and enums.
Members are graph-level symbols such as methods, properties, class constants, enum cases, and functions.
Parameters are the named inputs of a method, closure, or function and are tracked as their own target family.

- `OWNER_DECLARATION`: the node declares a class-like owner, such as a class, interface, trait, or enum;
- `OWNER_USAGE`: the node uses a class-like owner through a native PHP class-name reference;
- `MEMBER_DECLARATION`: the node declares a graph member, such as a method, property, class constant, enum case, or function. A promoted-property `Param` is a member declaration when it declares the property member;
- `MEMBER_USAGE`: the node uses a graph member, such as a method call, property fetch, class-constant fetch, or function call;
- `PARAMETER_DECLARATION`: the `Param` node declares the target parameter on a method, closure, or function;
- `PARAMETER_USAGE`: the node uses the target parameter through a named argument.

The locator does not rebuild the graph and does not scan the full codebase.
It first asks the impact service for impacted virtual files, then re-traverses only those ASTs.

When graph facts carry `SourceNodeId`, the locator matches declarations and usages by parser position instead of by name.
This prevents same-name nodes in the same virtual file from being returned when they do not correspond to the impacted graph fact.

For parameter targets, the locator also includes the declaration virtual file of the method or function so callers can inspect both the parameter declaration and named-argument usages.
Parameter usage nodes are matched by `SourceNodeId`; parameter declaration nodes are matched inside the exact target function-like declaration by parameter name.

Parameter lookup can optionally receive a zero-based declaration index:

```php
$matches = $locator->parameter('App\\Mailer', 'send', 'message', 0);
```

When the index is provided, `PARAMETER_DECLARATION` matches must satisfy both the parameter name and the declaration index.
The index is part of the `ParameterId` identity used by impact targets, so indexed and non-indexed parameter targets do not share the same exact hash.
This allows rename tooling to target one parameter during temporary swap states where two parameters may currently have the same name.
Named-argument `PARAMETER_USAGE` matches remain graph-driven and continue to be returned through the name-scoped parameter lookup when the graph can relate them to the targeted parameter name.

This API does not mutate code.
It only provides source-level facts a higher-level tool may inspect.

`SourceNodeId` is stored on owner declarations, owner usages, member declarations, member usages, and parameter usages when parser position attributes are available.
It uses the virtual file path, node type, file offsets, and line range as a deterministic source identifier.

## Read-Only Analysis Workflow

The graph API is intentionally read-only.
Its job is to answer questions about members, parameters, usages, owners, files, and source nodes.
It does not decide what a caller should do with those facts.

A typical analysis flow is:

```php
$impactService = MemberGraphImpactService::fromBuild($build);
$locator = MemberGraphSourceNodeLocator::fromBuild($build);

$impact = $impactService->method('App\\Service\\UserService', 'send');
$matches = $locator->method('App\\Service\\UserService', 'send');
```

Use `MemberGraphImpactService` when the caller needs graph-level impact:

- impacted declarations;
- impacted member usages;
- impacted parameter usages;
- impacted owner declarations;
- impacted owner usages;
- impacted owners;
- impacted physical and virtual files;
- available members exposed by impacted owners.

Use `MemberGraphSourceNodeLocator` when the caller needs exact PHPParser nodes inside the impacted virtual files:

- declaration nodes;
- usage nodes;
- owner declaration nodes;
- owner usage nodes;
- parameter declaration nodes;
- named-argument usage nodes.

Both APIs share the same target families: owners, methods, properties, class constants, functions, and parameters.
They are separate on purpose: impact queries stay graph-level, while source-node location stays AST-level.

## Owner Resolution Policy

The first implementation keeps owner resolution simple and graph-local:

- target owners come from declarations and usages;
- source owners are extracted from `sourceSymbol` values formatted as `Owner::member`;
- function-like source symbols without `::` do not produce an impacted owner.

This keeps impact resolution focused on graph facts and reusable by higher-level query services.

## File Resolution Policy

Impacted files are collected from the `file` fields already carried by:

- `MemberDeclaration`;
- `MemberUsage`;
- `ParameterUsage`.

This means the initial file impact query is usable without a separate file index.

Richer file-level behavior belongs to `MemberGraphImpactService`, not to `MemberImpactResolver`.

This keeps `MemberImpactResolver` graph-local while still allowing application code to obtain virtual files and physical files through the higher-level service.

## Current Scope

The current resolver is intentionally exact:

- member targets return exact matching member declarations and usages;
- parameter targets return exact matching parameter usages;
- polymorphic behavior is whatever the graph already collected;
- no extra validation or rule logic is applied.

This keeps impact queries as a read-side helper, not a new graph-building phase.

Navigation: [Back to README](README.md) | [Previous: Maintenance Guide](08-maintenance-guide.md) | [Next: Query Service](10-query-service.md)
