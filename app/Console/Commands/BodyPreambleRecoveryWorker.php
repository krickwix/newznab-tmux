<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\MissedPartHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class BodyPreambleRecoveryWorker extends Command
{
    protected $signature = 'nntmux:body-preamble-recovery-worker
                            {group : Exact group name}
                            {--batch=75 : Maximum rows claimed per cohort}
                            {--lease-seconds=180 : Claim lease duration}
                            {--idle-sleep=20 : Seconds between empty or denied polls}
                            {--owner= : Stable worker owner; defaults to HOSTNAME}
                            {--once : Process at most one cohort}';

    protected $description = 'Process explicit BODY-preamble recovery rows through token-fenced cohorts';

    public function handle(BinariesService $binaries, MissedPartHandler $missedParts): int
    {
        $group = UsenetGroup::query()->where('name', (string) $this->argument('group'))->first();
        if ($group === null) {
            $this->error('Unknown Usenet group: '.(string) $this->argument('group'));

            return self::FAILURE;
        }

        $batch = max(1, min(250, (int) $this->option('batch')));
        $leaseSeconds = max(90, min(900, (int) $this->option('lease-seconds')));
        $idleSleep = max(1, min(300, (int) $this->option('idle-sleep')));
        $owner = trim((string) ($this->option('owner') ?: getenv('HOSTNAME') ?: gethostname() ?: 'body-recovery'));
        $once = (bool) $this->option('once');

        do {
            if (! $this->recoveryAdmitted()) {
                $this->emit(['status' => 'denied', 'owner' => $owner]);
                if ($once) {
                    return self::SUCCESS;
                }
                sleep($idleSleep);

                continue;
            }

            $token = (string) Str::uuid();
            $claimed = $missedParts->claimBodyRecoveryParts(
                (int) $group->id,
                $token,
                $owner,
                $batch,
                now()->addSeconds($leaseSeconds),
            );
            if ($claimed === []) {
                $this->emit(['status' => 'idle', 'owner' => $owner]);
                if ($once) {
                    return self::SUCCESS;
                }
                sleep($idleSleep);

                continue;
            }

            $started = microtime(true);
            $summary = $binaries->partRepairClaimedCohort(
                $group->toArray(),
                $claimed,
                $token,
                $leaseSeconds,
            );
            $this->emit([
                'status' => 'processed',
                'owner' => $owner,
                'token' => $token,
                ...$summary,
                'seconds' => round(microtime(true) - $started, 3),
            ]);

            if (! $once && $summary['group_available'] === false) {
                sleep($idleSleep);
            }
        } while (! $once);

        return self::SUCCESS;
    }

    private function recoveryAdmitted(): bool
    {
        $profile = (string) Settings::settingValue('orchestrator_profile');
        $leaseUntil = (int) Settings::settingValue('orchestrator_lease_until');
        $allowed = in_array($profile, ['drain', 'balanced', 'fill'], true)
            || (int) Settings::settingValue('orchestrator_recovery_ok') === 1;

        return $allowed && $leaseUntil >= time();
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload): void
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
