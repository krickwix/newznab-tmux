<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources\Clients;

use App\Services\NameFixing\ExternalSources\ExternalReleaseHit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SrrdbClient
{
    /**
     * @return array{title: string, files: list<array{name: string, size: int, crc: string}>}|null
     */
    public function details(string $releaseTitle): ?array
    {
        try {
            $response = Http::timeout((int) config('external_metadata.timeout', 20))
                ->withUserAgent('nntmux-external-metadata/1.0')
                ->acceptJson()
                ->get(rtrim((string) config('external_metadata.sources.srrdb.base_url'), '/').'/details/'.rawurlencode($releaseTitle));
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $files = $response->json('files');
        if (! is_array($files)) {
            return null;
        }

        $rows = [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            $crc = strtoupper(trim((string) ($file['crc'] ?? '')));
            $size = (int) ($file['size'] ?? 0);
            $name = trim((string) ($file['name'] ?? ''));

            if ($name === '' || $size <= 0 || ! preg_match('/^[A-F0-9]{8}$/', $crc)) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'size' => $size,
                'crc' => $crc,
            ];
        }

        return [
            'title' => (string) ($response->json('name') ?: $releaseTitle),
            'files' => $rows,
        ];
    }

    /**
     * @return list<ExternalReleaseHit>
     */
    public function searchByArchiveCrc(string $crc, ?int $size = null, int $limit = 10): array
    {
        $crc = strtoupper(trim($crc));
        if (! preg_match('/^[A-F0-9]{8}$/', $crc)) {
            return [];
        }

        $path = '/search/archive-crc:'.$crc;
        if ($size !== null && $size > 0) {
            $path .= '/archive-size:'.$size;
        }

        try {
            $response = Http::timeout((int) config('external_metadata.timeout', 20))
                ->withUserAgent('nntmux-external-metadata/1.0')
                ->acceptJson()
                ->get(rtrim((string) config('external_metadata.sources.srrdb.base_url'), '/').$path);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $rows = $response->json('results') ?? $response->json('releases') ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $hits = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['release'] ?? $row['name'] ?? $row['dirname'] ?? ''));
            if ($title === '') {
                continue;
            }

            $hits[] = new ExternalReleaseHit(
                source: 'srrdb',
                title: $title,
                category: $this->stringOrNull($row['category'] ?? null),
                externalId: $this->stringOrNull($row['id'] ?? $row['link'] ?? null),
                autoRenameEligible: true,
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
