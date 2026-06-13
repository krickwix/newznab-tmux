<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\Category;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class NntmuxPrometheusMetrics
{
    private const TABLES = [
        'binaries',
        'collections',
        'missed_parts',
        'parts',
        'releases',
        'predb',
        'predb_crcs',
        'usenet_groups',
    ];

    private const TIMER_SETTINGS = [
        'bins_timer',
        'back_timer',
        'rel_timer',
        'fix_timer',
        'crap_timer',
        'post_timer',
        'post_timer_non',
        'post_timer_amazon',
        'metadata_refresh_timer',
        'seq_timer',
    ];

    public function render(): string
    {
        $lines = [
            '# HELP nntmux_metric_scrape_success Whether the NNTmux metrics scrape completed successfully.',
            '# TYPE nntmux_metric_scrape_success gauge',
        ];

        try {
            $lines = array_merge(
                $lines,
                $this->tableEstimateMetrics(),
                $this->groupMetrics(),
                $this->releaseMetrics(),
                $this->externalMetadataMetrics(),
                $this->imdbLookupMetrics(),
                $this->timerMetrics(),
                $this->lockMetrics(),
            );
            $lines[] = 'nntmux_metric_scrape_success 1';
        } catch (Throwable $e) {
            $lines[] = 'nntmux_metric_scrape_success 0';
            $lines[] = $this->metric('nntmux_metric_scrape_error', 1, [
                'class' => $e::class,
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function imdbLookupMetrics(): array
    {
        $lines = [
            '# HELP nntmux_imdb_lookup_total IMDb lookup attempts by outcome and reason since cache start.',
            '# TYPE nntmux_imdb_lookup_total counter',
        ];

        try {
            $connectionName = (string) config('cache.stores.redis.connection', 'cache');
            foreach ($this->scanRedisKeys($connectionName, $this->redisPhysicalPattern('metrics:imdb_lookup*')) as $key) {
                $key = (string) $key;
                $labels = $this->parseImdbMetricLabels($key);
                if ($labels === null) {
                    continue;
                }

                $lines[] = $this->metric('nntmux_imdb_lookup_total', $this->redisValue($key, $connectionName), $labels);
            }
        } catch (Throwable) {
            $lines[] = $this->metric('nntmux_imdb_lookup_total', 0, [
                'outcome' => 'redis_unavailable',
                'reason' => 'redis_unavailable',
                'fallback_reason' => 'redis_unavailable',
                'source' => 'redis_unavailable',
            ]);
        }

        return $lines;
    }

    /**
     * @return array{outcome: string, reason: string, fallback_reason: string, source: string}|null
     */
    private function parseImdbMetricLabels(string $key): ?array
    {
        if (! preg_match(
            '/metrics:imdb_lookup:outcome:(?<outcome>[^:]+):reason:(?<reason>[^:]+):fallback:(?<fallback>[^:]+):source:(?<source>[^:]+)/',
            $key,
            $match,
        )) {
            return null;
        }

        return [
            'outcome' => $match['outcome'],
            'reason' => $match['reason'],
            'fallback_reason' => $match['fallback'],
            'source' => $match['source'],
        ];
    }

    /**
     * @return list<string>
     */
    private function tableEstimateMetrics(): array
    {
        $database = DB::connection()->getDatabaseName();
        $rows = DB::table('information_schema.tables')
            ->select(['table_name', 'table_rows'])
            ->where('table_schema', $database)
            ->whereIn('table_name', self::TABLES)
            ->get();

        $lines = [
            '# HELP nntmux_table_rows_estimate Estimated table row count from information_schema.',
            '# TYPE nntmux_table_rows_estimate gauge',
        ];

        foreach ($rows as $row) {
            $lines[] = $this->metric('nntmux_table_rows_estimate', (float) ($row->table_rows ?? 0), [
                'table' => (string) $row->table_name,
            ]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function groupMetrics(): array
    {
        $active = (int) DB::table('usenet_groups')->where('active', 1)->count();
        $backfill = (int) DB::table('usenet_groups')->where('active', 1)->where('backfill', 1)->count();
        $oldest = DB::table('usenet_groups')
            ->where('active', 1)
            ->where('backfill', 1)
            ->whereNotNull('first_record_postdate')
            ->min('first_record_postdate');

        $lines = [
            '# HELP nntmux_groups_total Usenet group count by state.',
            '# TYPE nntmux_groups_total gauge',
            $this->metric('nntmux_groups_total', $active, ['state' => 'active']),
            $this->metric('nntmux_groups_total', $backfill, ['state' => 'active_backfill']),
            '# HELP nntmux_backfill_oldest_cursor_age_seconds Age of the oldest active backfill first-record cursor.',
            '# TYPE nntmux_backfill_oldest_cursor_age_seconds gauge',
            $this->metric('nntmux_backfill_oldest_cursor_age_seconds', $oldest === null ? 0 : max(0, time() - strtotime((string) $oldest))),
            '# HELP nntmux_group_first_record_postdate_timestamp First-record postdate timestamp for active backfill groups.',
            '# TYPE nntmux_group_first_record_postdate_timestamp gauge',
            '# HELP nntmux_group_first_record Current first article number for active backfill groups.',
            '# TYPE nntmux_group_first_record gauge',
        ];

        $groups = DB::table('usenet_groups')
            ->select(['name', 'first_record', 'first_record_postdate'])
            ->where('active', 1)
            ->where('backfill', 1)
            ->orderByDesc('first_record_postdate')
            ->limit(50)
            ->get();

        foreach ($groups as $group) {
            $labels = ['group' => (string) $group->name];
            $lines[] = $this->metric('nntmux_group_first_record', (float) $group->first_record, $labels);
            $lines[] = $this->metric(
                'nntmux_group_first_record_postdate_timestamp',
                $group->first_record_postdate === null ? 0 : strtotime((string) $group->first_record_postdate),
                $labels,
            );
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function releaseMetrics(): array
    {
        $hour = (int) DB::table('releases')->where('adddate', '>=', now()->subHour())->count();
        $day = (int) DB::table('releases')->where('adddate', '>=', now()->subDay())->count();
        $hasLegacyHashedColumn = Schema::hasColumn('releases', 'ishashed');
        $hashed = $hasLegacyHashedColumn ? (int) DB::table('releases')->where('ishashed', 1)->count() : 0;
        $hashedCategory = (int) DB::table('releases')->where('categories_id', Category::OTHER_HASHED)->count();
        $hashedEffectiveQuery = DB::table('releases');
        if ($hasLegacyHashedColumn) {
            $hashedEffectiveQuery->where(static function ($query): void {
                $query->where('ishashed', 1)
                    ->orWhere('categories_id', Category::OTHER_HASHED);
            });
        } else {
            $hashedEffectiveQuery->where('categories_id', Category::OTHER_HASHED);
        }
        $hashedEffective = (int) $hashedEffectiveQuery->count();
        $renamed = (int) DB::table('releases')->where('isrenamed', 1)->count();

        return [
            '# HELP nntmux_releases_recent_total Releases added in a recent time window.',
            '# TYPE nntmux_releases_recent_total gauge',
            $this->metric('nntmux_releases_recent_total', $hour, ['window' => '1h']),
            $this->metric('nntmux_releases_recent_total', $day, ['window' => '24h']),
            '# HELP nntmux_releases_state_total Release count by processing state.',
            '# TYPE nntmux_releases_state_total gauge',
            $this->metric('nntmux_releases_state_total', $hashed, ['state' => 'hashed']),
            $this->metric('nntmux_releases_state_total', $hashedCategory, ['state' => 'hashed_category']),
            $this->metric('nntmux_releases_state_total', $hashedEffective, ['state' => 'hashed_effective']),
            $this->metric('nntmux_releases_state_total', $renamed, ['state' => 'renamed']),
            ...$this->releaseCategoryMetrics(),
        ];
    }

    /**
     * @return list<string>
     */
    private function releaseCategoryMetrics(): array
    {
        $rows = DB::table('releases AS r')
            ->join('categories AS c', 'c.id', '=', 'r.categories_id')
            ->leftJoin('root_categories AS rc', 'rc.id', '=', 'c.root_categories_id')
            ->selectRaw('r.categories_id, c.title AS category, rc.title AS root_category, COUNT(*) AS releases_count')
            ->groupBy('r.categories_id', 'c.title', 'rc.title')
            ->orderBy('rc.title')
            ->orderBy('c.title')
            ->get();

        $lines = [
            '# HELP nntmux_releases_category_total Current release count by NNTmux category.',
            '# TYPE nntmux_releases_category_total gauge',
        ];

        foreach ($rows as $row) {
            $rootCategory = (string) ($row->root_category ?? 'Uncategorized');
            $category = (string) $row->category;

            $lines[] = $this->metric('nntmux_releases_category_total', (int) $row->releases_count, [
                'category_id' => (string) $row->categories_id,
                'root_category' => $rootCategory,
                'category' => $category,
                'category_path' => $rootCategory.' / '.$category,
            ]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function externalMetadataMetrics(): array
    {
        $lines = [
            '# HELP nntmux_external_metadata_total External metadata evidence rows and backlog counts.',
            '# TYPE nntmux_external_metadata_total gauge',
        ];

        if (Schema::hasTable('predb_crcs')) {
            $lines[] = $this->metric('nntmux_external_metadata_total', (int) DB::table('predb_crcs')->count(), [
                'source' => 'srrdb',
                'state' => 'crc_rows',
            ]);
        }

        if (Schema::hasTable('predb') && Schema::hasTable('predb_crcs')) {
            $withoutCrc = (int) DB::table('predb')
                ->where('source', 'srrdb')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('predb_crcs')
                        ->whereColumn('predb_crcs.predb_id', 'predb.id');
                })
                ->count();

            $lines[] = $this->metric('nntmux_external_metadata_total', $withoutCrc, [
                'source' => 'srrdb',
                'state' => 'predb_without_crc',
            ]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function timerMetrics(): array
    {
        $settings = DB::table('settings')
            ->whereIn('name', self::TIMER_SETTINGS)
            ->pluck('value', 'name');

        $lines = [
            '# HELP nntmux_worker_sleep_seconds Configured distributed worker sleep setting.',
            '# TYPE nntmux_worker_sleep_seconds gauge',
        ];

        foreach (self::TIMER_SETTINGS as $setting) {
            if (! $settings->has($setting)) {
                continue;
            }

            $lines[] = $this->metric('nntmux_worker_sleep_seconds', (float) $settings[$setting], [
                'setting' => $setting,
            ]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function lockMetrics(): array
    {
        $lines = [
            '# HELP nntmux_worker_lock_ttl_seconds Redis distributed worker lock TTL.',
            '# TYPE nntmux_worker_lock_ttl_seconds gauge',
        ];

        try {
            $prefix = (string) config('cache.prefix');
            $connectionName = (string) (config('cache.stores.redis.lock_connection')
                ?: config('cache.stores.redis.connection', 'default')
                ?: 'default');
            foreach ($this->scanRedisKeys($connectionName, $this->redisPhysicalPattern('nntmux:distributed-worker:*')) as $key) {
                $key = (string) $key;
                $job = preg_replace('/^.*distributed-worker:/', '', $key) ?: $key;
                $ttl = $this->redisTtl($key, $prefix, $connectionName);

                $lines[] = $this->metric('nntmux_worker_lock_ttl_seconds', $ttl, [
                    'job' => $job,
                    'prefix' => $prefix,
                ]);
            }
        } catch (Throwable) {
            $lines[] = $this->metric('nntmux_worker_lock_ttl_seconds', -1, [
                'job' => 'redis_unavailable',
                'prefix' => '',
            ]);
        }

        return $lines;
    }

    private function redisTtl(string $key, string $prefix, string $connectionName): int
    {
        foreach ($this->redisKeyCandidates($key, $prefix) as $candidate) {
            $ttl = (int) Redis::connection($connectionName)->ttl($candidate);
            if ($ttl !== -2) {
                return $ttl;
            }
        }

        return -2;
    }

    private function redisValue(string $key, string $connectionName): int
    {
        foreach ($this->redisKeyCandidates($key, (string) config('cache.prefix')) as $candidate) {
            $value = Redis::connection($connectionName)->get($candidate);
            if ($value !== null) {
                return (int) $value;
            }
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private function scanRedisKeys(string $connectionName, string $pattern, int $count = 1000): array
    {
        $connection = Redis::connection($connectionName);
        $defaultCursor = $this->defaultRedisScanCursor($connection);
        $cursor = $defaultCursor;
        $keys = [];
        $iterations = 0;

        do {
            // Laravel's Redis connection exposes SCAN dynamically with the same options shape used by RedisStore.
            /** @phpstan-ignore-next-line */
            $scanResult = $connection->scan($cursor, ['match' => $pattern, 'count' => $count]);
            if (! is_array($scanResult)) {
                break;
            }

            [$cursor, $chunk] = $scanResult;
            if (! is_array($chunk)) {
                break;
            }

            foreach ($chunk as $key) {
                $keys[] = (string) $key;
            }

            $iterations++;
        } while (! $this->redisScanComplete($cursor, $defaultCursor) && $iterations < 1000);

        return array_values(array_unique($keys));
    }

    private function redisPhysicalPattern(string $logicalPattern): string
    {
        return (string) config('database.redis.options.prefix').(string) config('cache.prefix').$logicalPattern;
    }

    private function defaultRedisScanCursor(mixed $connection): mixed
    {
        return $connection instanceof PhpRedisConnection && version_compare((string) phpversion('redis'), '6.1.0', '>=')
            ? null
            : '0';
    }

    private function redisScanComplete(mixed $cursor, mixed $defaultCursor): bool
    {
        if ($defaultCursor === null) {
            return $cursor === null || $cursor === 0 || $cursor === '0';
        }

        return (string) $cursor === (string) $defaultCursor;
    }

    /**
     * Redis::keys() returns the physical key with the Redis connection prefix,
     * while Redis::ttl() applies that prefix before lookup.
     *
     * @return list<string>
     */
    private function redisKeyCandidates(string $key, string $cachePrefix): array
    {
        $candidates = [$key];
        $redisPrefix = (string) config('database.redis.options.prefix');

        foreach ([$redisPrefix, $cachePrefix, $redisPrefix.$cachePrefix] as $prefix) {
            if ($prefix !== '' && str_starts_with($key, $prefix)) {
                $candidates[] = substr($key, strlen($prefix));
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function metric(string $name, float|int $value, array $labels = []): string
    {
        if ($labels === []) {
            return sprintf('%s %s', $name, $this->formatValue($value));
        }

        $pairs = [];
        foreach ($labels as $key => $label) {
            $pairs[] = sprintf('%s="%s"', $key, $this->escapeLabel($label));
        }

        return sprintf('%s{%s} %s', $name, implode(',', $pairs), $this->formatValue($value));
    }

    private function formatValue(float|int $value): string
    {
        return is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) $value;
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }
}
