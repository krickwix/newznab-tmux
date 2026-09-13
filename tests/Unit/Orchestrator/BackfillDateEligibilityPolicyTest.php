<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\BackfillDateEligibilityPolicy;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class BackfillDateEligibilityPolicyTest extends TestCase
{
    public function test_group_target_mode_uses_each_groups_configured_date(): void
    {
        self::assertSame(
            '(NOW() - INTERVAL g.backfill_target DAY) < g.first_record_postdate',
            (new BackfillDateEligibilityPolicy(1))->datePendingSql('g'),
        );
    }

    public function test_global_date_mode_uses_the_resolved_global_age(): void
    {
        self::assertSame(
            '(NOW() - INTERVAL 42 DAY) < g.first_record_postdate',
            (new BackfillDateEligibilityPolicy(2, 42))->datePendingSql('g'),
        );
    }

    public function test_runtime_date_check_matches_the_strict_group_target_boundary(): void
    {
        $at = Carbon::parse('2026-09-13 18:00:00');
        $policy = new BackfillDateEligibilityPolicy(1);

        self::assertFalse($policy->isPending('2026-09-06 18:00:00', 7, $at));
        self::assertTrue($policy->isPending('2026-09-06 18:00:01', 7, $at));
        self::assertFalse($policy->isPending('2026-09-13 18:00:01', 7, $at));
        self::assertFalse($policy->isPending('1999-12-31 23:59:59', 10_000, $at));
        self::assertFalse($policy->isPending('not-a-date', 7, $at));
    }
}
