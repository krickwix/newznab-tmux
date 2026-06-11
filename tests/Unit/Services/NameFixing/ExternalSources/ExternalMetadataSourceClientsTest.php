<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing\ExternalSources;

use App\Services\NameFixing\ExternalSources\Clients\NzbIndexClient;
use App\Services\NameFixing\ExternalSources\Clients\PredbNetClient;
use App\Services\NameFixing\ExternalSources\Clients\PredbOvhClient;
use App\Services\NameFixing\ExternalSources\Clients\SrrdbClient;
use App\Services\NameFixing\ExternalSources\Clients\XrelClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalMetadataSourceClientsTest extends TestCase
{
    public function test_srrdb_client_parses_release_details_files(): void
    {
        Http::fake([
            'api.srrdb.com/v1/details/*' => Http::response([
                'name' => 'Movie.Name.2026.1080p.BluRay.x264-GRP',
                'files' => [
                    ['name' => 'movie.r00', 'size' => 15000000, 'crc' => 'aabbccdd'],
                    ['name' => 'movie.nfo', 'size' => 1200, 'crc' => 'BAD'],
                ],
            ]),
        ]);

        $details = (new SrrdbClient)->details('Movie.Name.2026.1080p.BluRay.x264-GRP');

        $this->assertSame('Movie.Name.2026.1080p.BluRay.x264-GRP', $details['title']);
        $this->assertSame([
            ['name' => 'movie.r00', 'size' => 15000000, 'crc' => 'AABBCCDD'],
        ], $details['files']);
    }

    public function test_predb_net_client_parses_release_search_results(): void
    {
        Http::fake([
            'api.predb.net/*' => Http::response([
                'status' => 'success',
                'results' => 1,
                'data' => [[
                    'id' => 13926955,
                    'release' => 'Movie.Name.2026.1080p.BluRay.x264-GRP',
                    'section' => 'X264-HD',
                    'files' => 42,
                    'size' => 10240,
                    'group' => 'GRP',
                    'pretime' => 1781200000,
                ]],
            ]),
        ]);

        $hits = (new PredbNetClient)->search('Movie Name 2026');

        $this->assertCount(1, $hits);
        $this->assertSame('predb-net', $hits[0]->source);
        $this->assertSame('Movie.Name.2026.1080p.BluRay.x264-GRP', $hits[0]->title);
        $this->assertSame('GRP', $hits[0]->group);
    }

    public function test_predb_ovh_client_parses_release_search_results(): void
    {
        Http::fake([
            'predb.ovh/api/v1/*' => Http::response([
                'status' => 'success',
                'data' => [
                    'rows' => [[
                        'id' => 7812994,
                        'name' => 'Movie.Name.2026.720p.BluRay.x264-GRP',
                        'team' => 'GRP',
                        'cat' => 'X264-HD',
                        'size' => 6500,
                        'files' => 19,
                        'preAt' => 1781200100,
                    ]],
                ],
            ]),
        ]);

        $hits = (new PredbOvhClient)->search('Movie Name');

        $this->assertCount(1, $hits);
        $this->assertSame('predb-ovh', $hits[0]->source);
        $this->assertSame('Movie.Name.2026.720p.BluRay.x264-GRP', $hits[0]->title);
    }

    public function test_nzbindex_client_parses_exact_obfuscated_collection_metadata_as_preview_only(): void
    {
        Http::fake([
            'www.nzbindex.com/api/search*' => Http::response([
                'data' => [
                    'content' => [[
                        'id' => '52a4dcb0-26be-30ef-884b-e9438b0b6657',
                        'name' => '[01/58] "GTsl1F9.7z.001"',
                        'poster' => 'poster@example.test',
                        'posted' => 1781160870,
                        'size' => 27580207619,
                        'fileCount' => 58,
                        'complete' => true,
                        'groups' => ['alt.binaries.blu-ray'],
                    ]],
                ],
                'error' => false,
            ]),
        ]);

        $hits = (new NzbIndexClient)->search('GTsl1F9');

        $this->assertCount(1, $hits);
        $this->assertSame('nzbindex', $hits[0]->source);
        $this->assertFalse($hits[0]->autoRenameEligible);
        $this->assertSame('poster@example.test', $hits[0]->payloadSummary['poster']);
    }

    public function test_xrel_client_parses_scene_and_p2p_release_results_as_preview_only(): void
    {
        Http::fake([
            'www.xrel.to/api/*' => Http::response([
                'list' => [[
                    'dirname' => 'Movie.Name.2026.1080p.WEB-DL.H264-GRP',
                    'group_name' => 'GRP',
                    'type' => 'p2p',
                    'link_href' => '/p2p/12345',
                ]],
            ]),
        ]);

        $hits = (new XrelClient)->search('Movie Name', p2p: true);

        $this->assertCount(1, $hits);
        $this->assertSame('xrel-p2p', $hits[0]->source);
        $this->assertFalse($hits[0]->autoRenameEligible);
        $this->assertSame('Movie.Name.2026.1080p.WEB-DL.H264-GRP', $hits[0]->title);
    }
}
