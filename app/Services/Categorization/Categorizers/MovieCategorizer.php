<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;

/**
 * Categorizer for Movie content including HD, SD, UHD, 3D, Blu-ray, DVD, etc.
 */
class MovieCategorizer extends AbstractCategorizer
{
    protected int $priority = 25;

    public function getName(): string
    {
        return 'Movie';
    }

    public function shouldSkip(ReleaseContext $context): bool
    {
        // Skip if this looks like adult content
        if ($context->hasAdultMarkers()) {
            return true;
        }

        // Skip if it looks like a TV episode (S01E01) or season pack (S01.1080p)
        if (preg_match('/[._ -]S\d{1,3}[._ -]?(E\d|D\d|Complete|Full|1080|720|480|2160|WEB|HDTV|BluRay|NF|AMZN)/i', $context->releaseName)) {
            return true;
        }

        // Skip episode-only patterns (E01, E02) - common in anime
        if (preg_match('/[._ -]E\d{1,4}[._ -]/i', $context->releaseName)) {
            return true;
        }

        // Skip known anime release groups
        if (preg_match('/(?:^|[.\-_ \[])(URANiME|ANiHLS|HaiKU|ANiURL|SkyAnime|Erai-raws|LostYears|Vodes|SubsPlease|Judas|Ember|YuiSubs|ASW|Tsundere-Raws|Anime-Raws)(?:[.\-_ \]]|$)/i', $context->releaseName)) {
            return true;
        }

        return false;
    }

    public function categorize(ReleaseContext $context): CategorizationResult
    {
        $name = $context->releaseName;

        // Check if it looks like movie content
        if (! $this->looksLikeMovie($name, $context)) {
            return $this->noMatch();
        }

        // Try specific movie subcategories in order of specificity
        if ($context->categorizeForeign && ($result = $this->checkForeign($name))) {
            return $result;
        }

        if ($result = $this->checkX265($name)) {
            return $result;
        }

        if ($result = $this->checkDocumentaryVideo($name, $context)) {
            return $result;
        }

        if ($result = $this->checkUHD($name)) {
            return $result;
        }

        if ($result = $this->check3D($name)) {
            return $result;
        }

        if ($result = $this->checkBluRay($name)) {
            return $result;
        }

        if ($result = $this->checkDVD($name)) {
            return $result;
        }

        if ($context->catWebDL && ($result = $this->checkWebDL($name))) {
            return $result;
        }

        if ($result = $this->checkHD($name, $context->catWebDL)) {
            return $result;
        }

        if ($result = $this->checkVintageFilmSD($name, $context)) {
            return $result;
        }

        if ($result = $this->checkSD($name)) {
            return $result;
        }

        if ($result = $this->checkClassicTitle($name, $context)) {
            return $result;
        }

        if ($result = $this->checkOther($name, $context)) {
            return $result;
        }

        return $this->noMatch();
    }

    /**
     * Check if release name looks like movie content.
     */
    protected function looksLikeMovie(string $name, ReleaseContext $context): bool
    {
        return (bool) preg_match('/[._ -]AVC|[BH][DR]RIP|(Bluray|Blu-Ray)|BD[._ -]?(25|50)?|\bBR\b|Camrip|[._ -]\d{4}[._ -].+(720p|1080p|Cam|HDTS|2160p)|DIVX|[._ -]DVD[._ -]|DVD-?(5|9|R|Rip)|Untouched|VHSRip|XVID|[._ -](DTS|TVrip|webrip|WEBDL|WEB-DL)[._ -]|\b(2160)p\b.*\b(Netflix|Amazon|NF|AMZN|Disney)\b/i', $name)
            || $this->looksLikeClassicMovieTitle($name, $context)
            || $this->looksLikeReadableVintageFilmArchiveSubject($name, $context)
            || $this->looksLikeVintageFilmPost($name, $context)
            || $this->looksLikeVideoPar2Sidecar($name)
            || $this->looksLikeCleanedVideoSidecar($name)
            || $this->looksLikeDocumentaryVideoPost($name, $context);
    }

    protected function looksLikeClassicMovieTitle(string $name, ReleaseContext $context): bool
    {
        $year = $this->extractTrailingYear($name);
        if ($year === null || $year > 1969) {
            return false;
        }

        if (preg_match('/\((?!19\d{2}|20\d{2})[^)]{3,80}\)\s*(?:\((?:19|20)\d{2}\)|(?:19|20)\d{2})\s*$/i', $name)) {
            return true;
        }

        return $context->groupMatchesPattern('/(?:alt\.binaries|a\.b)\..*?(?:vintage[.-]?film|classic[.-]?film|old[.-]?movies?)/i');
    }

    protected function extractTrailingYear(string $name): ?int
    {
        if (! preg_match('/(?:^|[._ \(])((?:19|20)\d{2})(?:\)?\s*)$/i', $name, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    protected function looksLikeVintageFilmPost(string $name, ReleaseContext $context): bool
    {
        if (! $context->groupMatchesPattern('/(?:alt\.binaries|a\.b)\..*?(?:vintage[.-]?film|classic[.-]?film|old[.-]?movies?|movies?[.-]?classic|dvd[.-]classic)/i')) {
            return false;
        }

        $hasVideoEvidence = (bool) preg_match('/(?:\.|\b)(?:nfo|rar|par2|avi|mkv|mp4|mpg|mpeg|vob|iso|nzb)(?:\.\d{3})?"?\s*(?:yEnc)?$/i', $name)
            || $this->looksLikeNumberedImageVideoSidecar($name)
            || preg_match('/\b(?:480p|576p|720p|1080p|x264|x265|h\.?264|h\.?265|xvid|divx|avi|mkv|mp4|mpg|mpeg|vcd|svcd|dvdrip|vhsrip|dvd|disc|film|films|shorts?)\b/i', $name)
            || preg_match('/\b(?:19|20)\d{2}-\d{1,3}(?:-\d{1,3})?mn\b/i', $name);

        if (! $hasVideoEvidence) {
            return false;
        }

        return true;
    }

    protected function checkForeign(string $name): ?CategorizationResult
    {
        if (preg_match('/(danish|flemish|Deutsch|dutch|french|german|heb|hebrew|nl[._ -]?sub|dub(bed|s)?|\.NL|norwegian|swedish|swesub|spanish|Staffel)[._ -]|\(german\)|Multisub/i', $name)) {
            return $this->matched(Category::MOVIE_FOREIGN, 0.8, 'foreign_language');
        }

        if (stripos($name, 'Castellano') !== false) {
            return $this->matched(Category::MOVIE_FOREIGN, 0.8, 'foreign_castellano');
        }

        if (preg_match('/(720p|1080p|AC3|AVC|DIVX|DVD(5|9|RIP|R)|XVID)[._ -](Dutch|French|German|ITA)|\(?(Dutch|French|German|ITA)\)?[._ -](720P|1080p|AC3|AVC|DIVX|DVD(5|9|RIP|R)|WEB(-DL|-?RIP)|HD[._ -]|XVID)/i', $name)) {
            return $this->matched(Category::MOVIE_FOREIGN, 0.85, 'foreign_pattern');
        }

        return null;
    }

    protected function checkX265(string $name): ?CategorizationResult
    {
        if (preg_match('/(\w+[\.-_\s]+).*(x265).*(Tigole|SESKAPiLE|CHD|IAMABLE|THREESOME|OohLaLa|DEFLATE|NCmt)/i', $name)) {
            return $this->matched(Category::MOVIE_X265, 0.9, 'x265_group');
        }

        return null;
    }

    protected function checkDocumentaryVideo(string $name, ReleaseContext $context): ?CategorizationResult
    {
        if (! $this->looksLikeDocumentaryVideoPost($name, $context)) {
            return null;
        }

        if (preg_match('/\b(?:WEBRip|WEB[._ -]?DL|WEB)\b/i', $name)) {
            return $this->matched(Category::MOVIE_WEBDL, 0.92, 'documentary_video_web');
        }

        if (preg_match('/\b(?:2160p|1080p|720p|x264|x265|h\.?264|h\.?265|mkv|mp4)\b/i', $name)) {
            return $this->matched(Category::MOVIE_HD, 0.9, 'documentary_video_hd');
        }

        return $this->matched(Category::MOVIE_OTHER, 0.86, 'documentary_video');
    }

    protected function checkUHD(string $name): ?CategorizationResult
    {
        // Skip TV shows
        if (preg_match('/(S\d+).*(2160p).*(Netflix|Amazon|NF|AMZN).*(TrollUHD|NTb|VLAD|DEFLATE|CMRG)/i', $name)) {
            return null;
        }

        // Check for UHD indicators
        if (stripos($name, '2160p') !== false ||
            preg_match('/\b(UHD|Ultra[._ -]HD|4K)\b/i', $name) ||
            (preg_match('/\b(HDR|HDR10|HDR10\+|Dolby[._ -]?Vision)\b/i', $name) &&
             preg_match('/\b(HEVC|H\.?265|x265)\b/i', $name)) ||
            (stripos($name, 'UHD') !== false &&
             preg_match('/\b(BR|BluRay|Blu[._ -]?Ray)\b/i', $name))) {
            return $this->matched(Category::MOVIE_UHD, 0.9, 'uhd');
        }

        return null;
    }

    protected function check3D(string $name): ?CategorizationResult
    {
        if (preg_match('/[._ -]3D\s?[\.\-_\[ ](1080p|(19|20)\d\d|AVC|BD(25|50)|Blu[._ -]?ray|CEE|Complete|GER|MVC|MULTi|SBS|H(-)?SBS)[._ -]/i', $name)) {
            return $this->matched(Category::MOVIE_3D, 0.9, '3d');
        }

        return null;
    }

    protected function checkBluRay(string $name): ?CategorizationResult
    {
        if (preg_match('/bluray-|[._ -]bd?[._ -]?(25|50)|blu-ray|Bluray\s-\sUntouched|[._ -]untouched[._ -]/i', $name) &&
            ! preg_match('/SecretUsenet\.com$/i', $name)) {
            return $this->matched(Category::MOVIE_BLURAY, 0.9, 'bluray');
        }

        return null;
    }

    protected function checkDVD(string $name): ?CategorizationResult
    {
        if (preg_match('/(dvd\-?r|[._ -]dvd|dvd9|dvd5|[._ -]r5)[._ -]/i', $name)) {
            return $this->matched(Category::MOVIE_DVD, 0.85, 'dvd');
        }

        return null;
    }

    protected function checkWebDL(string $name): ?CategorizationResult
    {
        if (preg_match('/web[._ -]dl|web-?rip/i', $name)) {
            return $this->matched(Category::MOVIE_WEBDL, 0.85, 'webdl');
        }

        return null;
    }

    protected function checkHD(string $name, bool $catWebDL): ?CategorizationResult
    {
        if (preg_match('/720p|1080p|AVC|VC1|VC-1|web-dl|wmvhd|x264|XvidHD|bdrip/i', $name)) {
            return $this->matched(Category::MOVIE_HD, 0.85, 'hd');
        }

        if (! $catWebDL && preg_match('/web[._ -]dl|web-?rip/i', $name)) {
            return $this->matched(Category::MOVIE_HD, 0.8, 'hd_webdl_fallback');
        }

        return null;
    }

    protected function checkSD(string $name): ?CategorizationResult
    {
        if (preg_match('/\b(?:480p|576p|SVCD)\b/i', $name)) {
            return $this->matched(Category::MOVIE_SD, 0.82, 'vintage_film_sd');
        }

        if (preg_match('/(divx|dvdscr|extrascene|dvdrip|\.CAM|HDTS(-LINE)?|vhsrip|xvid(vd)?)[._ -]/i', $name)) {
            return $this->matched(Category::MOVIE_SD, 0.8, 'sd');
        }

        return null;
    }

    protected function checkVintageFilmSD(string $name, ReleaseContext $context): ?CategorizationResult
    {
        if (! $context->groupMatchesPattern('/(?:alt\.binaries|a\.b)\..*?(?:vintage[.-]?film|classic[.-]?film|old[.-]?movies?)/i')) {
            return null;
        }

        if (preg_match('/(?:^|[._ \(-])(?:19|20)\d{2}(?:$|[._ -]|\)|\])/i', $name) &&
            (preg_match('/(?:\.|\b)(?:avi|mkv|mp4|mpg|mpeg|vob)(?:\.\d{3})?"?\s*(?:yEnc)?$/i', $name)
                || $this->looksLikeNumberedImageVideoSidecar($name)
                || preg_match('/\b(?:VCD|SVCD)[._ -]?(?:Collection|Disc|Movie|Film)\b/i', $name)
                || preg_match('/\b(?:19|20)\d{2}-\d{1,3}(?:-\d{1,3})?mn\b/i', $name))) {
            return $this->matched(Category::MOVIE_SD, 0.82, 'vintage_film_sd');
        }

        if ($this->looksLikeNumberedImageVideoSidecar($name)) {
            return $this->matched(Category::MOVIE_SD, 0.82, 'vintage_film_sd');
        }

        if (preg_match('/\b(?:VCD|SVCD)[._ -]?(?:Collection|Disc|Movie|Film)\b/i', $name)) {
            return $this->matched(Category::MOVIE_SD, 0.82, 'vintage_film_sd');
        }

        return null;
    }

    protected function looksLikeVideoPar2Sidecar(string $name): bool
    {
        if (! preg_match('/(?:"[^"]+|\\S+)\\.(?:avi|mkv|mp4|mpg|mpeg|vob)\\.par2"?\\s*(?:yEnc)?$/i', $name)) {
            return false;
        }

        return (bool) preg_match('/(?:19|20)\d{2}|\bmovie\b|\bfilm\b/i', $name);
    }

    protected function looksLikeCleanedVideoSidecar(string $name): bool
    {
        if (! preg_match('/\b(?:avi|mkv|mp4|mpg|mpeg|vob)\b/i', $name)) {
            return false;
        }

        return (bool) preg_match('/(?:19|20)\d{2}|\bmovie\b|\bfilm\b/i', $name);
    }

    protected function looksLikeNumberedImageVideoSidecar(string $name): bool
    {
        return preg_match('/\b(?:avi|mkv|mp4|mpg|mpeg|vob)[._ -]\d{1,4}[._ -](?:jpe?g|png)\b/i', $name) === 1;
    }

    protected function looksLikeDocumentaryVideoPost(string $name, ReleaseContext $context): bool
    {
        if (! $context->groupMatchesPattern('/(?:alt\.binaries|a\.b)\.documentaries(?:\.|$)/i')) {
            return false;
        }

        return (bool) preg_match('/\b(?:19|20)\d{2}\b/i', $name)
            && preg_match('/\b(?:2160p|1080p|720p|WEBRip|WEB[._ -]?DL|WEB|x264|x265|h\.?264|h\.?265|mkv|mp4)\b/i', $name);
    }

    protected function checkClassicTitle(string $name, ReleaseContext $context): ?CategorizationResult
    {
        if ($this->looksLikeClassicMovieTitle($name, $context)) {
            return $this->matched(Category::MOVIE_OTHER, 0.82, 'classic_movie_title');
        }

        return null;
    }

    protected function checkOther(string $name, ReleaseContext $context): ?CategorizationResult
    {
        if ($this->looksLikeReadableVintageFilmArchiveSubject($name, $context)) {
            return $this->matched(Category::MOVIE_OTHER, 0.82, 'vintage_film_file');
        }

        if ($this->looksLikeVideoPar2Sidecar($name)) {
            return $this->matched(Category::MOVIE_OTHER, 0.82, 'video_par2_sidecar');
        }

        if ($this->looksLikeCleanedVideoSidecar($name)) {
            return $this->matched(Category::MOVIE_OTHER, 0.82, 'video_sidecar_stem');
        }

        if (preg_match('/\b(?:19|20)\d{2}\b/i', $name) &&
            preg_match('/(?:\.|\b)(?:nfo|rar|par2|avi|mkv|mp4|mpg|mpeg|vob|iso|nzb)(?:\.par2)?"?\s*(?:yEnc)?$/i', $name)) {
            return $this->matched(Category::MOVIE_OTHER, 0.82, 'vintage_film_file');
        }

        if (preg_match('/[._ -]cam[._ -]/i', $name)) {
            return $this->matched(Category::MOVIE_OTHER, 0.6, 'cam');
        }

        return null;
    }

    private function looksLikeReadableVintageFilmArchiveSubject(string $name, ReleaseContext $context): bool
    {
        if (! $context->groupMatchesPattern('/(?:alt\.binaries|a\.b)\..*?(?:vintage[.-]?film|classic[.-]?film|old[.-]?movies?|movies?[.-]?classic|dvd[.-]classic)/i')) {
            return false;
        }

        if (! preg_match('/"[^"]+\.(?:part\d+\.rar|vol\d{1,4}\+\d{1,4}\.par2|par2|rar|zip|7z(?:\.\d{1,4})?)"/i', $name)) {
            return $this->isReadableVintageFilmNormalizedArchiveStem($name);
        }

        $outsideQuotedFilename = trim((string) preg_replace('/"[^"]+"/', ' ', $name));
        if (
            $this->countAlphabeticWordTokens($outsideQuotedFilename) >= 2
            && (
                preg_match('/\b(?:19|20)\d{2}\b/', $outsideQuotedFilename) === 1
                || preg_match('/\b(?:avi|xvid|divx|mkv|mp4|mpg|mpeg|vob|dvd|dvdrip|vhsrip|tvrip|bluray|blu[.-]?ray|480p|576p|720p|1080p|2160p)\b/i', $outsideQuotedFilename) === 1
            )
        ) {
            return true;
        }

        return $this->hasReadableQuotedArchiveStem($name);
    }

    private function isReadableVintageFilmNormalizedArchiveStem(string $name): bool
    {
        $trimmed = trim($name);

        if (
            preg_match('/^[A-Z][a-z]{3,}\d{1,4}$/', $trimmed) === 1
            && preg_match('/[aeiouy]/i', $trimmed) === 1
        ) {
            return true;
        }

        return $this->countAlphabeticWordTokens($trimmed) >= 2
            && preg_match('/\b(?:D?\d+of\d+|part\d+)\b/i', $trimmed) === 1;
    }

    private function hasReadableQuotedArchiveStem(string $name): bool
    {
        if (! preg_match('/"(?P<filename>[^"]+)"/', $name, $matches)) {
            return false;
        }

        $stem = (string) preg_replace(
            '/\.(?:part\d+\.rar|vol\d{1,4}\+\d{1,4}\.par2|par2|rar|zip|7z(?:\.\d{1,4})?)$/i',
            '',
            $matches['filename'],
        );

        $stem = str_replace(['_', '.', '-'], ' ', $stem);

        if ($this->countReadableStemWordTokens($stem) >= 2) {
            return true;
        }

        $compactStem = preg_replace('/\s+/', '', $stem) ?? $stem;

        return preg_match('/^[A-Z][a-z]{3,}(?:\d{2,4})?$/', $compactStem) === 1
            && preg_match('/[aeiouy]/i', $compactStem) === 1;
    }

    private function countReadableStemWordTokens(string $stem): int
    {
        $tokens = preg_split('/[.\s_-]+/', $stem) ?: [];

        return count(array_filter(
            $tokens,
            static fn (string $token): bool => preg_match('/^[A-Z]?[a-z]{2,}(?:[\'-][A-Z]?[a-z]{2,})?$/', $token) === 1
                && preg_match('/[aeiouy]/i', $token) === 1
        ));
    }

    private function countAlphabeticWordTokens(string $name): int
    {
        $tokens = preg_split('/[.\s_-]+/', $name) ?: [];

        return count(array_filter(
            $tokens,
            static fn (string $token): bool => preg_match('/[a-z]{3,}/i', $token) === 1
        ));
    }
}
