<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\CollectionFileNumberAllocator;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\IngestCollectionKeying;
use App\Services\CollectionsCleaningService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\SplitCollectionReconciler;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * docs/design/2026-08-04-ingest-collection-keying.md, end to end.
 *
 * The defect: `HeaderStorageService::extractFileNumberAndTotal()` probes
 * `matches[1]` -- the subject WITHOUT its `(x/y)` part counter -- and, finding
 * nothing, falls back to `matches[0]`, which still carries it. The part counter
 * is then returned as a file count and flows into
 * `CollectionHandler::collectionIdentity()`, which keys on
 * `$collMatch['name'] . $totalFiles`. Files of ONE posting carry different part
 * counts, so they mint different keys and one posting becomes N collections
 * holding one binary each.
 *
 * The fixtures are the live alt.binaries.cinemageddon posting named in the
 * design -- same name after cleaning, different part counters -- not invented
 * subjects.
 *
 * Four things are asserted here, and the last two matter as much as the first:
 *
 *  1. with the group enabled, one posting is one collection, densely numbered,
 *     at totalfiles = 0, and stage 0 then promotes it;
 *  2. a subject carrying a REAL file counter is untouched;
 *  3. with the flag off, ingest still produces today's fragmented shape exactly
 *     -- so the flag is provably a switch and not a rewrite;
 *  4. the flag-off and flag-on shapes for a real-counter posting are identical
 *     row for row.
 */
final class IngestCollectionKeyingTest extends TestCase
{
    private const GROUP = ['id' => 4211, 'name' => 'alt.binaries.cinemageddon'];

    private const OTHER_GROUP = ['id' => 4212, 'name' => 'alt.binaries.hdtv'];

    /**
     * One live posting: four par2 volumes, each declaring a different part
     * count, none declaring a file count. Two articles per file.
     *
     * @var list<string>
     */
    private const POSTING = [
        '60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol001+01.PAR2)',
        '60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol009+06.PAR2)',
        '60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol015+11.PAR2)',
        '60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol031+22.PAR2)',
    ];

    /** @var list<int> Part counts, one per file above -- the values that split the key today. */
    private const POSTING_PART_COUNTS = [2, 7, 11, 22];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.obfuscated_brace_token_groups' => [],
            'nntmux.obfuscated_hash_set_groups' => [],
            'nntmux.ingest_collection_keying_groups' => [],
            'nntmux.ingest_collection_keying_legacy_adoption' => false,
        ]);
        DB::purge();
        DB::reconnect();

        DB::connection()->getPdo()->sqliteCreateFunction(
            'GREATEST',
            static fn (int|float $left, int|float $right): int|float => max($left, $right),
            2
        );

        $this->createTables();
        $this->seedSettings();
    }

    // ---------------------------------------------------------------- the fix

    /**
     * The whole point of the design.
     */
    public function test_one_counterless_posting_becomes_one_densely_numbered_collection(): void
    {
        $this->storage(['alt.binaries.cinemageddon'])->store($this->postingHeaders(), self::GROUP, true);

        $collections = DB::table('collections')->get();
        self::assertCount(1, $collections, 'one posting must be one collection');
        self::assertSame(0, (int) $collections[0]->totalfiles, 'a leaked part count must not reach totalfiles');

        $binaries = DB::table('binaries')
            ->where('collections_id', (int) $collections[0]->id)
            ->orderBy('filenumber')
            ->get();

        self::assertCount(4, $binaries, 'one binary per file of the posting');
        self::assertSame(
            [1, 2, 3, 4],
            $binaries->map(static fn (object $binary): int => (int) $binary->filenumber)->all(),
            'filenumbers must be dense 1..N or stage 0 can never promote',
        );

        // Subject order, so a replay numbers the posting the same way.
        self::assertSame(
            array_map(static fn (string $name): string => $name.' yEnc', self::POSTING),
            $binaries->map(static fn (object $binary): string => (string) $binary->name)->all(),
        );

        // Nothing was dropped on the way in.
        self::assertSame(8, DB::table('parts')->count());
    }

    /**
     * Density has to survive the chunk seam, not just fit inside one chunk.
     *
     * `headerChunkSize` bounds each transaction, so one posting routinely spans
     * several. Each chunk resets the handlers' in-memory caches and re-resolves
     * the collection from the database, and the allocator then continues from
     * the committed MAX(filenumber) -- which is the only reason a posting larger
     * than a chunk can ever reach stage 0.
     */
    public function test_density_survives_the_chunk_boundary(): void
    {
        $this->storage(['alt.binaries.cinemageddon'], headerChunkSize: 2)
            ->store($this->postingHeaders(), self::GROUP, true);

        self::assertSame(1, DB::table('collections')->count());
        self::assertSame(
            [1, 2, 3, 4],
            array_map('intval', DB::table('binaries')->orderBy('filenumber')->pluck('filenumber')->all()),
            'ordinals must continue across chunks, not restart at 1',
        );
        self::assertSame(8, DB::table('parts')->count());

        $this->runStageZero();
        self::assertSame(
            CollectionFileCheckStatus::CompleteCollection->value,
            (int) DB::table('collections')->value('filecheck'),
        );
    }

    /**
     * Stage 0 is the reason density is not negotiable. Its gate is
     *
     *     COUNT(DISTINCT filenumber) >= GREATEST(1, CEIL(MAX(filenumber) * completion / 100))
     *
     * and with completion = 100 that is COUNT(DISTINCT filenumber) == MAX(filenumber).
     */
    public function test_stage_zero_promotes_the_re_keyed_collection(): void
    {
        $this->storage(['alt.binaries.cinemageddon'])->store($this->postingHeaders(), self::GROUP, true);

        $this->runStageZero();

        $collection = DB::table('collections')->first();
        self::assertSame(
            CollectionFileCheckStatus::CompleteCollection->value,
            (int) $collection->filecheck,
            'a densely numbered totalfiles=0 collection must promote',
        );
        self::assertSame(4, (int) $collection->totalfiles, 'stage 0 recovers the real file count');
    }

    /**
     * Pins the production assumption the safety argument rests on:
     * `settings.completion` is NULL in production, which
     * requiredCompletionPercent() turns into 100. If anyone ever sets it lower,
     * sparse ordinals start passing stage 0 and this design's safety argument
     * weakens.
     */
    public function test_the_completion_gate_is_one_hundred_percent(): void
    {
        $method = new ReflectionMethod(ReleaseProcessingService::class, 'requiredCompletionPercent');

        self::assertSame(100, $method->invoke(new ReleaseProcessingService));
    }

    /**
     * The counterpart: a sparse collection must NOT promote, so a failed
     * allocation is a stall rather than a short release.
     */
    public function test_a_sparse_collection_does_not_promote(): void
    {
        DB::table('collections')->insert([
            'id' => 900,
            'subject' => 'sparse',
            'fromname' => 'x',
            'xref' => '',
            'groups_id' => self::GROUP['id'],
            'totalfiles' => 0,
            'filecheck' => CollectionFileCheckStatus::Default->value,
            'collectionhash' => 'sparse-hash',
            'collection_regexes_id' => 0,
            'noise' => '',
        ]);
        foreach ([1, 2, 9] as $fileNumber) {
            DB::table('binaries')->insert([
                'binaryhash' => 'h'.$fileNumber,
                'name' => 'f'.$fileNumber,
                'collections_id' => 900,
                'totalparts' => 1,
                'currentparts' => 1,
                'filenumber' => $fileNumber,
                'partsize' => 1,
            ]);
        }

        $this->runStageZero();

        $collection = DB::table('collections')->where('id', 900)->first();
        self::assertSame(CollectionFileCheckStatus::Default->value, (int) $collection->filecheck);
    }

    // --------------------------------------------------------- the guardrails

    /**
     * Characterisation. With the flag off, ingest must produce today's
     * fragmented shape EXACTLY -- four collections, one binary each, every
     * binary claiming filenumber 1 -- so the flag is provably a switch and not
     * a rewrite.
     *
     * If this ever starts failing, the change stopped being opt-in.
     */
    public function test_with_the_flag_off_the_posting_still_fragments(): void
    {
        $this->storage([])->store($this->postingHeaders(), self::GROUP, true);

        $collections = DB::table('collections')->orderBy('totalfiles')->get();
        self::assertCount(4, $collections, 'today: one collection per distinct part count');
        self::assertSame(
            self::POSTING_PART_COUNTS,
            $collections->map(static fn (object $c): int => (int) $c->totalfiles)->all(),
            'today: the part counter IS the collection totalfiles',
        );

        foreach ($collections as $collection) {
            $binaries = DB::table('binaries')->where('collections_id', (int) $collection->id)->get();
            self::assertCount(1, $binaries, 'today: a one-binary fragment');
            self::assertSame(1, (int) $binaries[0]->filenumber, 'today: the PART number is the filenumber');
        }
    }

    /**
     * The allowlist is per group, not global. Enabling cinemageddon must not
     * change what hdtv does.
     */
    public function test_a_group_that_is_not_on_the_allowlist_is_untouched(): void
    {
        $this->storage(['alt.binaries.cinemageddon'])->store(
            $this->postingHeaders(self::OTHER_GROUP),
            self::OTHER_GROUP,
            true
        );

        self::assertSame(4, DB::table('collections')->count(), 'an unlisted group keeps today behaviour');
    }

    /**
     * There is deliberately no `all` sentinel on the WRITE flag, unlike the
     * reporting flag it is often confused with. The rollout is group by group.
     */
    public function test_the_write_flag_has_no_all_sentinel(): void
    {
        $keying = new IngestCollectionKeying(['all'], false);

        self::assertFalse(
            $keying->appliesTo('alt.binaries.cinemageddon'),
            '`all` is a literal name here, not a sentinel: it enables no real group',
        );
        self::assertFalse($keying->appliesTo(''), 'an unnamed group can never be enabled');
        self::assertTrue(
            (new IngestCollectionKeying(['ALT.BINARIES.CINEMAGEDDON  '], false))
                ->appliesTo('alt.binaries.cinemageddon'),
            'case and padding are ignored, as everywhere else',
        );
    }

    /**
     * The regression that must fail loudly if the classification ever widens.
     *
     * A subject carrying a REAL file counter keeps byte-identical behaviour --
     * same key, same totalfiles, same ordinal -- whether or not the group is
     * enabled. Asserted differentially: the same posting is ingested twice into
     * a clean database, once with the flag off and once with it on, and the
     * resulting rows are compared.
     */
    public function test_a_real_file_counter_is_untouched_by_the_flag(): void
    {
        $headers = $this->realCounterHeaders();

        $this->storage([])->store($headers, self::GROUP, true);
        $flagOff = $this->shapeSnapshot();

        $this->truncateIngestTables();

        $this->storage(['alt.binaries.cinemageddon'])->store($headers, self::GROUP, true);
        $flagOn = $this->shapeSnapshot();

        self::assertSame($flagOff, $flagOn, 'a declared file counter must survive the flag unchanged');

        // And it really is the shape we think it is, not two identical empties.
        self::assertSame(1, DB::table('collections')->count());
        self::assertSame(2, (int) DB::table('collections')->value('totalfiles'));
        self::assertSame([1, 2], array_map(
            'intval',
            DB::table('binaries')->orderBy('filenumber')->pluck('filenumber')->all()
        ));
    }

    // --------------------------------------------------- legacy key adoption

    /**
     * Design section 4. A posting that was mid-flight when the flag flipped
     * already has collections under `name . partCount`. Its next articles
     * compute `name . 0`, miss, and would mint yet another collection -- so on a
     * miss we adopt the collection the OLD key would have hit.
     *
     * Note what adoption can and cannot reach. Every demoted header of one
     * posting collapses onto ONE new key, so the pending row carries the legacy
     * keys of ALL of them; the lowest-id match wins and the whole posting joins
     * it. But that only works because one of those legacy keys is already in the
     * database -- adoption catches a posting whose ingest had STARTED, which is
     * exactly the in-flight case it is for. A posting whose every article
     * arrives after the flip has nothing to adopt and simply gets the new shape.
     */
    public function test_a_mid_flight_posting_is_adopted_rather_than_re_minted(): void
    {
        $headers = $this->postingHeaders();

        // One article lands under the old key: `name . 2`.
        $this->storage([])->store(\array_slice($headers, 0, 1), self::GROUP, true);
        self::assertSame(1, DB::table('collections')->count());
        $legacyId = (int) DB::table('collections')->value('id');
        self::assertSame(2, (int) DB::table('collections')->value('totalfiles'));

        // The flag flips; the rest of the posting arrives.
        $this->storage(['alt.binaries.cinemageddon'], legacyAdoption: true)
            ->store(\array_slice($headers, 1), self::GROUP, true);

        self::assertSame(1, DB::table('collections')->count(), 'the posting must not be re-minted');
        self::assertSame($legacyId, (int) DB::table('collections')->value('id'));
        self::assertSame(4, DB::table('binaries')->where('collections_id', $legacyId)->count());
        self::assertSame(
            [1, 2, 3, 4],
            array_map('intval', DB::table('binaries')->orderBy('filenumber')->pluck('filenumber')->all()),
            'ordinals continue from the adopted collection MAX, so the result is still dense',
        );
    }

    /**
     * Adoption is off by default, and off means off: the same sequence mints a
     * second collection, which is exactly what the hourly sweep is there to
     * merge.
     *
     * This is not a nicety. An adopted collection keeps its old, wrong
     * `totalfiles` (2 here), so feeding it the rest of a large posting can carry
     * it past the stage 1 gate and release it short. Turning adoption on is a
     * deliberate trade of sweep work against that risk.
     */
    public function test_without_adoption_the_new_shape_is_a_separate_collection(): void
    {
        $headers = $this->postingHeaders();

        $this->storage([])->store(\array_slice($headers, 0, 1), self::GROUP, true);
        $this->storage(['alt.binaries.cinemageddon'])->store(\array_slice($headers, 1), self::GROUP, true);

        self::assertSame(2, DB::table('collections')->count());
        self::assertSame(
            [0, 2],
            DB::table('collections')->orderBy('totalfiles')->pluck('totalfiles')
                ->map(static fn (mixed $value): int => (int) $value)->all(),
        );
    }

    /**
     * Open question 3 of the design, asserted so the exclusion is deliberate
     * rather than discovered.
     *
     * SplitCollectionReconciler selects `whereBetween('totalfiles', [2, MAX])`,
     * so new-shape collections are invisible to it. That is correct: the
     * reconciler exists to pair a payload anchor with its par2 companions ACROSS
     * collections, and this change puts them in one collection to begin with.
     */
    public function test_new_shape_collections_are_invisible_to_the_split_reconciler(): void
    {
        $this->seedReconcilerCandidate(801, totalFiles: 0);
        $this->seedReconcilerCandidate(802, totalFiles: 3);

        $method = new ReflectionMethod(SplitCollectionReconciler::class, 'candidateCollectionIds');
        $candidates = $method->invoke(
            new SplitCollectionReconciler,
            self::GROUP['id'],
            now()->subDay()->toDateTimeString(),
        );

        self::assertSame(
            [802],
            array_values(array_map('intval', $candidates)),
            'a totalfiles=0 collection is out of the reconciler\'s scope by design',
        );
    }

    // ------------------------------------------------------------- fixtures

    private function seedReconcilerCandidate(int $id, int $totalFiles): void
    {
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => 'candidate-'.$id,
            'fromname' => 'x',
            'xref' => '',
            'groups_id' => self::GROUP['id'],
            'totalfiles' => $totalFiles,
            'filecheck' => CollectionFileCheckStatus::Default->value,
            'collectionhash' => 'candidate-'.$id,
            'collection_regexes_id' => 0,
            'dateadded' => now()->toDateTimeString(),
            'releases_id' => null,
            'noise' => '',
        ]);
    }

    /**
     * @param  list<string>  $keyingGroups
     */
    private function storage(
        array $keyingGroups,
        bool $legacyAdoption = false,
        int $headerChunkSize = 50,
    ): HeaderStorageService {
        return new HeaderStorageService(
            // The real cleaner needs the DB regex table; stub it to the
            // behaviour that matters here -- digit runs stripped, so every file
            // of the posting cleans to one name. That is what the design
            // verified against the live service.
            new CollectionHandler(new class extends CollectionsCleaningService
            {
                public function collectionsCleaner(string $subject, string $groupName = ''): array
                {
                    return [
                        'id' => self::REGEX_GENERIC_MATCH,
                        'name' => preg_replace('/\d+/', '', $subject) ?? $subject,
                    ];
                }
            }),
            config: new BinariesConfig(partsChunkSize: 50, headerChunkSize: $headerChunkSize),
            keying: new IngestCollectionKeying($keyingGroups, $legacyAdoption),
            fileNumbers: new CollectionFileNumberAllocator,
        );
    }

    /**
     * Two articles for each of four par2 volumes of one posting. No file
     * counter anywhere; only the part counter, which differs per file.
     *
     * @param  array{id: int, name: string}|null  $group
     * @return list<array<string, mixed>>
     */
    private function postingHeaders(?array $group = null): array
    {
        $group ??= self::GROUP;
        $raw = [];
        $article = 0;

        foreach (self::POSTING as $fileIndex => $name) {
            $totalParts = self::POSTING_PART_COUNTS[$fileIndex];
            foreach ([1, 2] as $part) {
                $article++;
                $raw[] = $this->rawHeader(
                    $article,
                    \sprintf('%s yEnc (%d/%d)', $name, $part, $totalParts),
                    $group
                );
            }
        }

        return $this->parse($raw);
    }

    /**
     * A posting that declares a real `[n/m]` file counter, two files, two
     * articles each.
     *
     * @return list<array<string, mixed>>
     */
    private function realCounterHeaders(): array
    {
        $raw = [];
        $article = 0;

        foreach ([1, 2] as $fileNumber) {
            foreach ([1, 2] as $part) {
                $article++;
                $raw[] = $this->rawHeader(
                    $article,
                    \sprintf(
                        'Some.Release [%02d/02] - "x.part%02d.rar" yEnc (%d/2)',
                        $fileNumber,
                        $fileNumber,
                        $part
                    ),
                    self::GROUP
                );
            }
        }

        return $this->parse($raw);
    }

    /**
     * The parse HeaderParser performs before storage: matches[0] is the raw
     * subject, matches[1] the same thing with its (x/y) part counter removed.
     *
     * @param  list<array<string, mixed>>  $raw
     * @return list<array<string, mixed>>
     */
    private function parse(array $raw): array
    {
        $parsed = [];
        foreach ($raw as $header) {
            if (preg_match('/^\s*(?!Usenet Index Post)(.+?)\s+\((\d+)\/(\d+)\)/', (string) $header['Subject'], $matches) === 1) {
                $header['matches'] = $matches;
                $parsed[] = $header;
            }
        }

        self::assertCount(\count($raw), $parsed, 'every fixture must be a parseable yEnc subject');

        return $parsed;
    }

    /**
     * @param  array{id: int, name: string}  $group
     * @return array<string, mixed>
     */
    private function rawHeader(int $number, string $subject, array $group): array
    {
        return [
            'Number' => $number,
            'Subject' => $subject,
            'From' => 'Sleazer <sleaze@test.com>',
            'Date' => 'Tue, 04 Aug 2026 09:11:02 +0000',
            'Message-ID' => '<article'.$number.'@ngPost>',
            'Bytes' => 640000,
            'Xref' => 'news.example '.$group['name'].':'.$number,
        ];
    }

    /**
     * Everything about the written shape that the flag could possibly move.
     *
     * @return array<string, mixed>
     */
    private function shapeSnapshot(): array
    {
        return [
            'collections' => DB::table('collections')
                ->orderBy('collectionhash')
                ->get(['subject', 'totalfiles', 'collectionhash', 'groups_id'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'binaries' => DB::table('binaries')
                ->orderBy('name')
                ->get(['name', 'filenumber', 'totalparts', 'currentparts'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'parts' => DB::table('parts')->orderBy('number')->pluck('number')->all(),
        ];
    }

    private function runStageZero(): void
    {
        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);

        $method = new ReflectionMethod(ReleaseProcessingService::class, 'runCollectionFileCheckStage0');
        $method->invoke($service, null);
    }

    private function truncateIngestTables(): void
    {
        foreach (['collections', 'binaries', 'parts', 'missed_parts'] as $table) {
            DB::table($table)->delete();
        }
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255) UNIQUE)');
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');

        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            dateadded DATETIME NULL,
            added DATETIME NULL,
            xref TEXT DEFAULT \'\',
            groups_id INT,
            totalfiles INT,
            filesize INTEGER DEFAULT 0,
            filecheck INTEGER DEFAULT 0,
            collectionhash VARCHAR(40) UNIQUE,
            collection_regexes_id INT,
            releases_id INTEGER NULL,
            noise VARCHAR(64) DEFAULT \'\'
        )');

        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            name VARCHAR(255),
            collections_id INT,
            totalparts INT,
            currentparts INT,
            partcheck INTEGER DEFAULT 0,
            filenumber INT,
            partsize INT,
            UNIQUE(collections_id, filenumber)
        )');

        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT,
            UNIQUE(binaries_id, number)
        )');

        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY,
            numberid INT,
            groups_id INT,
            attempts INT DEFAULT 0,
            recovery_kind VARCHAR(32) NULL,
            recovery_source_collection_id INT NULL,
            recovery_source_binary_id INT NULL,
            claim_token VARCHAR(64) NULL,
            claim_owner VARCHAR(128) NULL,
            claim_expires_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE(numberid, groups_id)
        )');

        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INT DEFAULT 1,
            ordinal INT DEFAULT 0
        )');
    }

    /**
     * `completionpercent` is deliberately absent: it is NULL in production, so
     * ProcessReleasesSettings resolves completion to 0 and
     * requiredCompletionPercent() turns that into 100.
     */
    private function seedSettings(): void
    {
        foreach ([
            'maxnzbsprocessed' => '1000',
            'delaytime' => '12',
            'crossposttime' => '2',
            'collection_timeout' => '48',
            'maxsizetoformrelease' => '0',
            'minsizetoformrelease' => '0',
            'minfilestoformrelease' => '2',
            'releaseretentiondays' => '0',
            'deletepasswordedrelease' => '0',
            'miscotherretentionhours' => '0',
            'mischashedretentionhours' => '0',
            'partretentionhours' => '24',
            'last_run_time' => '',
        ] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }
}
