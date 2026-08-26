<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NameFixing\NativeHashedFixNameRenameApplier;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Throwable;

class ApplyNativeHashedFixNameRenames extends Command
{
    private const MAX_INPUT_BYTES = 1048576;

    protected $signature = 'nntmux:native-hashed-fixnames:apply-renames
                            {--input=- : Path to resolved native write-contract JSON; use - for STDIN}';

    protected $description = 'Apply PHP-owned hashed fix-name renames from a resolved native write contract';

    public function handle(NativeHashedFixNameRenameApplier $applier): int
    {
        try {
            $payload = $this->decodeJson($this->readInput((string) $this->option('input')));
            $this->line(json_encode(
                $applier->apply($payload),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
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
            throw new InvalidArgumentException('Input JSON exceeds the maximum native rename apply report size.');
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
            throw new InvalidArgumentException('Input JSON exceeds the maximum native rename apply report size.');
        }

        return $contents;
    }
}
