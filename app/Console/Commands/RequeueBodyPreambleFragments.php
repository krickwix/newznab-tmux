<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Diagnostics\BodyPreambleFragmentRequeueService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class RequeueBodyPreambleFragments extends Command
{
    protected $signature = 'nntmux:requeue-body-preamble-fragments
                            {group : Group id or exact group name}
                            {--regex=* : Collection regex id to include; repeatable}
                            {--limit=1000 : Maximum candidate collections to scan}
                            {--max-current-parts=2 : Only one-binary collections with this many stored parts or fewer}
                            {--min-total-parts=10 : Only binaries whose expected part count is at least this value}
                            {--before= : Optional collection dateadded upper bound}
                            {--after-collection-id=0 : Only scan collections with an id greater than this value}
                            {--update : Insert rows into missed_parts; default is dry-run}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Requeue legacy one-binary BODY-preamble fragments into missed_parts for partrepair refetch';

    public function handle(BodyPreambleFragmentRequeueService $service): int
    {
        try {
            $summary = $service->requeue(
                (string) $this->argument('group'),
                $this->regexIds(),
                (int) $this->option('limit'),
                (int) $this->option('max-current-parts'),
                (int) $this->option('min-total-parts'),
                $this->option('before') === null ? null : (string) $this->option('before'),
                (int) $this->option('after-collection-id'),
                (bool) $this->option('update')
            );
        } catch (InvalidArgumentException $e) {
            if ((bool) $this->option('json')) {
                $this->line((string) json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d candidate body-preamble fragments for %s (%d); inserted %d, skipped existing %d.',
            $summary['updated'] ? 'Requeued' : 'Dry-run found',
            $summary['candidates'],
            $summary['group']['name'],
            $summary['group']['id'],
            $summary['inserted'],
            $summary['skipped_existing']
        ));

        $this->table(
            ['collection_id', 'binary_id', 'article', 'regex_id', 'filenumber', 'parts', 'totalfiles'],
            array_map(static fn (array $row): array => [
                $row['collection_id'],
                $row['binary_id'],
                $row['article'],
                $row['regex_id'],
                $row['filenumber'],
                $row['currentparts'].'/'.$row['totalparts'],
                $row['totalfiles'],
            ], $summary['sample'])
        );

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function regexIds(): array
    {
        return array_values(array_unique(array_map(
            static function (mixed $value): int {
                $value = (string) $value;
                $id = filter_var($value, FILTER_VALIDATE_INT);
                if ($id === false) {
                    throw new InvalidArgumentException('Invalid --regex value: '.$value);
                }

                return $id;
            },
            array_filter((array) $this->option('regex'), static fn (mixed $value): bool => $value !== null && $value !== '')
        )));
    }
}
