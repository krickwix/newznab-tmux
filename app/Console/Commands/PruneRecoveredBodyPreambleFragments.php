<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Diagnostics\BodyPreambleFragmentRequeueService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PruneRecoveredBodyPreambleFragments extends Command
{
    protected $signature = 'nntmux:prune-recovered-body-fragments
                            {group : Group id or exact group name}
                            {--regex=* : Collection regex id to include; repeatable}
                            {--limit=1000 : Maximum source fragments to inspect}
                            {--max-current-parts=2 : Only one-binary fragments with this many stored parts or fewer}
                            {--min-total-parts=10 : Only binaries whose expected part count is at least this value}
                            {--before= : Required collection dateadded upper bound}
                            {--after-collection-id=0 : Only inspect collections above this id}
                            {--manifest-hash= : Exact recovered-id hash emitted by the dry-run; required with --update}
                            {--update : Delete proven recovered source fragments; default is dry-run}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Prune legacy BODY fragments only after the same article exists in a normalized collection';

    public function handle(BodyPreambleFragmentRequeueService $service): int
    {
        try {
            $summary = $service->pruneRecovered(
                (string) $this->argument('group'),
                $this->regexIds(),
                (int) $this->option('limit'),
                (int) $this->option('max-current-parts'),
                (int) $this->option('min-total-parts'),
                $this->option('before') === null ? null : (string) $this->option('before'),
                (int) $this->option('after-collection-id'),
                (bool) $this->option('update'),
                $this->option('manifest-hash') === null ? null : (string) $this->option('manifest-hash'),
            );
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                '%s %d of %d proven recovered fragments for %s.',
                $summary['updated'] ? 'Deleted' : 'Dry-run found',
                $summary['updated'] ? $summary['deleted'] : $summary['recovered'],
                $summary['candidates'],
                $summary['group']['name'],
            ));
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function regexIds(): array
    {
        return array_values(array_unique(array_map(
            static function (mixed $value): int {
                $id = filter_var((string) $value, FILTER_VALIDATE_INT);
                if ($id === false) {
                    throw new InvalidArgumentException('Invalid --regex value: '.(string) $value);
                }

                return $id;
            },
            array_filter((array) $this->option('regex'), static fn (mixed $value): bool => $value !== null && $value !== ''),
        )));
    }
}
