<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NameFixing\NativeHashedFixNameSearchSync;
use App\Services\NameFixing\NativeSearchSideEffectSyncFailed;
use App\Services\NameFixing\NativeSearchSideEffectOutboxSync;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Throwable;

class SyncNativeSearchSideEffects extends Command
{
    private const MAX_INPUT_BYTES = 1048576;

    protected $signature = 'nntmux:native-search-side-effects:sync
                            {--input=- : Path to native commit JSON report; use - for STDIN}
                            {--pending-outbox : Process pending native search side-effect outbox rows instead of a report}
                            {--limit=100 : Maximum pending outbox rows to process}';

    protected $description = 'Execute PHP-owned search side effects for a native commit report';

    public function handle(NativeHashedFixNameSearchSync $sync, NativeSearchSideEffectOutboxSync $outboxSync): int
    {
        try {
            if ((bool) $this->option('pending-outbox')) {
                $result = $outboxSync->syncPending((int) $this->option('limit'));
                $this->line(json_encode(
                    $result,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ));

                return ($result['search_updates_failed'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
            }

            $payload = $this->decodeJson($this->readInput((string) $this->option('input')));
            $this->line(json_encode(
                $sync->sync($payload),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        } catch (NativeSearchSideEffectSyncFailed $exception) {
            $this->line(json_encode(
                $exception->report(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function readInput(string $input): string
    {
        if ($input === '-') {
            $contents = stream_get_contents(STDIN, self::MAX_INPUT_BYTES + 1);

            return $this->assertInputSize($contents === false ? '' : $contents);
        }

        if (! is_file($input)) {
            throw new InvalidArgumentException("Input JSON file [{$input}] does not exist.");
        }

        $size = filesize($input);
        if ($size !== false && $size > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('Input JSON exceeds the maximum native search sync report size.');
        }

        $contents = file_get_contents($input);

        return $this->assertInputSize($contents === false ? '' : $contents);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Input JSON must decode to an object.');
        }

        return $decoded;
    }

    private function assertInputSize(string $contents): string
    {
        if (strlen($contents) > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('Input JSON exceeds the maximum native search sync report size.');
        }

        return $contents;
    }
}
