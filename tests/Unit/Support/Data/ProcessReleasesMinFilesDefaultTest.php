<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Data;

use App\Support\Data\ProcessReleasesSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `minfilestoformrelease` defaults to 1, and 0 means DISABLED rather than
 * "no floor".
 *
 * The distinction is not cosmetic. Both delete predicates in
 * ReleaseProcessingService are guarded by `> 0` -- createReleases()' rejected
 * builder and deleteReleasesUnderMinFiles() -- and effectiveGroupThreshold()
 * treats an explicit group value of 0 as a real override rather than a
 * fall-through to the site setting. So 0 switches a pipeline stage off, which is
 * the opposite of what a reader of "minimum files = 0" assumes, and it is what
 * 22 live groups (7 of them active, the whole alt.binaries.dvd.* family) were
 * carrying: the admin edit form rendered a NULL override as 0, so saving the
 * form unchanged wrote the disabling value back.
 *
 * A default of 1 is also the floor that deletes nothing: measured against
 * production, 0 collections have totalfiles < 1 and 0 releases have totalpart
 * < 1, while the site's configured 2 was taking 9 collections and 48 releases.
 */
final class ProcessReleasesMinFilesDefaultTest extends TestCase
{
    public function test_the_constructor_default_is_one(): void
    {
        // A release describes at least one file, so this is the weakest floor
        // that still leaves the predicate switched on.
        $this->assertSame(1, (new ProcessReleasesSettings)->minFilesToFormRelease);
    }

    public function test_an_absent_settings_row_falls_back_to_one(): void
    {
        // forDatabase() is the only hydration path the workers use, and it has
        // its own default list -- a constructor default alone would not cover it.
        $this->assertSame(1, ProcessReleasesSettings::forDatabase([])->minFilesToFormRelease);
    }

    public function test_an_empty_settings_value_falls_back_to_one(): void
    {
        // Settings rows are stringly typed and '' is how the admin UI writes a
        // cleared field.
        $this->assertSame(
            1,
            ProcessReleasesSettings::forDatabase(['minfilestoformrelease' => ''])->minFilesToFormRelease
        );
    }

    /** @return iterable<string, array{0: mixed, 1: int}> */
    public static function explicitValues(): iterable
    {
        // An explicit 0 must survive hydration intact: it is the documented way
        // to disable the check, so silently coercing it to 1 would start
        // deleting for a site that had switched it off on purpose.
        yield 'explicit zero disables rather than defaults' => ['0', 0];
        yield 'explicit zero as int' => [0, 0];
        yield 'the live site value' => ['2', 2];
        yield 'a high floor' => ['10', 10];
    }

    #[DataProvider('explicitValues')]
    public function test_an_explicit_value_is_never_overridden_by_the_default(mixed $raw, int $expected): void
    {
        $this->assertSame(
            $expected,
            ProcessReleasesSettings::forDatabase(['minfilestoformrelease' => $raw])->minFilesToFormRelease
        );
    }
}
