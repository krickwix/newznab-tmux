<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\BackfillWindow;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use PHPUnit\Framework\TestCase;

final class BackfillWindowTest extends TestCase
{
    private const array PAUSED = ['alt.binaries.boneless', 'A.B.Multimedia.Aviation'];

    public function test_open_inside_a_range_and_closed_outside(): void
    {
        $window = new BackfillWindow('02:00-06:00', self::PAUSED, 0);

        self::assertFalse($window->isOpen($this->at('01:59')));
        self::assertTrue($window->isOpen($this->at('02:00')));
        self::assertTrue($window->isOpen($this->at('05:59')));
        self::assertFalse($window->isOpen($this->at('06:00')));
    }

    public function test_a_range_ending_before_it_starts_wraps_past_midnight(): void
    {
        $window = new BackfillWindow('22:30-01:00, 12:00-24:00', self::PAUSED, 0);

        self::assertTrue($window->isOpen($this->at('23:15')));
        self::assertTrue($window->isOpen($this->at('00:30')));
        self::assertFalse($window->isOpen($this->at('01:00')));
        self::assertTrue($window->isOpen($this->at('12:00')));
        self::assertFalse($window->isOpen($this->at('11:59')));
    }

    public function test_malformed_ranges_are_ignored(): void
    {
        $window = new BackfillWindow('25:00-26:00,nonsense,03:00-03:00,04:00-05:00', self::PAUSED, 0);

        self::assertFalse($window->isOpen($this->at('03:00')));
        self::assertTrue($window->isOpen($this->at('04:30')));
    }

    public function test_never_open_without_a_schedule_or_paused_groups(): void
    {
        self::assertFalse((new BackfillWindow('', self::PAUSED, 0))->isOpen($this->at('04:00')));
        self::assertFalse((new BackfillWindow('00:00-24:00', [], 0))->isOpen($this->at('04:00')));
    }

    public function test_paused_groups_and_low_mark_apply_only_while_open(): void
    {
        $window = new BackfillWindow('02:00-06:00', self::PAUSED, 500_000);

        self::assertSame(['alt.binaries.boneless', 'a.b.multimedia.aviation'], $window->pausedGroups($this->at('03:00')));
        self::assertTrue($window->pauses(' A.B.MULTIMEDIA.AVIATION ', $this->at('03:00')));
        self::assertFalse($window->pauses('alt.binaries.teevee', $this->at('03:00')));
        self::assertSame(500_000, $window->collectionsLow($this->at('03:00')));

        self::assertSame([], $window->pausedGroups($this->at('07:00')));
        self::assertFalse($window->pauses('alt.binaries.boneless', $this->at('07:00')));
        self::assertNull($window->collectionsLow($this->at('07:00')));
    }

    public function test_paused_groups_are_never_backfill_candidates(): void
    {
        $candidates = [
            ['name' => 'alt.binaries.teevee', 'cursor' => 1],
            ['name' => 'a.b.multimedia.aviation', 'cursor' => 2],
            ['name' => 'alt.binaries.moovee', 'cursor' => 3],
        ];

        self::assertSame(
            ['alt.binaries.teevee', 'alt.binaries.moovee'],
            array_column(PipelineSnapshotRepository::withoutGroups($candidates, ['a.b.multimedia.aviation']), 'name')
        );
        self::assertSame($candidates, PipelineSnapshotRepository::withoutGroups($candidates, []));
    }

    private function at(string $time): int
    {
        return (int) strtotime('2026-10-11 '.$time.':00 UTC');
    }
}
