<?php

declare(strict_types=1);

namespace App\Support\ReleaseNames;

final class CompactTvEpisode
{
    /**
     * Match a conservative scene-style compact season/episode name.
     *
     * @return array{season:int,episode:int,quality:'sd'|'hd'|'uhd',source:string}|null
     */
    public static function match(string $name, ?string $groupName = null): ?array
    {
        if ($groupName !== null
            && preg_match('/(?:alt\.binaries|a\.b)\.(?:.*\.)?(?:tv|hdtv|tvseries|documentar(?:y|ies))(?:\.|$)/i', $groupName) !== 1) {
            return null;
        }

        if (preg_match('/(?:^|[._ -])(?:19|20)\d{2}(?:[._ -]|$)/', $name) === 1) {
            return null;
        }

        if (preg_match(
            '/^(?<prefix>.*?)[._ -](?<season>0[1-9])(?<episode>0[1-9]|[1-9][0-9])[._ -](?<resolution>(?:480|576|720|1080|2160)[pi]?|4k|uhd)[._ -](?<source>web(?:[._ -]?(?:dl|rip))?|hdtv|pdtv|bluray|bdrip|webrip)(?:[._ -]|$)/iu',
            trim($name),
            $matches,
        ) !== 1) {
            return null;
        }

        $prefixTokens = preg_split('/[._ -]+/u', trim($matches['prefix'])) ?: [];
        $readablePrefixTokens = array_filter(
            $prefixTokens,
            static fn (string $token): bool => preg_match('/^\p{L}[\p{L}\d]*$/u', $token) === 1,
        );
        if (count($readablePrefixTokens) < 2) {
            return null;
        }

        $resolution = strtolower($matches['resolution']);
        $quality = preg_match('/^(?:2160|4k|uhd)/', $resolution) === 1
            ? 'uhd'
            : (preg_match('/^(?:720|1080)/', $resolution) === 1 ? 'hd' : 'sd');

        return [
            'season' => (int) $matches['season'],
            'episode' => (int) $matches['episode'],
            'quality' => $quality,
            'source' => strtolower((string) preg_replace('/[._ -]+/', '', $matches['source'])),
        ];
    }
}
