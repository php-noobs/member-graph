# MemberGraph

[![CI](https://github.com/php-noobs/member-graph/actions/workflows/ci.yml/badge.svg)](https://github.com/php-noobs/member-graph/actions/workflows/ci.yml)

PHP member-level dependency graph builder.

`MemberGraph` analyzes PHP source code and builds dependency facts at member level: methods, functions, properties, class constants, enum cases, and named parameters.

It is designed for tools that need to answer questions such as:

- where is this method declared?
- where is this property used?
- which files are impacted by this member?
- which owners expose this member after inheritance, interfaces, and traits?
- which source nodes correspond to graph declarations and usages?

## Status

This package is currently extracted locally from the main project.
It already uses its target package and namespace:

```text
php-noobs/member-graph
```

```php
namespace PhpNoobs\MemberGraph;
```

## Requirements

- PHP 8.4+
- `php-noobs/php-source-registry`
- `nikic/php-parser`
- `phpstan/phpdoc-parser`
- `psr/log`

## Installation

During local development, consume the package through a Composer path repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../PhpNoobs/php-source-registry",
      "options": {
        "symlink": true
      }
    },
    {
      "type": "path",
      "url": "packages/member-graph",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "php-noobs/php-source-registry": "dev-main",
    "php-noobs/member-graph": "dev-main"
  }
}
```

Then run:

```bash
composer update php-noobs/member-graph
```

## Basic Usage

```php
use PhpNoobs\MemberGraph\Application\Build\Factory\MemberDependencyGraphFactory;

$build = MemberDependencyGraphFactory::fromDirectory(
    directories: ['/project/src'],
    cacheFilePath: '/project/var/member-graph.cache',
    excludedDirectories: ['/project/src/Generated'],
);

$graph = $build->memberDependencyGraph;
```

For transactional in-memory workflows, rebuild from the current virtual-file ASTs:

```php
$virtualFile->update($virtualFile->nodes);

$build = MemberDependencyGraphFactory::fromVirtualFiles($build->virtualFiles);
```

This path does not scan directories, read physical files, or write the persistent cache.

For supported transaction identity updates, project a build without a full AST rebuild:

```php
use PhpNoobs\MemberGraph\Application\Build\Projection\MemberGraphBuildOverlay;
use PhpNoobs\MemberGraph\Application\Build\Projection\MemberGraphProjectedBuildFactory;

$overlay = MemberGraphBuildOverlay::empty()
    ->withOwnerUpdate('App\\Mailer', 'App\\Infrastructure\\Sender')
    ->withMethodUpdate('App\\Infrastructure\\Sender', 'send', 'deliver')
    ->withParameterUpdate('App\\Infrastructure\\Sender', 'deliver', 'message', 'emailMessage', 0);

$build = MemberGraphProjectedBuildFactory::fromBuild($build, $overlay);
```

The projected build is a normal `MemberDependencyGraphBuild` for the supported update families.

## Query Usage

```php
use PhpNoobs\MemberGraph\Application\Query\MemberGraphQueryService;

$query = MemberGraphQueryService::fromGraph($graph);

$declarations = $query->allDeclarations();
$usages = $query->allMemberUsages();
$owners = $query->allOwners();
```

## Impact Usage

```php
use PhpNoobs\MemberGraph\Application\Impact\MemberGraphImpactService;

$impactService = MemberGraphImpactService::fromBuild($build);

$impact = $impactService->method('App\\Service\\UserService', 'send');
```

The returned impact DTO exposes graph files, physical files, virtual files, owners, declarations, member usages, parameter usages, and available members.

## Documentation

Detailed documentation lives in [`doc/README.md`](doc/README.md).

The documentation covers:

- public usage;
- build pipeline;
- domain model;
- type and expression resolution;
- PHPDoc and structured types;
- testing and debugging;
- cache and partial rebuild design;
- impact queries;
- query service;
- topology service;
- public API entry points.

## Boundaries

`MemberGraph` owns dependency graph facts, semantic indexes, impact queries, source-node lookup, topology, cache, and partial rebuild behavior.

It does not own physical PHP file loading, virtual source files, AST storage, source updates, or physical-file reassembly. Those concerns belong to `php-noobs/php-source-registry`.

## Tests

From the consuming project, run the MemberGraph test suites:

```bash
vendor/bin/phpunit tests/Unit/MemberGraph tests/Integration/MemberGraph
```

When this package gets its own autonomous test suite, it should be runnable from the package root with:

```bash
vendor/bin/phpunit
```
