<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources\Clients;

use App\Services\NameFixing\ExternalSources\ExternalReleaseHit;
use Illuminate\Support\Facades\Http;

class XrelClient
{
    /**
     * @return list<ExternalReleaseHit>
     */
    public function search(string $query, bool $p2p = false, int $limit = 10): array
    {
        $endpoint = $p2p ? '/p2p/releases.json' : '/release/search.json';
        $params = $p2p
            ? ['dirname' => $query, 'limit' => $limit]
            : ['dirname' => $query, 'limit' => $limit];

        $response = Http::timeout((int) config('external_metadata.timeout', 20))
            ->acceptJson()
            ->get(rtrim((string) config('external_metadata.sources.xrel.base_url'), '/').$endpoint, $params);

        if (! $response->successful()) {
            return [];
        }

        $rows = $response->json('list') ?? $response->json('results') ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $source = $p2p ? 'xrel-p2p' : 'xrel';
        $hits = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['dirname'] ?? $row['release_name'] ?? ''));
            if ($title === '') {
                continue;
            }

            $hits[] = new ExternalReleaseHit(
                source: $source,
                title: $title,
                group: $this->stringOrNull($row['group_name'] ?? $row['group'] ?? null),
                category: $this->stringOrNull($row['category'] ?? $row['type'] ?? null),
                externalId: isset($row['id']) ? (string) $row['id'] : ($row['link_href'] ?? null),
                autoRenameEligible: false,
            );
        }

        return $hits;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
