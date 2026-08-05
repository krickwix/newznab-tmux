<?php

declare(strict_types=1);

namespace Tests\Unit\Movies;

use App\Services\ImdbScraper;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * OMDB reports a spent daily allowance as HTTP 401 with
 * {"Response":"False","Error":"Request limit reached!"} -- not as 429.
 *
 * That matters because IMDb HTML is WAF-blocked, so EVERY movie lookup falls
 * through to OMDB. Once the quota is gone the fleet was calling an API that had
 * explicitly told it to stop, on every single title, logging
 * `omdb_fallback_http_failure` each time. With the classics archives running
 * that is thousands of refused calls an hour and a good way to get a key
 * banned.
 *
 * Observed live: 401, {"Response":"False","Error":"Request limit reached!"},
 * while movie identification carried on via TMDb.
 */
final class OmdbQuotaCooldownTest extends TestCase
{
    private function detects(Response $response): bool
    {
        $method = new ReflectionMethod(ImdbScraper::class, 'omdbReportsExhaustedQuota');

        /** @var bool $result */
        $result = $method->invoke(
            (new \ReflectionClass(ImdbScraper::class))->newInstanceWithoutConstructor(),
            $response,
        );

        return $result;
    }

    /**
     * @return array<string, array{0: Response, 1: bool}>
     */
    public static function responses(): array
    {
        return [
            'the live 401' => [
                new Response(401, [], '{"Response":"False","Error":"Request limit reached!"}'),
                true,
            ],
            'lowercased' => [
                new Response(401, [], '{"Response":"False","Error":"request limit reached!"}'),
                true,
            ],
            'non-json body still matched' => [
                new Response(429, [], 'Request limit reached!'),
                true,
            ],
            // A bad key is NOT an exhausted quota: cooling down for an hour on
            // one would hide a misconfiguration behind a plausible-looking
            // pause.
            'invalid key' => [
                new Response(401, [], '{"Response":"False","Error":"Invalid API key!"}'),
                false,
            ],
            'server error' => [new Response(500, [], 'upstream failure'), false],
            'empty body' => [new Response(401, [], ''), false],
        ];
    }

    #[DataProvider('responses')]
    public function test_only_an_exhausted_quota_is_detected(Response $response, bool $expected): void
    {
        self::assertSame($expected, $this->detects($response));
    }

    public function test_the_cooldown_suppresses_further_calls_and_is_configurable(): void
    {
        Cache::flush();
        config()->set('nntmux_api.omdb_cooldown_seconds', 3600);

        $scraper = (new \ReflectionClass(ImdbScraper::class))->newInstanceWithoutConstructor();
        $activate = new ReflectionMethod(ImdbScraper::class, 'activateOmdbCooldown');

        $key = (new \ReflectionClass(ImdbScraper::class))->getConstant('OMDB_COOLDOWN_CACHE_KEY');
        self::assertFalse(Cache::has($key), 'no cooldown before the quota is hit');

        $activate->invoke($scraper);

        self::assertTrue(Cache::has($key), 'the cooldown is what stops the next lookup calling OMDB');
    }

    public function test_a_zero_cooldown_disables_the_pause(): void
    {
        Cache::flush();
        config()->set('nntmux_api.omdb_cooldown_seconds', 0);

        $scraper = (new \ReflectionClass(ImdbScraper::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(ImdbScraper::class, 'activateOmdbCooldown'))->invoke($scraper);

        $key = (new \ReflectionClass(ImdbScraper::class))->getConstant('OMDB_COOLDOWN_CACHE_KEY');
        self::assertFalse(Cache::has($key), '0 must mean "never pause", as it does for imdbapi.dev');
    }
}
