<?php

declare(strict_types=1);

namespace App\Services\Distributed;

final readonly class NativeWorkerCommitResult
{
    public function __construct(
        public bool $successful,
        public string $output,
        public string $errorOutput,
        public ?int $exitCode,
    ) {}

    public function message(): string
    {
        $message = trim($this->errorOutput) !== ''
            ? trim($this->errorOutput)
            : trim($this->output);

        if ($message === '') {
            $message = 'native worker commit exited without output';
        }

        return $this->exitCode === null
            ? $message
            : sprintf('exit %d: %s', $this->exitCode, $message);
    }
}
