<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\ReleaseRemoverService;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class ReleaseRemoverBlacklistQueryTest extends TestCase
{
    /**
     * `buildBlacklistRegexSQL()` already opens the WHERE clause, so the
     * search-result id scope must extend it with AND. Emitting a second WHERE
     * produced `... WHERE r.searchname REGEXP 'x' WHERE r.id IN (...)`, which
     * MariaDB rejects with SQLSTATE[42000] 1064 — silently disabling every
     * subject blacklist rule whose search returned hits.
     */
    public function test_blacklist_subject_query_keeps_a_single_where_clause(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();
        DB::connection()->getPdo()->sqliteCreateFunction(
            'REGEXP',
            static function (?string $pattern, ?string $subject): int {
                if ($pattern === null || $subject === null || $pattern === '') {
                    return 0;
                }

                set_error_handler(static fn (): true => true);
                $ok = @preg_match('~'.$pattern.'~', $subject);
                restore_error_handler();

                return $ok === 1 ? 1 : 0;
            },
            2
        );
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name TEXT)');
        DB::statement("INSERT INTO usenet_groups (id, name) VALUES (1, 'alt.binaries.test')");

        // The bug only surfaced when the subject search returned hits, so the
        // search must be stubbed to reach the id-scope branch at all.
        Search::shouldReceive('searchReleases')->once()->andReturn([11, 22]);

        $service = new class extends ReleaseRemoverService
        {
            public string $capturedQuery = '';

            protected function checkSelectQuery(): bool
            {
                // Capture the assembled SQL instead of executing a delete.
                $this->capturedQuery = $this->query;

                return false;
            }
        };

        // Force the search-result branch that appended the second WHERE.
        (new ReflectionProperty(ReleaseRemoverService::class, 'crapTime'))->setValue($service, '');

        $method = new ReflectionMethod(ReleaseRemoverService::class, 'processBlacklistRegex');
        $method->invoke($service, (object) [
            'id' => 7,
            'regex' => 'spam',
            'groupname' => 'alt.binaries.test',
            'msgcol' => 1,
        ]);

        self::assertNotSame('', $service->capturedQuery, 'The blacklist query was never assembled.');
        self::assertSame(
            1,
            preg_match_all('/\bWHERE\b/i', $service->capturedQuery),
            'The blacklist query must contain exactly one WHERE clause: '.$service->capturedQuery,
        );
        self::assertStringNotContainsString('WHERE r.id IN', $service->capturedQuery);
    }
}
