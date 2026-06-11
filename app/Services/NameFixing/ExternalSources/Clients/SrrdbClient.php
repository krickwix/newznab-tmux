<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources\Clients;

use Illuminate\Support\Facades\Http;

class SrrdbClient
{
    /**
     * @return array{title: string, files: list<array{name: string, size: int, crc: string}>}|null
     */
    public function details(string $releaseTitle): ?array
    {
        $response = Http::timeout((int) config('external_metadata.timeout', 20))
            ->withUserAgent('nntmux-external-metadata/1.0')
            ->acceptJson()
            ->get(rtrim((string) config('external_metadata.sources.srrdb.base_url'), '/').'/details/'.rawurlencode($releaseTitle));

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
}
