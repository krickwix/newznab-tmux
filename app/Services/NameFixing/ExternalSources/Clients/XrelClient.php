<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources\Clients;

use App\Services\NameFixing\ExternalSources\ExternalReleaseHit;

class XrelClient
{
    use RecordsHttpStatus;

    /**
     * @return list<ExternalReleaseHit>
     */
    public function search(string $query, bool $p2p = false, int $limit = 10): array
    {
        $response = $this->request()
            ->acceptJson()
            ->get(rtrim((string) config('external_metadata.sources.xrel.base_url'), '/').'/search/releases.json', [
                'q' => $query,
                'scene' => $p2p ? 0 : 1,
                'p2p' => $p2p ? 1 : 0,
                'limit' => $limit,
            ]);
        $this->recordStatus($response->status());

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
