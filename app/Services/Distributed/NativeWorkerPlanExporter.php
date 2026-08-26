<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use Illuminate\Support\Carbon;

class NativeWorkerPlanExporter
{
    /**
     * @param  array{
     *     name: string,
     *     description: string,
     *     enabled: bool,
     *     disabled_reason: string|null,
     *     commands: list<array{command: string, arguments: array<string, mixed>}>,
     *     sleep: int
     * }  $plan
     * @return array{
     *     version: int,
     *     generated_at: string,
     *     mode: string,
     *     job: array{name: string, description: string, enabled: bool, disabled_reason: string|null, sleep: int},
     *     lock: array{name: string, redis_key: string, seconds: int},
     *     commands: list<array{command: string, arguments: array<string, mixed>}>
     * }
     */
    public function export(array $plan, int $lockSeconds, string $mode = 'shadow'): array
    {
        $jobName = $plan['name'];
        $lockName = 'nntmux:distributed-worker:'.$jobName;

        return [
            'version' => 1,
            'generated_at' => Carbon::now()->toISOString(),
            'mode' => $mode,
            'job' => [
                'name' => $jobName,
                'description' => $plan['description'],
                'enabled' => $plan['enabled'],
                'disabled_reason' => $plan['disabled_reason'],
                'sleep' => max(1, $plan['sleep']),
            ],
            'lock' => [
                'name' => $lockName,
                'redis_key' => $this->redisLockKey($lockName),
                'seconds' => max(1, $lockSeconds),
            ],
            'commands' => array_map(
                static fn (array $command): array => [
                    'command' => $command['command'],
                    'arguments' => $command['arguments'],
                ],
                $plan['commands'],
            ),
        ];
    }

    private function redisLockKey(string $lockName): string
    {
        return (string) config('database.redis.options.prefix')
            .(string) config('cache.prefix')
            .$lockName;
    }
}
