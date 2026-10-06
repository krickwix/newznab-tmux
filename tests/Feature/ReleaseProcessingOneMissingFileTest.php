<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Services\ReleaseProcessingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Stage 6 forgives one missing file of a small, fully complete post.
 * MariaDB only (NNTMUX_TEST_MARIADB_DSN=mysql://user:pass@host:port/db):
 * SQLite divides integers, so CEIL(12 * 94 / 100) is 11 there and the
 * rounding this covers never happens.
 */
final class ReleaseProcessingOneMissingFileTest extends TestCase
{
    private bool $mariadb = false;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = (string) env('NNTMUX_TEST_MARIADB_DSN', '');
        if ($dsn === '') {
            $this->markTestSkipped('NNTMUX_TEST_MARIADB_DSN is not set.');
        }
        $parts = parse_url($dsn);
        config([
            'cache.default' => 'array',
            'nntmux.distributed_lock_store' => 'array',
            'database.default' => 'mariadb_t',
            'database.connections.mariadb_t' => [
                'driver' => 'mariadb', 'host' => $parts['host'] ?? '127.0.0.1', 'port' => $parts['port'] ?? 3306,
                'database' => ltrim($parts['path'] ?? '/t', '/'), 'username' => $parts['user'] ?? 'root',
                'password' => $parts['pass'] ?? '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '', 'strict' => true,
            ],
        ]);
        DB::purge('mariadb_t');
        $this->mariadb = true;
        $this->dropTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        if ($this->mariadb) {
            $this->dropTables();
        }
        parent::tearDown();
    }

    public function test_stage6_forgives_one_missing_file_of_a_small_fully_complete_post(): void
    {
        // [01/12]..[11/12] complete, [12/12] never posted.
        $this->seedCollection(11, totalFiles: 12);
        $this->seedBinaries(11, files: 11);
        // Ten of ten: complete without forgiveness.
        $this->seedCollection(16, totalFiles: 10);
        $this->seedBinaries(16, files: 10);

        $this->assertSame([11, 16], $this->stage6Candidates());
    }

    public function test_stage6_keeps_posts_that_do_not_qualify(): void
    {
        // A present binary is short a part, though it passes 94% part completion.
        $this->seedCollection(12, totalFiles: 12);
        $this->seedBinaries(12, files: 11, shortFile: 3);
        // Below the minimum post size, one file is too large a share.
        $this->seedCollection(13, totalFiles: 4);
        $this->seedBinaries(13, files: 3);
        // Not yet past delaytime: the last file may still be posting.
        $this->seedCollection(14, totalFiles: 12, hoursOld: 1);
        $this->seedBinaries(14, files: 11);
        // Two missing files are never forgiven.
        $this->seedCollection(15, totalFiles: 12);
        $this->seedBinaries(15, files: 10);
        // Large posts keep the plain percentage: 93 of 100 is below 94%.
        $this->seedCollection(17, totalFiles: 100);
        $this->seedBinaries(17, files: 93);

        $this->assertSame([], $this->stage6Candidates());
    }

    public function test_large_posts_still_pass_on_the_percentage(): void
    {
        $this->seedCollection(18, totalFiles: 100);
        $this->seedBinaries(18, files: 94);

        $this->assertSame([18], $this->stage6Candidates());
    }

    /** @return list<int> */
    private function stage6Candidates(): array
    {
        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);

        return (new ReflectionMethod($service, 'stage6CompleteCollectionIds'))->invoke($service, 0, null, 94, null);
    }

    private function seedCollection(int $id, int $totalFiles, int $hoursOld = 13): void
    {
        $at = now()->subHours($hoursOld)->format('Y-m-d H:i:s');
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => '[01/'.$totalFiles.'] - "Show.S01E0'.$id.'.mkv.par2" yEnc',
            'fromname' => 'poster@example.com',
            'date' => $at,
            'dateadded' => $at,
            'added' => $at,
            'xref' => 'alt.binaries.moovee:'.$id,
            'groups_id' => 5,
            'totalfiles' => $totalFiles,
            'filesize' => 0,
            'filecheck' => CollectionFileCheckStatus::Default->value,
            'collectionhash' => 'collection-'.$id,
            'collection_regexes_id' => -10,
            'releases_id' => null,
            'noise' => '',
        ]);
    }

    private function seedBinaries(int $collectionId, int $files, int $shortFile = 0): void
    {
        for ($fileNumber = 1; $fileNumber <= $files; $fileNumber++) {
            DB::table('binaries')->insert([
                'id' => $collectionId * 1000 + $fileNumber,
                'name' => sprintf('file.%03d.rar', $fileNumber),
                'collections_id' => $collectionId,
                'currentparts' => $fileNumber === $shortFile ? 99 : 100,
                'totalparts' => 100,
                'partcheck' => 0,
                'filenumber' => $fileNumber,
                'partsize' => 100,
            ]);
        }
    }

    private function dropTables(): void
    {
        foreach (['binaries', 'collections', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createTables(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('subject')->default('');
            $table->string('fromname')->default('');
            $table->dateTime('date')->nullable();
            $table->dateTime('dateadded')->nullable();
            $table->dateTime('added')->nullable();
            $table->text('xref')->nullable();
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedInteger('totalfiles')->default(0);
            $table->unsignedBigInteger('filesize')->default(0);
            $table->tinyInteger('filecheck')->default(0);
            $table->string('collectionhash')->default('0');
            $table->integer('collection_regexes_id')->default(0);
            $table->integer('releases_id')->nullable();
            $table->string('noise', 64)->default('');
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name', 1000)->default('');
            $table->unsignedInteger('collections_id')->default(0);
            $table->unsignedInteger('currentparts')->default(0);
            $table->unsignedInteger('totalparts')->default(0);
            $table->tinyInteger('partcheck')->default(0);
            $table->unsignedInteger('filenumber')->default(0);
            $table->unsignedBigInteger('partsize')->default(0);
        });

        foreach ([
            'maxnzbsprocessed' => '1000',
            'delaytime' => '12',
            'crossposttime' => '2',
            'completionpercent' => '94',
            'collection_timeout' => '96',
            'maxsizetoformrelease' => '0',
            'minsizetoformrelease' => '0',
            'minfilestoformrelease' => '1',
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
