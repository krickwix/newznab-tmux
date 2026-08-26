<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NameFixing\NativeHashedFixNameWriteContractResolver;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Throwable;

class ResolveNativeWriteContract extends Command
{
    private const MAX_INPUT_BYTES = 1048576;

    protected $signature = 'nntmux:native-write-contract:resolve
                            {--input=- : Path to native JSON report or write-contract JSON; use - for STDIN}';

    protected $description = 'Resolve PHP-owned native write-contract side effects without executing writes';

    public function handle(NativeHashedFixNameWriteContractResolver $resolver): int
    {
        try {
            $payload = $this->decodeJson($this->readInput((string) $this->option('input')));
            $this->line(json_encode(
                $resolver->resolve($payload),
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
            throw new InvalidArgumentException('Input JSON exceeds the maximum native write contract report size.');
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
            throw new InvalidArgumentException('Input JSON exceeds the maximum native write contract report size.');
        }

        return $contents;
    }
}
