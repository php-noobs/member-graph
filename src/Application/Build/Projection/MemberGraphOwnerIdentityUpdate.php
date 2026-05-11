<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Application\Build\Projection;

/**
 * Represents one owner identity update requested by a graph projection.
 */
final readonly class MemberGraphOwnerIdentityUpdate
{
    /**
     * Constructor.
     *
     * @param string $owner    the current owner identity to update
     * @param string $newOwner the projected owner identity
     */
    public function __construct(
        public string $owner,
        public string $newOwner,
    ) {
    }
}
