<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\RegexService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegexServiceCacheTest extends TestCase
{
    public function test_repeat_lookups_for_a_group_are_served_in_process(): void
    {
        config(['nntmux.cache_expiry_long' => 15]);
        Cache::shouldReceive('get')->once()->andReturn([
            (object) ['id' => 7, 'regex' => '/^(?P<name>[a-z]+)\.part\d+/i'],
        ]);
        Cache::shouldReceive('put')->never();
        DB::shouldReceive('select')->never();

        $regexes = new RegexService('collection_regexes');

        self::assertSame('alpha', $regexes->tryRegex('alpha.part01.rar', 'alt.binaries.test'));
        self::assertSame('beta', $regexes->tryRegex('beta.part02.rar', 'alt.binaries.test'));
        self::assertSame(7, $regexes->matchedRegex);
    }

    public function test_a_database_miss_is_cached_for_the_configured_lifetime(): void
    {
        config(['nntmux.cache_expiry_long' => 15]);
        $rows = [(object) ['id' => 3, 'regex' => '/^(?P<name>[a-z]+)/i']];
        Cache::shouldReceive('get')->once()->andReturnNull();
        DB::shouldReceive('select')->once()->andReturn($rows);
        Cache::shouldReceive('put')->once()->withArgs(
            fn (string $key, array $value, $expiresAt): bool => $value === $rows
                && $expiresAt->greaterThan(now()->addMinutes(14)),
        );

        $regexes = new RegexService('collection_regexes');

        self::assertSame('gamma', $regexes->tryRegex('gamma', 'alt.binaries.test'));
        self::assertSame('delta', $regexes->tryRegex('delta', 'alt.binaries.test'));
    }

    public function test_empty_cache_expiry_env_values_fall_back_to_defaults(): void
    {
        $keys = ['CACHE_EXPIRY_SHORT', 'CACHE_EXPIRY_MEDIUM', 'CACHE_EXPIRY_LONG'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = '';
            putenv($key.'=');
        }

        try {
            $config = require base_path('config/nntmux.php');

            self::assertSame(5, $config['cache_expiry_short']);
            self::assertSame(10, $config['cache_expiry_medium']);
            self::assertSame(15, $config['cache_expiry_long']);

            $_ENV['CACHE_EXPIRY_LONG'] = $_SERVER['CACHE_EXPIRY_LONG'] = '0';
            putenv('CACHE_EXPIRY_LONG=0');
            $config = require base_path('config/nntmux.php');

            self::assertSame(0, $config['cache_expiry_long']);
        } finally {
            foreach ($saved as $key => [$env, $server, $put]) {
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                putenv($put === false ? $key : $key.'='.$put);
            }
        }
    }
}
