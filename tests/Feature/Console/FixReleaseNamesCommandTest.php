<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\NameFixing\NameFixingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class FixReleaseNamesCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });

        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '1'],
        ]);
    }

    public function test_hashed_category_option_maps_to_hashed_name_fixing_mode(): void
    {
        $nameFixingService = Mockery::mock(NameFixingService::class);
        $nameFixingService
            ->shouldReceive('fixNamesWithFiles')
            ->once()
            ->with(2, false, 5, false, false, 0);

        $this->app->instance(NameFixingService::class, $nameFixingService);

        $this->artisan('releases:fix-names', [
            'method' => '6',
            '--category' => 'hashed',
        ])->assertExitCode(0);
    }
}
