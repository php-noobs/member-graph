# MemberGraph Documentation

Navigation: [Next: Overview](01-overview.md)

This documentation describes the `MemberGraph` component, how to use it, how it works internally, and which rules should guide future changes.

The `MemberGraph` component builds a dependency graph at PHP member and owner level: classes, interfaces, traits, enums, methods, functions, properties, class constants, and named parameters. It complements class-level dependencies with a finer-grained view of declarations and usages.

## Pages

1. [Overview](01-overview.md)
2. [Public Usage](02-public-usage.md)
3. [Build Pipeline](03-build-pipeline.md)
4. [Domain Model](04-domain-model.md)
5. [Type And Expression Resolution](05-type-and-expression-resolution.md)
6. [PHPDoc And Structured Types](06-phpdoc-and-structured-types.md)
7. [Testing And Debugging](07-testing-and-debugging.md)
8. [Maintenance Guide](08-maintenance-guide.md)
9. [Impact Queries](09-impact-queries.md)
10. [Query Service](10-query-service.md)
11. [Member Dependency Graph Factory](11-member-dependency-graph-factory.md)
12. [Partial Rebuild Design](12-partial-rebuild-design.md)
13. [Topology Service](13-topology-service.md)
14. [Public API Entry Points](14-public-api-entrypoints.md)

## Visual Reference

The main pipeline is summarized in [Flowchart.md](./assets/Flowchart.md).

## External Dependency

`MemberGraph` consumes `php-noobs/php-source-registry` for physical PHP files, virtual source files, PHPParser AST storage, updates, and reassembly.

`MemberGraph` owns dependency graph facts, semantic indexes, impact queries, source-node lookup, topology, cache, and partial rebuild behavior.

## Current Layout

```text
MemberGraph/
  Domain/
    Availability/
    Declaration/
    Graph/
    Index/
      ClassLike/
      Constant/
      Function/
      Method/
      Polymorphism/
      Property/
      Template/
    Owner/
    Parameter/
    Source/
    Symbol/
    Type/
    Usage/

  Application/
    Build/
      Context/
      Factory/
        Mode/
        Plan/
        Runner/
      GlobalIndex/
      GlobalIndexRebuild/
      Input/
      PartialGraph/
        Assembly/
        Diagnostics/
        Execution/
        Input/
        Loading/
        SourceView/
        WorkingSet/
      Projection/
      Source/
    Cache/
      Core/
      Fingerprint/
      Fragment/
      Plan/
      Snapshot/
        Declaration/
      VirtualFile/
    Collect/
    Enrich/
    Impact/
    Project/
    Query/
    Resolver/
      Contracts/
      Expression/
      Service/
    Source/
      Node/
    Topology/
      Api/
      Export/
      Filter/
    Traverse/
    Validator/
      PhpDoc/

  Infrastructure/
    PhpDoc/
      Extractor/
      Inheritance/
      Parser/
      Renderer/
      Resolver/
      Template/
      Traversal/
      ValueExtraction/
    PhpParser/
      Indexing/
      Traversal/
    UseStatements/
```

The general rule is:

- `Domain/` contains graph objects and indexes.
- `Application/Build/` contains graph build orchestration, factory entry points, global-index preparation, partial graph rebuild services, and source-view assembly.
- `Application/Build/Factory/` contains the public factory entry point, build DTOs, modes, rebuild planning DTOs, and build-mode runners.
- `Application/Build/GlobalIndexRebuild/` contains cache-backed source metadata and global-index rebuild input services.
- `Application/Build/PartialGraph/` contains partial graph rebuild assembly, diagnostics, execution, input, loading, source-view, and working-set services.
- `Application/Build/Projection/` contains policy-free projected-build services for supported semantic identity updates.
- `Application/Cache/` contains cache storage, planning, fragments, fingerprints, virtual-file metadata, and cacheable snapshots.
- `Application/Resolver/` contains expression resolver contracts, expression strategies, and shared resolver services.
- `Application/Impact/`, `Application/Query/`, `Application/Source/`, and `Application/Topology/` expose impact resolution, read/query services, source-node lookup, and bounded topology projections over the graph.
- `Infrastructure/` contains PHPParser, PHPDoc, and use-statement adapters.

Navigation: [Next: Overview](01-overview.md)
