<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Settings;
use App\Services\Diagnostics\BodyPreambleFragmentRequeueService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class BodyPreambleRecoveryCycle extends Command
{
    protected $signature = 'nntmux:body-preamble-recovery-cycle
                            {group : Exact group name}
                            {--regex=* : Legacy collection regex id; repeatable}
                            {--limit=1000 : Maximum sources pruned and requeued per cycle}
                            {--max-current-parts= : Override configured maximum current parts}
                            {--min-total-parts= : Override configured minimum total parts}
                            {--cutoff-hours= : Override configured source cutoff hours}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Prune proven BODY recoveries and replenish one bounded repair cohort';

    public function handle(BodyPreambleFragmentRequeueService $service): int
    {
        $profile = (string) Settings::settingValue('orchestrator_profile');
        $leaseUntil = (int) Settings::settingValue('orchestrator_lease_until');
        $recoveryAllowed = in_array($profile, ['drain', 'balanced', 'fill'], true)
            || (int) Settings::settingValue('orchestrator_recovery_ok') === 1;
        if (! $recoveryAllowed || $leaseUntil < time()) {
            $summary = [
                'skipped' => true,
                'reason' => $leaseUntil < time() ? 'orchestrator_lease_stale' : 'orchestrator_profile_unsafe',
                'profile' => $profile,
            ];
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        try {
            $group = (string) $this->argument('group');
            $regexIds = $this->regexIds();
            $limit = max(1, min(1000, (int) $this->option('limit')));
            $maxCurrentParts = max(1, (int) ($this->option('max-current-parts')
                ?: config('nntmux.orchestrator.body_recovery_source_max_current_parts', 2)));
            $minTotalParts = max(1, (int) ($this->option('min-total-parts')
                ?: config('nntmux.orchestrator.body_recovery_source_min_total_parts', 10)));
            $cutoff = now()->subHours(max(1, (int) ($this->option('cutoff-hours')
                ?: config('nntmux.orchestrator.body_recovery_source_cutoff_hours', 2))))->toDateTimeString();

            $prune = $service->pruneRecovered(
                $group,
                $regexIds,
                $limit,
                $maxCurrentParts,
                $minTotalParts,
                $cutoff,
                0,
                false,
            );
            if ((int) $prune['recovered'] > 0) {
                $prune = $service->pruneRecovered(
                    $group,
                    $regexIds,
                    $limit,
                    $maxCurrentParts,
                    $minTotalParts,
                    $cutoff,
                    0,
                    true,
                    (string) $prune['manifest_hash'],
                );
            }
            $requeue = $service->requeue(
                $group,
                $regexIds,
                $limit,
                $maxCurrentParts,
                $minTotalParts,
                $cutoff,
                0,
                true,
            );
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $summary = ['skipped' => false, 'profile' => $profile, 'prune' => $prune, 'requeue' => $requeue];
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Deleted %d proven fragments; queued %d of %d candidates.',
                $prune['deleted'],
                $requeue['inserted'],
                $requeue['candidates'],
            ));
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function regexIds(): array
    {
        $ids = array_values(array_unique(array_map(
            static function (mixed $value): int {
                $id = filter_var((string) $value, FILTER_VALIDATE_INT);
                if ($id === false) {
                    throw new InvalidArgumentException('Invalid --regex value: '.(string) $value);
                }

                return $id;
            },
            (array) $this->option('regex'),
        )));
        if ($ids === []) {
            $ids = array_values(array_unique(array_map(
                'intval',
                (array) config('nntmux.orchestrator.body_recovery_source_regex_ids', []),
            )));
        }
        if ($ids === []) {
            throw new InvalidArgumentException('At least one recovery source regex selector is required.');
        }

        return $ids;
    }
}
