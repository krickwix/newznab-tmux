<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources\Clients;

use App\Services\NameFixing\ExternalSources\ExternalReleaseHit;
use Illuminate\Support\Facades\Http;

class NzbIndexClient
{
    /**
     * @return list<ExternalReleaseHit>
     */
    public function search(string $query, int $limit = 10): array
    {
        $response = Http::timeout((int) config('external_metadata.timeout', 20))
            ->acceptJson()
            ->get(rtrim((string) config('external_metadata.sources.nzbindex.base_url'), '/').'/search', [
                'q' => $query,
                'max' => $limit,
            ]);

        if (! $response->successful() || $response->json('error') === true) {
            return [];
        }

        $rows = $response->json('data.content');
        if (! is_array($rows)) {
            return [];
        }

        $hits = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['name'] ?? ''));
            if ($title === '') {
                continue;
            }

            $hits[] = new ExternalReleaseHit(
                source: 'nzbindex',
                title: $title,
                files: $this->intOrNull($row['fileCount'] ?? null),
                size: $this->intOrNull($row['size'] ?? null),
                pretime: $this->intOrNull($row['posted'] ?? null),
                externalId: isset($row['id']) ? (string) $row['id'] : null,
                autoRenameEligible: false,
                payloadSummary: [
                    'poster' => $row['poster'] ?? null,
                    'groups' => is_array($row['groups'] ?? null) ? $row['groups'] : [],
                    'complete' => (bool) ($row['complete'] ?? false),
                ],
            );
        }

        return $hits;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
