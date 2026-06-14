<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources\Clients;

use App\Services\NameFixing\ExternalSources\ExternalReleaseHit;
use Illuminate\Support\Facades\Http;

class PredbNetClient
{
    /**
     * @return list<ExternalReleaseHit>
     */
    public function search(string $query, int $limit = 10): array
    {
        $response = Http::timeout((int) config('external_metadata.timeout', 20))
            ->acceptJson()
            ->get(rtrim((string) config('external_metadata.sources.predb-net.base_url'), '/').'/', [
                'q' => $query,
                'limit' => $limit,
            ]);

        if (! $response->successful()) {
            return [];
        }

        $rows = $response->json('data');
        if (! is_array($rows)) {
            return [];
        }

        $hits = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['release'] ?? ''));
            if ($title === '') {
                continue;
            }

            $hits[] = new ExternalReleaseHit(
                source: 'predb-net',
                title: $title,
                group: $this->stringOrNull($row['group'] ?? null),
                category: $this->stringOrNull($row['section'] ?? null),
                files: $this->intOrNull($row['files'] ?? null),
                size: $this->intOrNull($row['size'] ?? null),
                pretime: $this->intOrNull($row['pretime'] ?? null),
                externalId: isset($row['id']) ? (string) $row['id'] : null,
                autoRenameEligible: false,
                payloadSummary: ['status' => $row['status'] ?? null, 'reason' => $row['reason'] ?? null],
            );
        }

        return $hits;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
