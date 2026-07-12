<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\XrefService;
use PHPUnit\Framework\TestCase;

final class XrefServiceTest extends TestCase
{
    public function test_diff_returns_one_representative_token_only_for_new_groups(): void
    {
        $service = new XrefService;

        self::assertSame(
            ['alt.binaries.baz:300'],
            $service->diffNewTokens(
                'alt.binaries.foo:100 alt.binaries.bar:200',
                'alt.binaries.foo:900 alt.binaries.baz:300 alt.binaries.baz:301',
            ),
        );
    }

    public function test_diff_recognizes_existing_groups_across_all_xref_whitespace(): void
    {
        $service = new XrefService;

        self::assertSame(
            [],
            $service->diffNewTokens(
                "alt.binaries.foo:1\nalt.binaries.bar:2\talt.binaries.baz:3",
                'alt.binaries.bar:200 alt.binaries.baz:300',
            ),
        );
    }

    public function test_diff_matches_exact_group_names_case_insensitively_with_or_without_article_numbers(): void
    {
        $service = new XrefService;

        self::assertSame([], $service->diffNewTokens('Alt.Binaries.Foo', 'alt.binaries.foo:200'));
        self::assertSame(
            ['alt.binaries.foobar:300'],
            $service->diffNewTokens('alt.binaries.foo:100', 'alt.binaries.foobar:300'),
        );
    }
}
