<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Diagnostics\BodyPreambleFragmentRequeueService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class BodyPreambleRecoveryCycle extends Command
{
    protected $signature = 'nntmux:body-preamble-recovery-cycle
                            {group : Exact group name}
                            {--regex=* : Legacy collection regex id; repeatable}
                            {--limit=1000 : Maximum sources pruned and requeued per cycle}
                            {--max-current-parts=2}
                            {--min-total-parts=10}
                            {--cutoff-hours=2 : Protect collections newer than this many hours}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Prune proven BODY recoveries and replenish one bounded repair cohort';

    public function handle(BodyPreambleFragmentRequeueService $service): int
    {
        try {
            $group = (string) $this->argument('group');
            $regexIds = $this->regexIds();
            $limit = max(1, min(1000, (int) $this->option('limit')));
            $maxCurrentParts = max(1, (int) $this->option('max-current-parts'));
            $minTotalParts = max(1, (int) $this->option('min-total-parts'));
            $cutoff = now()->subHours(max(1, (int) $this->option('cutoff-hours')))->toDateTimeString();

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

        $summary = ['prune' => $prune, 'requeue' => $requeue];
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
            throw new InvalidArgumentException('At least one --regex selector is required.');
        }

        return $ids;
    }
}
