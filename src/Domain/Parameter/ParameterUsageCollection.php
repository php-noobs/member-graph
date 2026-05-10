<?php

declare(strict_types=1);

namespace PhpNoobs\MemberGraph\Domain\Parameter;

/**
 * Stores parameter usages indexed by parameter target.
 *
 * @implements \IteratorAggregate<string, list<ParameterUsage>>
 */
final class ParameterUsageCollection implements \Countable, \IteratorAggregate
{
    /**
     * @var array<string, list<ParameterUsage>>
     */
    private array $byTarget = [];

    /**
     * Adds one usage to the collection.
     */
    public function add(ParameterUsage $usage): void
    {
        $this->byTarget[$usage->target->hash()][] = $usage;
    }

    /**
     * Get all collection items.
     *
     * @return array<string, list<ParameterUsage>>
     */
    public function all(): array
    {
        return $this->byTarget;
    }

    /**
     * Returns usages indexed for one parameter identifier.
     *
     * Indexed identifiers first use their exact hash, then include name-scoped usages. This keeps named-argument usages
     * discoverable when the collector cannot know the declaration index without coupling itself to signature indexes.
     *
     * @return list<ParameterUsage>
     */
    public function getByTarget(ParameterId $parameterId): array
    {
        $usages = $this->byTarget[$parameterId->hash()] ?? [];

        if (!$parameterId->hasParameterIndex()) {
            return array_values($usages);
        }

        foreach ($this->byTarget[$parameterId->nameHash()] ?? [] as $nameScopedUsage) {
            $usages[] = $nameScopedUsage;
        }

        return array_values($this->uniqueUsages($usages));
    }

    /**
     * Returns an iterator over parameter usages grouped by target parameter hash.
     *
     * @return \Traversable<string, list<ParameterUsage>>
     */
    public function getIterator(): \Traversable
    {
        foreach ($this->byTarget as $target => $usages) {
            yield $target => array_values($usages);
        }
    }

    /**
     * Counts all parameter usages.
     */
    public function count(): int
    {
        $count = 0;

        foreach ($this->byTarget as $usages) {
            $count += count($usages);
        }

        return $count;
    }

    /**
     * Removes duplicate usages when exact and name-scoped hashes point to the same object.
     *
     * @param list<ParameterUsage> $usages the usages to de-duplicate
     *
     * @return list<ParameterUsage>
     */
    private function uniqueUsages(array $usages): array
    {
        $uniqueUsages = [];
        $seen = [];

        foreach ($usages as $usage) {
            $usageKey = spl_object_id($usage);

            if (isset($seen[$usageKey])) {
                continue;
            }

            $seen[$usageKey] = true;
            $uniqueUsages[] = $usage;
        }

        return $uniqueUsages;
    }
}
