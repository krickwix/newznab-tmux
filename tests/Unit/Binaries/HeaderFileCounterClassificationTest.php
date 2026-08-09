<?php

declare(strict_types=1);

namespace Tests\Unit\Binaries;

use App\Services\Binaries\HeaderStorageService;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 1 of docs/design/2026-08-04-ingest-collection-keying.md.
 *
 * extractFileNumberAndTotal() now says whether the count it returns is a REAL
 * file counter or a PART counter that leaked in through the raw-subject
 * fallback. Nothing acts on it yet, so this file has two jobs: pin the
 * classification, and prove the values that DO reach the write path are
 * unchanged.
 *
 * The second job is the important one. Keying on `name . 0` cannot ship until
 * per-collection ordinals are allocated -- with `settings.completion` NULL
 * (=100), stage 0 reduces to COUNT(DISTINCT filenumber) == MAX(filenumber), so
 * two files of one posting would otherwise land in one collection both claiming
 * filenumber 1 and collide on UNIQUE (collections_id, filenumber). Until then a
 * behaviour change here is a bug, not progress.
 *
 * Subjects are transcribed from live headers, not invented.
 */
final class HeaderFileCounterClassificationTest extends TestCase
{
    /**
     * @return array{0: int, 1: int, 2: bool}
     */
    private function classify(string $subject): array
    {
        // The parse HeaderParser performs before storage: matches[0] is the raw
        // subject, matches[1] the same thing without its (x/y) part counter.
        self::assertSame(
            1,
            preg_match('/^\s*(?!Usenet Index Post)(.+?)\s+\((\d+)\/(\d+)\)/', $subject, $matches),
            sprintf('fixture is not a parseable yEnc subject: %s', $subject),
        );

        $method = new ReflectionMethod(HeaderStorageService::class, 'extractFileNumberAndTotal');

        /** @var array{0: int, 1: int, 2: bool} $result */
        $result = $method->invoke(
            $this->headerStorageServiceWithoutConstructor(),
            ['matches' => $matches],
        );

        return $result;
    }

    private function headerStorageServiceWithoutConstructor(): HeaderStorageService
    {
        // extractFileNumberAndTotal() touches no collaborator, so the wiring
        // this service normally needs is irrelevant to what is under test.
        return (new \ReflectionClass(HeaderStorageService::class))->newInstanceWithoutConstructor();
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function realFileCounters(): array
    {
        return [
            'bracketed n/m' => ['Some.Release [03/11] - "x.part02.rar" yEnc (1/500)', 3, 11],
            'n of m' => ['Some.Release File 3 of 11 - "x.rar" yEnc (1/500)', 3, 11],
            'parenthesised n/m' => ['The Borrowers (1997) ==(37/62) - yEnc "x.part32.rar" yEnc (1/9)', 37, 62],
        ];
    }

    #[DataProvider('realFileCounters')]
    public function test_a_declared_file_counter_is_trusted(string $subject, int $file, int $total): void
    {
        [$fileNumber, $totalFiles, $isReal] = $this->classify($subject);

        self::assertTrue($isReal, 'a declared file counter must be trusted');
        self::assertSame($file, $fileNumber);
        self::assertSame($total, $totalFiles);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function partCounterOnly(): array
    {
        return [
            // The live alt.binaries.cinemageddon posting that motivated this:
            // two files, two different "file counts", one collection each.
            'cinemageddon vol001' => ['60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol001+01.PAR2) yEnc (1/2)'],
            'cinemageddon vol009' => ['60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol009+06.PAR2) yEnc (1/7)'],
            'bare random name' => ['"gjX3QVMbjFGuo7IXAfp" yEnc (1/158)'],
        ];
    }

    #[DataProvider('partCounterOnly')]
    public function test_a_part_counter_is_not_mistaken_for_a_file_counter(string $subject): void
    {
        [, , $isReal] = $this->classify($subject);

        self::assertFalse($isReal, 'the (x/y) part counter is not a file count');
    }

    /**
     * The two cinemageddon files differ ONLY by the part counter, and today
     * that is enough to give them different collection keys. This pins the
     * defect: same name, different totalfiles, so `name . $totalFiles` splits.
     */
    public function test_the_two_files_of_one_posting_still_disagree_on_the_count(): void
    {
        [, $first] = $this->classify('60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol001+01.PAR2) yEnc (1/2)');
        [, $second] = $this->classify('60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol009+06.PAR2) yEnc (1/7)');

        self::assertNotSame($first, $second);
        self::assertSame(2, $first);
        self::assertSame(7, $second);
    }

    /**
     * The inertness proof. Every fixture above must still yield exactly the
     * file number and count it did before the third value existed, because
     * those two are what reach collectionIdentity() and binaries.filenumber.
     *
     * If this fails, the classification has started changing ingest and the
     * ordinal allocator is not in place to absorb it.
     */
    public function test_the_values_that_reach_the_write_path_are_unchanged(): void
    {
        $expected = [
            'Some.Release [03/11] - "x.part02.rar" yEnc (1/500)' => [3, 11],
            'Some.Release File 3 of 11 - "x.rar" yEnc (1/500)' => [3, 11],
            '60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol001+01.PAR2) yEnc (1/2)' => [1, 2],
            '60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol009+06.PAR2) yEnc (1/7)' => [1, 7],
            '"gjX3QVMbjFGuo7IXAfp" yEnc (1/158)' => [1, 158],
        ];

        foreach ($expected as $subject => [$file, $total]) {
            [$fileNumber, $totalFiles] = $this->classify($subject);

            self::assertSame($file, $fileNumber, sprintf('file number changed for: %s', $subject));
            self::assertSame($total, $totalFiles, sprintf('file count changed for: %s', $subject));
        }
    }

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: bool}>
     */
    public static function allowlists(): array
    {
        return [
            'unset reports nothing' => [[], 'alt.binaries.cinemageddon', false],
            'named group reports' => [['alt.binaries.cinemageddon'], 'alt.binaries.cinemageddon', true],
            'other group stays quiet' => [['alt.binaries.cinemageddon'], 'alt.binaries.hdtv', false],
            'all reports every group' => [['all'], 'alt.binaries.hdtv', true],
            'all alongside a name' => [['alt.binaries.cinemageddon', 'all'], 'alt.binaries.moovee', true],
            'case and padding ignored' => [['  ALL  '], 'alt.binaries.moovee', true],
        ];
    }

    /**
     * The allowlist decides whether the count is SAID OUT LOUD, never whether
     * it is computed, and never anything about ingest. `all` is the sentinel
     * NNTMUX_ORCHESTRATOR_BACKFILL_PROBE_GROUPS already established.
     *
     * @param  list<string>  $configured
     */
    #[DataProvider('allowlists')]
    public function test_the_allowlist_decides_only_whether_the_count_is_reported(
        array $configured,
        string $groupName,
        bool $expected,
    ): void {
        $method = new ReflectionMethod(HeaderStorageService::class, 'reportPartCounterKeying');
        $service = $this->headerStorageServiceWithoutConstructor();

        $counter = new \ReflectionProperty(HeaderStorageService::class, 'partCounterKeyedHeaders');
        $counter->setValue($service, 7);

        $logged = [];
        Log::shouldReceive('info')
            ->andReturnUsing(static function (string $message, array $context) use (&$logged): void {
                $logged[] = $context;
            });

        config()->set('nntmux.ingest_partcount_key_groups', $configured);
        $method->invoke($service, $groupName);

        self::assertSame($expected, $logged !== [], 'reporting decision');
        if ($expected) {
            self::assertSame(7, $logged[0]['headers']);
            self::assertSame($groupName, $logged[0]['group']);
        }
    }

    /**
     * A subject the normalizer already claimed is not reclassified: it pins
     * 1 of 1 precisely BECAUSE those subjects carry only a part counter, so the
     * value is deliberate rather than leaked.
     */
    public function test_normalizer_supplied_counts_are_treated_as_real(): void
    {
        $method = new ReflectionMethod(HeaderStorageService::class, 'extractFileNumberAndTotal');

        /** @var array{0: int, 1: int, 2: bool} $result */
        $result = $method->invoke($this->headerStorageServiceWithoutConstructor(), [
            'matches' => ['{X.vol127+72.par2} {tok} yEnc (410/710)', '{X.vol127+72.par2} {tok} yEnc', '410', '710'],
            'collection_file_number' => 1,
            'collection_total_files' => 1,
        ]);

        self::assertSame([1, 1, true], $result);
    }
}
