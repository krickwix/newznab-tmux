<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Nzb\NzbBacklogCreationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class NzbCreateBacklog extends Command
{
    protected $signature = 'nntmux:nzb-create-backlog
        {--groups= : Comma-separated group names or IDs to process}
        {--leftguid= : Comma-separated release leftguid partitions to process}
        {--limit=250 : Maximum releases to attempt per pass}
        {--order=asc : Release ID order, asc or desc}
        {--mark-failed : Mark releases as NZB failed when writing returns false}
        {--loop : Keep processing until no NZBs are created}
        {--sleep=5 : Seconds to sleep between loop passes}';

    protected $description = 'Create missing NZB files from the release backlog using optional group and leftguid partitions';

    public function __construct(
        private readonly NzbBacklogCreationService $backlogCreation,
        private readonly DistributedWorkerTelemetry $workerTelemetry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $groups = $this->csvOption('groups');
        $leftGuids = $this->csvOption('leftguid');
        if (! $this->validateLeftGuids($leftGuids) || ! $this->validateGroups($groups)) {
            return self::FAILURE;
        }

        $limit = max(1, min(5000, (int) $this->option('limit')));
        $order = strtolower((string) $this->option('order')) === 'desc' ? 'desc' : 'asc';
        $markFailed = (bool) $this->option('mark-failed');
        $loop = (bool) $this->option('loop');
        $sleep = max(0, (int) $this->option('sleep'));

        do {
            $result = $this->backlogCreation->create(
                groups: $groups,
                leftGuids: $leftGuids,
                limit: $limit,
                markFailed: $markFailed,
                order: $order,
                countCandidates: false,
                onCreated: fn (int $created, int $total): null => $this->outputProgress($created, $total)
            );
            $this->recordTelemetry($result);

            $this->newLine();
            $this->info(sprintf(
                'NZB backlog pass: candidates=%d selected=%d scanned=%d scan_exhausted=%s selection_seconds=%.6f attempted=%d created=%d failed=%d marked_failed=%d',
                $result['candidate_total'],
                $result['selected'],
                $result['scanned'],
                $result['scan_exhausted'] ? 'yes' : 'no',
                $result['selection_duration_seconds'],
                $result['attempted'],
                $result['created'],
                $result['failed'],
                $result['marked_failed']
            ));

            if (! $loop || ($result['created'] === 0 && $result['marked_failed'] === 0)) {
                break;
            }

            if ($sleep > 0) {
                sleep($sleep);
            }
        } while (true);

        return self::SUCCESS;
    }

    /**
     * @param  array{selected: int, scanned: int, scan_exhausted: bool, selection_duration_seconds: float, attempted: int, created: int, failed: int, marked_failed: int}  $result
     */
    private function recordTelemetry(array $result): void
    {
        $this->workerTelemetry->recordSelectorDuration($result['selection_duration_seconds']);

        foreach (['scanned', 'selected', 'attempted', 'created', 'failed', 'marked_failed'] as $metric) {
            if ($result[$metric] > 0) {
                $this->workerTelemetry->recordItem('nzb-backlog', 'nzb', $metric, $result[$metric]);
            }
        }

        if ($result['scan_exhausted']) {
            $this->workerTelemetry->recordItem('nzb-backlog', 'nzb', 'scan_exhausted');
        }
    }

    /**
     * @return array<int, string>
     */
    private function csvOption(string $name): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) $this->option($name))
        )));
    }

    private function outputProgress(int $created, int $total): null
    {
        $this->output->write(sprintf("\r      Creating NZBs: %s/%s", number_format($created), number_format($total)));

        return null;
    }

    /**
     * @param  array<int, string>  $leftGuids
     */
    private function validateLeftGuids(array $leftGuids): bool
    {
        foreach ($leftGuids as $leftGuid) {
            if (preg_match('/^[0-9a-f]$/i', $leftGuid) !== 1) {
                $this->error('Invalid leftguid partition: '.$leftGuid);

                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $groups
     */
    private function validateGroups(array $groups): bool
    {
        if ($groups === []) {
            return true;
        }

        $ids = [];
        $names = [];
        foreach ($groups as $group) {
            if (is_numeric($group)) {
                $ids[] = (int) $group;
            } else {
                $names[] = $group;
            }
        }

        $knownIds = $ids === []
            ? []
            : DB::table('usenet_groups')->whereIn('id', $ids)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $knownNames = $names === []
            ? []
            : DB::table('usenet_groups')->whereIn('name', $names)->pluck('name')->map(static fn ($name): string => (string) $name)->all();

        $unknownIds = array_diff($ids, $knownIds);
        $unknownNames = array_diff($names, $knownNames);
        if ($unknownIds !== [] || $unknownNames !== []) {
            $this->error('Unknown group(s): '.implode(',', array_merge($unknownIds, $unknownNames)));

            return false;
        }

        return true;
    }
}
