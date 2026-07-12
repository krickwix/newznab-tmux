<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

final readonly class BodyRecoverySourceCriteria
{
    /**
     * @param  list<int>  $groupIds
     * @param  list<int>  $regexIds
     */
    public function __construct(
        public array $groupIds,
        public array $regexIds,
        public int $maxCurrentParts,
        public int $minTotalParts,
        public ?string $before,
    ) {}

    /** @return array{sql:string,bindings:list<int|string>} */
    public function identityPredicate(string $collectionAlias = 'c', string $binaryAlias = 'b'): array
    {
        $groupPlaceholders = implode(', ', array_fill(0, count($this->groupIds), '?'));
        $regexPlaceholders = implode(', ', array_fill(0, count($this->regexIds), '?'));

        return [
            'sql' => "{$collectionAlias}.groups_id IN ({$groupPlaceholders})
                AND {$collectionAlias}.filecheck = 0
                AND {$collectionAlias}.releases_id IS NULL
                AND {$collectionAlias}.totalfiles > 1
                AND {$collectionAlias}.collection_regexes_id IN ({$regexPlaceholders})
                AND {$collectionAlias}.subject LIKE '[PRiVATE]%[newzNZB]%'
                AND NOT EXISTS (
                    SELECT 1 FROM binaries b2
                    WHERE b2.collections_id = {$collectionAlias}.id
                    AND b2.id <> {$binaryAlias}.id
                )",
            'bindings' => [
                ...$this->groupIds,
                ...$this->regexIds,
            ],
        ];
    }

    /** @return array{sql:string,bindings:list<int|string>} */
    public function eligibilityPredicate(string $collectionAlias = 'c', string $binaryAlias = 'b'): array
    {
        $identity = $this->identityPredicate($collectionAlias, $binaryAlias);
        $cutoffSql = $this->before === null || $this->before === '' ? '' : "\n                AND {$collectionAlias}.dateadded < ?";
        $cutoffBindings = $cutoffSql === '' ? [] : [$this->before];

        return [
            'sql' => $identity['sql'].$cutoffSql."
                AND {$binaryAlias}.currentparts <= ?
                AND {$binaryAlias}.totalparts >= ?",
            'bindings' => [
                ...$identity['bindings'],
                ...$cutoffBindings,
                $this->maxCurrentParts,
                $this->minTotalParts,
            ],
        ];
    }
}
