<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Settings;
use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\Distributed\NativeLeafStartupSmoke;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\PostProcessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PostProcessGuid extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postprocess:guid
                            {type : Type: additional, nfo, movie, tv, anime, books, music, console, or games}
                            {guid : First character of release leftguid}
                            {renamed? : For movie/tv: process renamed only (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Post process releases by GUID character';

    public function __construct(
        private readonly PostProcessService $postProcessService,
        private readonly AdditionalProcessingOrchestrator $additionalProcessor
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->argument('type');
        $guid = $this->argument('guid');
        $renamed = $this->argument('renamed') ?? '';

        if (! $this->isValidChar($guid)) {
            $this->error('GUID character must be a single alphanumeric character.');

            return self::FAILURE;
        }

        $smokeArguments = [(string) $type, (string) $guid];
        if ($renamed !== '') {
            $smokeArguments[] = (string) $renamed;
        }
        if (NativeLeafStartupSmoke::recordIfEnabled('postprocess:guid', $smokeArguments)) {
            return self::SUCCESS;
        }

        try {
            match ($type) {
                'additional' => $this->processAdditional($guid),
                'nfo' => $this->processNfo($guid),
                'movie' => $this->postProcessService->processMovies('', $guid, $renamed),
                'tv' => $this->postProcessService->processTv('', $guid, $renamed),
                'anime' => $this->postProcessService->processAnime('', $guid),
                'books' => $this->postProcessService->processBooks('', $guid),
                'music' => $this->postProcessService->processMusic('', $guid),
                'console' => $this->postProcessService->processConsoles('', $guid),
                'games' => $this->postProcessService->processGames('', $guid),
                default => throw new \InvalidArgumentException(
                    'Invalid type. Must be: additional, nfo, movie, tv, anime, books, music, console, or games.'
                ),
            };

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Process additional data for releases.
     */
    private function processAdditional(string $guid): void
    {
        try {
            $this->additionalProcessor->start('', $guid);
        } finally {
            $this->additionalProcessor->finish();
        }
    }

    /**
     * Process NFO files for releases.
     */
    private function processNfo(string $guid): void
    {
        $nntp = $this->getNntp();
        (new NfoService)->processNfoFiles(
            $nntp,
            '',
            $guid,
            (bool) Settings::settingValue('lookupimdb'),
            (bool) Settings::settingValue('lookuptv')
        );
    }

    /**
     * Check if the bucket is a single leftguid character.
     */
    private function isValidChar(string $char): bool
    {
        return preg_match('/^[A-Za-z0-9]$/', $char) === 1;
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
            throw new \RuntimeException($errorMessage);
        }

        return $nntp;
    }
}
