<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class NativeHashedFixNameRenameApplier
{
    /**
     * Apply PHP-owned rename side effects from a resolved native write contract.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload, ?ReleaseUpdateService $releaseUpdateService = null): array
    {
        if (! (bool) config('nntmux.native_worker_rename_apply_test_enabled', false)) {
            throw new InvalidArgumentException('Native rename apply requires NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED=1.');
        }

        $contract = $this->resolvedContract($payload);
        $resolvedUpdates = $this->arrayList($contract['resolved_release_updates'] ?? []);

        if ((int) ($contract['release_updates_blocked'] ?? 0) > 0 || $this->arrayList($contract['blocked_release_updates'] ?? []) !== []) {
            throw new InvalidArgumentException('Native rename apply requires all release updates to be resolved.');
        }

        if ((int) ($contract['release_updates_resolved'] ?? -1) !== count($resolvedUpdates)) {
            throw new InvalidArgumentException('Native rename apply resolved release update count does not match release_updates_resolved.');
        }

        if ((int) ($contract['release_updates_seen'] ?? -1) !== count($resolvedUpdates)) {
            throw new InvalidArgumentException('Native rename apply resolved release update count does not match release_updates_seen.');
        }

        $this->validateResolvedSideEffectCounts($contract, $resolvedUpdates);

        $preparedUpdates = $this->prepareUpdates($resolvedUpdates);
        $releaseUpdateService ??= app(ReleaseUpdateService::class);

        $appliedIds = [];
        foreach ($preparedUpdates as $preparedUpdate) {
            try {
                $releaseUpdateService->updateRelease(
                    $preparedUpdate['release'],
                    $preparedUpdate['new_name'],
                    $preparedUpdate['method'],
                    true,
                    $preparedUpdate['type'],
                    true,
                    false,
                    $preparedUpdate['predb_id'],
                );
                $appliedIds[] = (int) $preparedUpdate['release_id'];
            } catch (Throwable $exception) {
                throw new InvalidArgumentException(
                    $this->releaseUpdateFailureMessage((int) $preparedUpdate['release_id'], $appliedIds),
                    previous: $exception,
                );
            }
        }

        $ids = array_map(static fn (array $update): int => (int) $update['release_id'], $preparedUpdates);
        sort($ids, SORT_NUMERIC);

        return [
            'schema_version' => 1,
            'mode' => 'native-hashed-fixname-rename-apply',
            'dry_run' => false,
            'source_job' => 'hashed-fixnames',
            'release_updates_seen' => count($resolvedUpdates),
            'release_updates_applied' => count($preparedUpdates),
            'release_ids' => $ids,
            'writes' => count($preparedUpdates),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolvedContract(array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== 1
            || ($payload['mode'] ?? null) !== 'native-write-contract-resolve'
            || ($payload['dry_run'] ?? null) !== true
            || ($payload['writes'] ?? null) !== 0) {
            throw new InvalidArgumentException('Native rename apply requires a resolved read-only native write contract.');
        }

        $contract = $payload['write_contract'] ?? null;
        if (! is_array($contract) || ($contract['writes'] ?? null) !== 0) {
            throw new InvalidArgumentException('Native rename apply requires write_contract.writes=0.');
        }

        return $contract;
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @return list<array{release_id: int, release: object, new_name: string, method: string, type: string, predb_id: int}>
     */
    private function prepareUpdates(array $updates): array
    {
        $releaseIds = [];
        $preparedUpdates = [];

        foreach ($updates as $update) {
            $releaseId = $this->releaseId($update);
            if ($releaseId <= 0) {
                throw new InvalidArgumentException('Native rename apply release IDs must be positive integers.');
            }

            if (isset($releaseIds[$releaseId])) {
                throw new InvalidArgumentException("Native rename apply duplicate release ID [{$releaseId}].");
            }
            $releaseIds[$releaseId] = true;

            $preparedUpdates[] = $this->prepareUpdate($update, $releaseId);
        }

        return $preparedUpdates;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  list<array<string, mixed>>  $resolvedUpdates
     */
    private function validateResolvedSideEffectCounts(array $contract, array $resolvedUpdates): void
    {
        $requiredEvents = 0;
        $requiredSearchUpdates = 0;
        $categoryResolutions = 0;

        foreach ($resolvedUpdates as $update) {
            $releaseId = $this->releaseId($update);
            $event = $update['required_event'] ?? null;
            if (is_array($event)) {
                if ($this->releaseId($event) !== $releaseId) {
                    throw new InvalidArgumentException("Native rename apply release [{$releaseId}] has mismatched required event context.");
                }

                $requiredEvents++;
            }

            foreach ($this->arrayList($update['required_search_updates'] ?? []) as $searchUpdate) {
                if ($this->releaseId($searchUpdate) !== $releaseId) {
                    throw new InvalidArgumentException("Native rename apply release [{$releaseId}] has mismatched search update context.");
                }

                $requiredSearchUpdates++;
            }

            $categoryResolution = $update['category_resolution'] ?? null;
            if (is_array($categoryResolution)) {
                if (array_key_exists('group_id', $categoryResolution)
                    && is_array($event)
                    && (int) $categoryResolution['group_id'] !== (int) ($event['group_id'] ?? -1)) {
                    throw new InvalidArgumentException("Native rename apply release [{$releaseId}] has mismatched category resolution context.");
                }

                if (array_key_exists('new_name', $categoryResolution)
                    && is_array($event)
                    && (string) $categoryResolution['new_name'] !== (string) ($event['new_name'] ?? '')) {
                    throw new InvalidArgumentException("Native rename apply release [{$releaseId}] has mismatched category resolution context.");
                }

                if (array_key_exists('poster_present', $categoryResolution)
                    && is_array($event)
                    && (bool) $categoryResolution['poster_present'] !== (bool) ($event['poster_present'] ?? false)) {
                    throw new InvalidArgumentException("Native rename apply release [{$releaseId}] has mismatched category resolution context.");
                }

                if (array_key_exists('categories_id', $categoryResolution)
                    && is_array($event)
                    && (int) $categoryResolution['categories_id'] !== (int) ($event['new_category_id'] ?? -1)) {
                    throw new InvalidArgumentException("Native rename apply release [{$releaseId}] has mismatched category resolution context.");
                }

                $categoryResolutions++;
            }
        }

        if ((int) ($contract['required_events'] ?? -1) !== $requiredEvents) {
            throw new InvalidArgumentException('Native rename apply required event count does not match resolved release updates.');
        }

        if ((int) ($contract['required_search_updates'] ?? -1) < $requiredSearchUpdates) {
            throw new InvalidArgumentException('Native rename apply required search update count is lower than resolved release updates.');
        }

        if ((int) ($contract['category_resolution_required'] ?? -1) !== $categoryResolutions) {
            throw new InvalidArgumentException('Native rename apply category resolution count does not match resolved release updates.');
        }
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array{release_id: int, release: object, new_name: string, method: string, type: string, predb_id: int}
     */
    private function prepareUpdate(array $update, int $releaseId): array
    {
        $event = $update['required_event'] ?? null;
        if (! is_array($event)) {
            throw new InvalidArgumentException("Native rename apply release [{$releaseId}] is missing required event context.");
        }

        $newName = $this->requiredString($event, 'new_name', $releaseId);
        $oldName = $this->requiredString($event, 'old_name', $releaseId);
        $oldCategoryId = (int) ($event['old_category_id'] ?? -1);
        $type = $this->requiredString($update, 'type', $releaseId);
        $method = $this->requiredString($update, 'method', $releaseId);

        if (! in_array($type, ['CRC32, ', 'PAR2 hash, '], true)) {
            throw new InvalidArgumentException("Native rename apply release [{$releaseId}] has unsupported type.");
        }

        if ($method === '') {
            throw new InvalidArgumentException("Native rename apply release [{$releaseId}] has unsupported method.");
        }

        if ($this->arrayList($update['required_search_updates'] ?? []) === []) {
            throw new InvalidArgumentException("Native rename apply release [{$releaseId}] is missing search update context.");
        }

        $release = DB::table('releases')
            ->where('id', $releaseId)
            ->first([
                'id',
                'name',
                'searchname',
                'groups_id',
                'fromname',
                'categories_id',
                'predb_id',
                'isrenamed',
                'iscategorized',
            ]);

        if ($release === null
            || (string) $release->searchname !== $oldName
            || (int) $release->categories_id !== $oldCategoryId) {
            throw new InvalidArgumentException("Native rename apply release [{$releaseId}] is stale.");
        }

        $release->releases_id = (int) $release->id;

        return [
            'release_id' => $releaseId,
            'release' => $release,
            'new_name' => $newName,
            'method' => $method,
            'type' => $type,
            'predb_id' => $this->intColumnValue($update, 'predb_id'),
        ];
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
     * @param  array<string, mixed>  $item
     */
    private function releaseId(array $item): int
    {
        return (int) ($item['release_id'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key, int $releaseId): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Native rename apply release [{$releaseId}] is missing {$key}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function intColumnValue(array $update, string $columnName): int
    {
        foreach ($this->arrayList($update['columns'] ?? []) as $column) {
            if (($column['column'] ?? null) !== $columnName) {
                continue;
            }

            return (int) ($column['value'] ?? 0);
        }

        return 0;
    }

    /**
     * @param  list<int>  $appliedIds
     */
    private function releaseUpdateFailureMessage(int $releaseId, array $appliedIds): string
    {
        if ($appliedIds === []) {
            return "Native rename apply release [{$releaseId}] failed.";
        }

        return sprintf(
            'Native rename apply release [%d] failed after applying release IDs [%s].',
            $releaseId,
            implode(',', $appliedIds),
        );
    }
}
