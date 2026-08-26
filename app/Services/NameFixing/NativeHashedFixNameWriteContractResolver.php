<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Services\Categorization\CategorizationService;
use InvalidArgumentException;

class NativeHashedFixNameWriteContractResolver
{
    private const CATEGORY_VALUE_SOURCE = 'CategorizationService.determineCategory(groups_id, new_title, fromname)';

    private const RELEASE_UPDATE_COLUMNS = [
        'anidbid' => true,
        'bookinfo_id' => true,
        'categories_id' => true,
        'consoleinfo_id' => true,
        'imdbid' => true,
        'iscategorized' => true,
        'isrenamed' => true,
        'musicinfo_id' => true,
        'predb_id' => true,
        'proc_crc32' => true,
        'proc_files' => true,
        'proc_hash16k' => true,
        'proc_nfo' => true,
        'proc_par2' => true,
        'proc_sorter' => true,
        'proc_srr' => true,
        'proc_uid' => true,
        'searchname' => true,
        'tv_episodes_id' => true,
        'videos_id' => true,
    ];

    private const SINGLE_COLUMN_UPDATE_COLUMNS = [
        'proc_crc32' => true,
        'proc_hash16k' => true,
    ];

    public function __construct(private readonly CategorizationService $categorization) {}

    /**
     * Resolve PHP-owned side-effect values from a native hashed fix-name write contract.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolve(array $payload): array
    {
        $contract = $this->extractWriteContract($payload);
        $releaseUpdates = $this->arrayList($contract['release_updates'] ?? []);
        $singleColumnUpdates = $this->arrayList($contract['single_column_updates'] ?? []);
        $requiredEvents = $this->arrayList($contract['required_events'] ?? []);
        $searchUpdates = $this->arrayList($contract['search_updates'] ?? []);

        $eventsByRelease = $this->indexByReleaseId($requiredEvents);
        $searchByRelease = $this->groupByReleaseId($searchUpdates);
        $resolved = [];
        $blocked = [];

        foreach ($releaseUpdates as $releaseUpdate) {
            $releaseId = $this->releaseId($releaseUpdate);
            $event = $eventsByRelease[$releaseId] ?? null;
            $releaseSearchUpdates = $searchByRelease[$releaseId] ?? [];

            if ($event === null) {
                $blocked[] = [
                    'release_id' => $releaseId,
                    'reason' => 'missing-required-event-context',
                ];

                continue;
            }

            if ($releaseSearchUpdates === []) {
                $blocked[] = [
                    'release_id' => $releaseId,
                    'reason' => 'missing-search-update-context',
                ];

                continue;
            }

            $categoryColumn = $this->categoryColumn($releaseUpdate);
            $valueSource = (string) ($categoryColumn['value_source'] ?? '');

            if ($categoryColumn === [] || $valueSource === '') {
                $blocked[] = [
                    'release_id' => $releaseId,
                    'reason' => 'missing-category-value-source',
                ];

                continue;
            }

            if ($valueSource !== self::CATEGORY_VALUE_SOURCE) {
                $blocked[] = [
                    'release_id' => $releaseId,
                    'reason' => 'unsupported-category-value-source',
                    'value_source' => $valueSource,
                ];

                continue;
            }

            $newName = (string) ($event['new_name'] ?? $this->columnValue($releaseUpdate, 'searchname', ''));
            $poster = (string) ($event['poster'] ?? '');
            $groupId = $this->normalizeScalar($event['group_id'] ?? 0);
            $categoryResult = $this->categorization->determineCategory($groupId, $newName, $poster);
            $categoryId = (int) ($categoryResult['categories_id'] ?? 0);

            $resolved[] = [
                'release_id' => $releaseId,
                'type' => (string) ($releaseUpdate['type'] ?? ''),
                'method' => (string) ($releaseUpdate['method'] ?? ''),
                'match_source' => (string) ($releaseUpdate['match_source'] ?? ''),
                'columns' => $this->resolvedColumns($releaseUpdate, $categoryId),
                'category_resolution' => [
                    'group_id' => $groupId,
                    'new_name' => $newName,
                    'poster_present' => $poster !== '',
                    'categories_id' => $categoryId,
                    'value_source' => $valueSource,
                ],
                'required_event' => [
                    'release_id' => $releaseId,
                    'old_name' => (string) ($event['old_name'] ?? ''),
                    'new_name' => $newName,
                    'old_category_id' => (int) ($event['old_category_id'] ?? 0),
                    'new_category_id' => $categoryId,
                    'group_id' => $groupId,
                    'poster_present' => $poster !== '',
                ],
                'required_search_updates' => $this->searchUpdateIntents($releaseSearchUpdates),
            ];
        }

        return [
            'schema_version' => 1,
            'mode' => 'native-write-contract-resolve',
            'dry_run' => true,
            'writes' => 0,
            'write_contract' => [
                'release_updates_seen' => count($releaseUpdates),
                'release_updates_resolved' => count($resolved),
                'release_updates_blocked' => count($blocked),
                'resolved_release_updates' => $resolved,
                'blocked_release_updates' => $blocked,
                'single_column_updates_seen' => count($singleColumnUpdates),
                'single_column_update_intents' => array_map([$this, 'singleColumnUpdateIntent'], $singleColumnUpdates),
                'required_events' => count($requiredEvents),
                'required_search_updates' => count($searchUpdates),
                'category_resolution_required' => (int) ($contract['category_resolution_required'] ?? 0),
                'writes' => 0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractWriteContract(array $payload): array
    {
        $nested = $payload['hashed_fixnames']['write_contract'] ?? null;
        if (is_array($nested)) {
            $this->assertReadOnlyContract($nested);

            return $nested;
        }

        if (array_key_exists('release_updates', $payload)) {
            $this->assertReadOnlyContract($payload);

            return $payload;
        }

        throw new InvalidArgumentException('Native write contract JSON is missing hashed_fixnames.write_contract.');
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function assertReadOnlyContract(array $contract): void
    {
        if (! array_key_exists('writes', $contract) || $contract['writes'] !== 0) {
            throw new InvalidArgumentException('Native write contract must be read-only with writes=0.');
        }
    }

    /**
     * @param  mixed  $value
     * @return list<array<string, mixed>>
     */
    private function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function indexByReleaseId(array $items): array
    {
        $indexed = [];

        foreach ($items as $item) {
            $indexed[$this->releaseId($item)] = $item;
        }

        return $indexed;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupByReleaseId(array $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $grouped[$this->releaseId($item)][] = $item;
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function releaseId(array $item): int
    {
        return (int) ($item['release_id'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $releaseUpdate
     * @return array<string, mixed>
     */
    private function categoryColumn(array $releaseUpdate): array
    {
        foreach ($this->arrayList($releaseUpdate['columns'] ?? []) as $column) {
            if (($column['column'] ?? '') === 'categories_id') {
                return $column;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $releaseUpdate
     * @return mixed
     */
    private function columnValue(array $releaseUpdate, string $columnName, mixed $default): mixed
    {
        foreach ($this->arrayList($releaseUpdate['columns'] ?? []) as $column) {
            if (($column['column'] ?? '') === $columnName) {
                return $column['value'] ?? $default;
            }
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $releaseUpdate
     * @return list<array<string, mixed>>
     */
    private function resolvedColumns(array $releaseUpdate, int $categoryId): array
    {
        return array_values(array_map(
            static function (array $column) use ($categoryId): array {
                if (($column['column'] ?? '') === 'categories_id') {
                    $column['value'] = $categoryId;
                }

                return $column;
            },
            array_filter(
                $this->arrayList($releaseUpdate['columns'] ?? []),
                static fn (array $column): bool => isset(self::RELEASE_UPDATE_COLUMNS[(string) ($column['column'] ?? '')]),
            ),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @return list<array<string, mixed>>
     */
    private function searchUpdateIntents(array $updates): array
    {
        return array_map(
            fn (array $update): array => [
                'release_id' => $this->releaseId($update),
                'reason' => (string) ($update['reason'] ?? ''),
            ],
            $updates,
        );
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>
     */
    private function singleColumnUpdateIntent(array $update): array
    {
        $column = (string) ($update['column'] ?? '');

        return [
            'release_id' => $this->releaseId($update),
            'column' => isset(self::SINGLE_COLUMN_UPDATE_COLUMNS[$column]) ? $column : 'unsupported',
            'value' => (int) ($update['value'] ?? 0),
            'reason' => (string) ($update['reason'] ?? ''),
        ];
    }

    private function normalizeScalar(mixed $value): int|string
    {
        if (is_int($value) || is_string($value)) {
            return is_numeric($value) ? (int) $value : $value;
        }

        return 0;
    }
}
