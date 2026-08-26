<?php

declare(strict_types=1);

namespace App\Services\Distributed;

final readonly class NativeHashedFixNameRenamePrepassResult
{
    /**
     * @param  list<int>  $releaseIds
     */
    public function __construct(
        public bool $successful,
        public string $output = '',
        public string $errorOutput = '',
        public ?int $exitCode = null,
        public int $releaseUpdatesSeen = 0,
        public int $releaseUpdatesApplied = 0,
        public array $releaseIds = [],
    ) {}

    public function message(): string
    {
        $message = trim($this->errorOutput) !== ''
            ? trim($this->errorOutput)
            : trim($this->output);

        if ($message === '') {
            $message = 'native rename prepass exited without output';
        }

        return $this->exitCode === null
            ? $message
            : sprintf('exit %d: %s', $this->exitCode, $message);
    }
}
