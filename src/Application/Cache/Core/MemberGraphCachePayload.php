<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Cache\Core;

use PhpNoobs\MemberGraph\Application\Cache\Plan\MemberGraphCacheFilePayload;
use PhpNoobs\MemberGraph\Application\Cache\Snapshot\Declaration\MemberGraphDeclarationSnapshot;
use PhpNoobs\MemberGraph\Application\Cache\Snapshot\MemberGraphGlobalIndexInputSnapshot;
use PhpNoobs\MemberGraph\Application\Cache\VirtualFile\MemberGraphVirtualFileReferenceCollection;
use PhpNoobs\MemberGraph\Domain\Owner\KnownOwnerCollection;

/**
 * Serializable payload for member graph cache metadata.
 */
final readonly class MemberGraphCachePayload
{
    public const int SCHEMA_VERSION = 9;

    /**
     * Constructor.
     *
     * @param int                                        $schemaVersion            the cache schema version
     * @param array<string, MemberGraphCacheFilePayload> $filesByPath              cache file payloads indexed by physical file path
     * @param MemberGraphVirtualFileReferenceCollection  $virtualFileReferences    cached virtual file references
     * @param KnownOwnerCollection|null                  $knownOwners              cached known owners
     * @param MemberGraphGlobalIndexInputSnapshot|null   $globalIndexInputSnapshot cached global-index input snapshot
     * @param MemberGraphDeclarationSnapshot|null        $declarationSnapshot      cached declaration snapshot
     */
    public function __construct(
        public int $schemaVersion = self::SCHEMA_VERSION,
        public array $filesByPath = [],
        public MemberGraphVirtualFileReferenceCollection $virtualFileReferences = new MemberGraphVirtualFileReferenceCollection(),
        public ?KnownOwnerCollection $knownOwners = null,
        public ?MemberGraphGlobalIndexInputSnapshot $globalIndexInputSnapshot = null,
        public ?MemberGraphDeclarationSnapshot $declarationSnapshot = null,
    ) {
    }
}
