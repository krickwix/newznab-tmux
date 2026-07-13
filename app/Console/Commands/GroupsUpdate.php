<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UsenetGroup;
use App\Services\NNTP\NNTPService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class GroupsUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'groups:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update first/last article numbers for all active groups';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $start = now();

        // The availability snapshot and the range workers must use the same provider.
        $nntp = app(NNTPService::class);
        $useAlternate = config('nntmux_nntp.use_alternate_nntp_server') === true;
        $connectResult = $useAlternate
            ? $nntp->doConnect(false, true)
            : $nntp->doConnect();
        if ($connectResult !== true) {
            $errorMessage = '❌ Unable to connect to usenet server';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            $this->error($errorMessage);

            return Command::FAILURE;
        }

        $this->info('📡 Getting first/last for all active groups...');

        try {
            $data = $nntp->getGroups();

            if ($nntp->isError($data)) {
                $this->error('❌ Failed to getGroups() from NNTP server');

                return Command::FAILURE;
            }

            // Get all active groups
            $activeGroups = Arr::pluck(
                UsenetGroup::query()
                    ->where('active', '=', 1)
                    ->orWhere('backfill', '=', 1)
                    ->get(['name']),
                'name'
            );

            $listedGroups = [];
            $activeLookup = array_fill_keys($activeGroups, true);
            foreach ($data as $newgroup) {
                $name = (string) ($newgroup['group'] ?? '');
                if ($name !== '' && isset($activeLookup[$name])) {
                    $listedGroups[$name] = $newgroup;
                }
            }

            $this->info('🔄 Verifying usable ranges for active groups...');

            $rows = [];
            $bar = $this->output->createProgressBar(count($activeGroups));
            $bar->start();

            foreach ($activeGroups as $groupName) {
                $rows[] = $this->resolveUsableRange($nntp, $groupName, $listedGroups[$groupName] ?? null);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            // DELETE is transactional for the InnoDB table; TRUNCATE was not and
            // exposed an empty/partially rebuilt snapshot to the orchestrator.
            DB::transaction(function () use ($rows): void {
                DB::table('short_groups')->delete();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('short_groups')->insert($chunk);
                }
            });

            $elapsed = now()->diffInSeconds($start, true);
            $updated = count($rows);
            $this->info("✅ Updated {$updated} groups from verified GROUP ranges");
            $this->info("⏱️  Running time: {$elapsed} seconds");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Update failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * LIST ACTIVE low-water marks can precede the first article that is
     * actually addressable. GROUP is authoritative for the selected provider.
     *
     * @param  array<string, mixed>|null  $listedGroup
     * @return array{name: string, first_record: int, last_record: int, updated: \DateTimeInterface}
     */
    private function resolveUsableRange(NNTPService $nntp, string $groupName, ?array $listedGroup): array
    {
        if ($listedGroup === null) {
            throw new \RuntimeException("Provider did not advertise active group {$groupName}");
        }

        $summary = $nntp->selectGroup($groupName, false, true);
        if (NNTPService::isError($summary) || ! is_array($summary)) {
            throw new \RuntimeException("Provider GROUP range could not be verified for {$groupName}");
        }

        $first = (int) ($summary['first'] ?? 0);
        $last = (int) ($summary['last'] ?? 0);
        if ($first <= 0 || $last < $first) {
            throw new \RuntimeException("Provider returned an invalid GROUP range for {$groupName}");
        }

        return [
            'name' => $groupName,
            'first_record' => $first,
            'last_record' => $last,
            'updated' => now(),
        ];
    }
}
