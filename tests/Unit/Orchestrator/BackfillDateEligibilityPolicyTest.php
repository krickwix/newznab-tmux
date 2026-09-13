<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\BackfillDateEligibilityPolicy;
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
}
