<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GetArticleRange extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'articles:get-range
                            {mode : Mode: binaries or backfill}
                            {group : Group name}
                            {first : First article number}
                            {last : Last article number}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get a range of article headers for a group';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $mode = $this->argument('mode');
        $groupName = $this->argument('group');
        $firstArticle = (int) $this->argument('first');
        $lastArticle = (int) $this->argument('last');

        if (! \in_array($mode, ['binaries', 'backfill'], true)) {
            $this->error('Mode must be either "binaries" or "backfill".');

            return self::FAILURE;
        }

        try {
            $nntp = $this->getNntp();
            $groupMySQL = UsenetGroup::getByName($groupName)->toArray();

            if ($groupMySQL === null) {
                $this->error("Group not found: {$groupName}");

                return self::FAILURE;
            }

            if (NNTPService::isError($nntp->selectGroup($groupMySQL['name']))
                && NNTPService::isError($nntp->dataError($nntp, $groupMySQL['name']))) {
                return self::FAILURE;
            }

            $binaries = new BinariesService;
            $binaries->setNntp($nntp);
            $return = $binaries->scan(
                $groupMySQL,
                $firstArticle,
                $lastArticle,
                ((int) Settings::settingValue('safepartrepair') === 1 ? 'update' : 'backfill')
            );

            if (empty($return)) {
                return self::SUCCESS;
            }

            $this->updateGroupRecords($mode, $groupMySQL, $return, $firstArticle);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Update group records based on mode.
     *
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $return
     */
    private function updateGroupRecords(string $mode, array $groupMySQL, array $return, int $rangeFirstArticle): void
    {
        switch ($mode) {
            case 'binaries':
                if ($return['lastArticleNumber'] <= $groupMySQL['last_record']) {
                    return;
                }
                $unixTime = is_numeric($return['lastArticleDate'])
                    ? $return['lastArticleDate']
                    : strtotime($return['lastArticleDate']);
                DB::table('usenet_groups')
                    ->where('id', $groupMySQL['id'])
                    ->where('last_record', '<', $return['lastArticleNumber'])
                    ->update([
                        'last_record_postdate' => date('Y-m-d H:i:s', (int) $unixTime),
                        'last_record' => $return['lastArticleNumber'],
                        'last_updated' => now()->toDateTimeString(),
                    ]);
                break;

            case 'backfill':
                if ($return['firstArticleNumber'] >= $groupMySQL['first_record']) {
                    return;
                }
                $unixTime = is_numeric($return['firstArticleDate'])
                    ? $return['firstArticleDate']
                    : strtotime($return['firstArticleDate']);
                $updated = DB::table('usenet_groups')
                    ->where('id', $groupMySQL['id'])
                    ->where('first_record', '>', $return['firstArticleNumber'])
                    ->update([
                        'first_record_postdate' => date('Y-m-d H:i:s', (int) $unixTime),
                        'first_record' => $return['firstArticleNumber'],
                        'last_updated' => now()->toDateTimeString(),
                    ]);

                if ($updated > 0) {
                    $this->disableBackfillIfProviderFloorReached($groupMySQL, (int) $return['firstArticleNumber']);
                }
                break;

            default:
                return;
        }
    }

    /**
     * @param  array<string, mixed>  $groupMySQL
     */
    private function disableBackfillIfProviderFloorReached(array $groupMySQL, int $firstArticleNumber): void
    {
        if ((int) Settings::settingValue('disablebackfillgroup') !== 1) {
            return;
        }

        $providerFirst = (int) DB::table('short_groups')->where('name', $groupMySQL['name'])->max('first_record');
        if ($providerFirst <= 0) {
            $providerFirst = $firstArticleNumber;
        }

        if ($firstArticleNumber > $providerFirst) {
            return;
        }

        DB::table('usenet_groups')->where('id', $groupMySQL['id'])->update([
            'backfill' => 0,
            'last_updated' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Get NNTP connection.
     */
    private function getNntp(): NNTPService
    {
        $nntp = new NNTPService;

        $connectResult = config('nntmux_nntp.use_alternate_nntp_server') === true
            ? $nntp->doConnect(false, true)
            : $nntp->doConnect();

        if ($connectResult !== true) {
            $errorMessage = 'Unable to connect to usenet.';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            throw new \Exception($errorMessage);
        }

        return $nntp;
    }
}
