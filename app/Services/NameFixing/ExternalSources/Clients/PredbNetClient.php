<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources\Clients;

use App\Services\NameFixing\ExternalSources\ExternalReleaseHit;

class PredbNetClient
{
    use RecordsHttpStatus;

    /**
     * @return list<ExternalReleaseHit>
     */
    public function search(string $query, int $limit = 10): array
    {
        $response = $this->request()
            ->acceptJson()
            ->get(rtrim((string) config('external_metadata.sources.predb-net.base_url'), '/').'/', [
                'q' => $query,
                'limit' => $limit,
            ]);
        $this->recordStatus($response->status());

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
