<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Release;
use App\Services\Movies\MovieLookupState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\ImdbScraperTestCase;

class MovieLookupStateTest extends ImdbScraperTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('searchname');
            $table->integer('categories_id');
            $table->string('imdbid')->nullable();
            $table->unsignedBigInteger('movieinfo_id')->nullable();
        });
        Schema::create('movie_lookup_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('release_id')->primary();
            $table->string('status', 16);
            $table->string('observed_imdbid', 100)->nullable();
            $table->string('attempted_imdbid', 100)->nullable();
            $table->string('observed_searchname');
            $table->integer('observed_category_id');
            $table->string('reason_code', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamps();
        });

        Release::query()->create([
            'id' => 1,
            'searchname' => 'Wrong.Movie.2026',
            'categories_id' => 2080,
            'imdbid' => '1234567',
        ]);
    }

    #[Test]
    public function terminal_failures_are_quarantined_after_the_configured_cap(): void
    {
        config(['nntmux_api.movie_lookup_max_attempts' => 2]);
        $state = new MovieLookupState;

        $firstToken = $state->claim(1);
        $this->assertNotNull($firstToken);
        $this->assertSame(['status' => 'retry', 'attempts' => 1], $state->fail(1, $firstToken, 'non_movie_identity', true));

        DB::table('movie_lookup_states')->where('release_id', 1)->update(['next_attempt_at' => now()->subMinute()]);
        $secondToken = $state->claim(1);
        $this->assertNotNull($secondToken);
        $this->assertSame(['status' => 'quarantined', 'attempts' => 2], $state->fail(1, $secondToken, 'non_movie_identity', true));
        $this->assertNull($state->claim(1));
        $this->assertNull(Release::query()->whereKey(1)->value('imdbid'));
        $this->assertSame('1234567', DB::table('movie_lookup_states')->where('release_id', 1)->value('attempted_imdbid'));
    }

    #[Test]
    public function transient_failures_delay_without_consuming_the_quarantine_budget(): void
    {
        config(['nntmux_api.movie_lookup_max_attempts' => 1]);
        $state = new MovieLookupState;

        $token = $state->claim(1);
        $this->assertNotNull($token);
        $this->assertSame(['status' => 'retry', 'attempts' => 0], $state->fail(1, $token, 'provider_unavailable', false));
        $this->assertSame(0, DB::table('movie_lookup_states')->where('release_id', 1)->value('attempts'));
        $this->assertSame(1, DB::table('movie_lookup_states')->where('release_id', 1)->value('retry_count'));
        $this->assertNull(DB::table('movie_lookup_states')->where('release_id', 1)->value('quarantined_at'));
        $this->assertTrue(now()->lt(DB::table('movie_lookup_states')->where('release_id', 1)->value('next_attempt_at')));
        $this->assertSame(0, $state->applyEligibility(Release::query())->count());

        DB::table('movie_lookup_states')->where('release_id', 1)->update(['next_attempt_at' => now()->subMinute()]);
        $this->assertSame(1, $state->applyEligibility(Release::query())->count());
    }

    #[Test]
    public function a_changed_release_snapshot_supersedes_old_quarantine(): void
    {
        config(['nntmux_api.movie_lookup_max_attempts' => 1]);
        $state = new MovieLookupState;
        $token = $state->claim(1);
        $this->assertNotNull($token);
        $state->fail(1, $token, 'non_movie_identity', true);

        Release::query()->whereKey(1)->update(['imdbid' => '7654321']);

        $this->assertNotNull($state->claim(1));
        $this->assertSame('7654321', DB::table('movie_lookup_states')->where('release_id', 1)->value('observed_imdbid'));
    }

    #[Test]
    public function an_active_claim_prevents_duplicate_provider_work(): void
    {
        $state = new MovieLookupState;

        $this->assertNotNull($state->claim(1));
        $this->assertNull($state->claim(1));
    }

    #[Test]
    public function an_expired_claim_cannot_link_or_finalize_after_reclaim(): void
    {
        $state = new MovieLookupState;
        $staleToken = $state->claim(1);
        $this->assertNotNull($staleToken);
        DB::table('movie_lookup_states')->where('release_id', 1)->update(['claim_expires_at' => now()->subMinute()]);

        $currentToken = $state->claim(1);
        $this->assertNotNull($currentToken);
        $this->assertFalse($state->link(1, $staleToken, '7654321', 42));
        $this->assertFalse($state->complete(1, $staleToken));
        $this->assertNull($state->fail(1, $staleToken, 'stale', true));
        $this->assertSame('1234567', Release::query()->whereKey(1)->value('imdbid'));
        $this->assertTrue($state->link(1, $currentToken, '7654321', 42));
        $this->assertSame('7654321', Release::query()->whereKey(1)->value('imdbid'));
    }

    #[Test]
    public function a_stale_no_match_result_cannot_overwrite_a_new_owners_link(): void
    {
        $state = new MovieLookupState;
        $staleToken = $state->claim(1);
        $this->assertNotNull($staleToken);
        DB::table('movie_lookup_states')->where('release_id', 1)->update(['claim_expires_at' => now()->subMinute()]);

        $currentToken = $state->claim(1);
        $this->assertNotNull($currentToken);
        $this->assertTrue($state->link(1, $currentToken, '7654321', 42));

        $this->assertFalse($state->markNoMatch(1, $staleToken));
        $this->assertSame('7654321', Release::query()->whereKey(1)->value('imdbid'));
        $this->assertSame(42, (int) Release::query()->whereKey(1)->value('movieinfo_id'));
    }

    #[Test]
    public function no_match_is_atomically_persisted_by_the_current_owner(): void
    {
        Release::query()->whereKey(1)->update(['imdbid' => null]);
        $state = new MovieLookupState;
        $token = $state->claim(1);
        $this->assertNotNull($token);

        $this->assertTrue($state->markNoMatch(1, $token));
        $this->assertSame('', Release::query()->whereKey(1)->value('imdbid'));
        $this->assertDatabaseMissing('movie_lookup_states', ['release_id' => 1]);
    }
}
