<?php

declare(strict_types=1);

namespace Tests\Unit\Releases;

use App\Services\Releases\ReleasePump;
use PHPUnit\Framework\TestCase;

final class ReleasePumpTest extends TestCase
{
    public function test_it_drains_ready_work_before_and_after_one_bounded_preparation_slice(): void
    {
        $pump = new RecordingReleasePump;

        $pump->run('42', deadlineSeconds: 25, batchSize: 200);

        self::assertSame([
            ['ready', '42', 200],
            ['prepare', '42', 200],
            ['ready', '42', 200],
            ['cleanup', '42', 200],
        ], $pump->events);
    }

    public function test_it_stops_between_safe_stages_when_the_deadline_is_exhausted(): void
    {
        $pump = new RecordingReleasePump;
        $pump->advanceClockAfterFirstReady = true;

        $result = $pump->run('42', deadlineSeconds: 1, batchSize: 200);

        self::assertSame([['ready', '42', 200]], $pump->events);
        self::assertTrue($result['budget_exhausted']);
    }
}

final class RecordingReleasePump extends ReleasePump
{
    /** @var list<array{string, string, int}> */
    public array $events = [];

    public bool $advanceClockAfterFirstReady = false;

    private float $clock = 100.0;

    public function __construct() {}

    protected function drainReady(string $groupId, int $batchSize, float $deadlineAt): int
    {
        $this->events[] = ['ready', $groupId, $batchSize];
        if ($this->advanceClockAfterFirstReady) {
            $this->clock = $deadlineAt;
        }

        return 0;
    }

    protected function advancePreparation(string $groupId, int $batchSize, float $deadlineAt): void
    {
        $this->events[] = ['prepare', $groupId, $batchSize];
    }

    protected function cleanup(string $groupId, int $batchSize): void
    {
        $this->events[] = ['cleanup', $groupId, $batchSize];
    }

    protected function monotonicNow(): float
    {
        return $this->clock;
    }
}
