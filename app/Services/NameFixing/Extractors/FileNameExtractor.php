<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Extractors;

use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\FileNameCleaner;

/**
 * Extracts release names from file names and paths.
 *
 * Handles various filename patterns for scene releases, streaming services,
 * video files, music, games, ebooks, and more.
 */
class FileNameExtractor
{
    /**
     * PreDB regex pattern for scene release names.
     */
    public const PREDB_REGEX = '/([\w.\'()\[\]-]+(?:[\s._-]+[\w.\'()\[\]-]+)+[-.][\w]+)/ui';

    protected FileNameCleaner $cleaner;

    public function __construct(?FileNameCleaner $cleaner = null)
    {
        $this->cleaner = $cleaner ?? new FileNameCleaner;
    }

    /**
     * Extract a release name from a filename.
     */
    public function extractFromFile(string $filename): ?NameFixResult
    {
        $result = [];
        $baseFilename = preg_replace('/^.*[\\\\\/]/', '', $filename) ?: $filename;
        $cleanedFilename = $this->cleaner->cleanForTitleMatch($filename);

        // Try each pattern in order of specificity

        if (str_contains($filename, '__NZBSPLIT__')) {
            $unwrapped = $this->cleaner->extractNzbSplitName($filename);

            if ($unwrapped !== null && $this->cleaner->isPlausibleReleaseTitle($unwrapped)) {
                return NameFixResult::fromMatch($unwrapped, 'NZBSPLIT wrapper', 'File');
            }

            return null;
        }

        if (preg_match('/(?:^|[._ -])sample(?:[._ -]|$)/i', $filename)) {
            return null;
        }

        if ($this->isSubtitleSupportFilename($baseFilename)) {
            return null;
        }

        if ($subjectResult = $this->extractFromYencSubject($filename)) {
            return $subjectResult;
        }

        if ($supportFileResult = $this->extractClassicMovieSupportFilename($baseFilename)) {
            return $supportFileResult;
        }

        if ($collectionPartResult = $this->extractClassicMovieCollectionArchivePart($baseFilename)) {
            return $collectionPartResult;
        }

        if (! preg_match('/(?:^|[._ -])(?:sample|proof|subs?|thumbs?)(?:[._ -]|$)/iu', $baseFilename)
            && ! $this->isLikelySoftwareArchivePart($baseFilename)
            && preg_match('/^(.+?(19|20)\d\d.+?)(?:[._ -]part0*\d+|[._ -]r\d{2,3})\.rar$/iu', $baseFilename, $result)) {
            return NameFixResult::fromMatch($this->normalizeBareMovieCandidate($result[1]).' DVDRip XviD NoGroup', 'Movie (year) archive part', 'File');
        }

        // Scene TV release with group suffix
        if (preg_match('/^(.+?(x264|x265|HEVC|XviD|H\.?264|H\.?265)\-[A-Za-z0-9]+)\\\\/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Scene release with group', 'File');
        }

        // TVP group format
        if (preg_match('/^(.+?(x264|XviD)\-TVP)\\\\/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'TVP', 'File');
        }

        // Generic TV - SxxExx format with quality/source info
        if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?S\d{1,3}[.-_ ]?E\d{1,3}(?:[.-_ ]?E\d{1,3})?[.-_ ].+?(?:720p|1080p|2160p|4K|HDTV|WEB-?DL|WEB-?RIP|BluRay|AMZN|HMAX|NF|DSNP).+?)\.(.+)$/iu', $filename, $result)) {
            return NameFixResult::fromMatch($result[4], 'TV SxxExx with quality', 'File');
        }

        // Generic TV - any SxxExx format
        if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+)\.(.+)$/iu', $filename, $result)) {
            return NameFixResult::fromMatch($result[4], 'Generic TV', 'File');
        }

        // 4K/UHD Movies - modern formats
        if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?[\.\-_ ](19|20)\d\d[\.\-_ ].+?(2160p|4K|UHD).+?(HDR10?\+?|DV|Dolby[\.\-_ ]?Vision)?.+?(HEVC|x265|H\.?265).+?)\.(.+)$/iu', $filename, $result)) {
            return NameFixResult::fromMatch($result[4], '4K/UHD Movie', 'File');
        }

        // HD Movies with modern codecs
        if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?[\.\-_ ](19|20)\d\d[\.\-_ ].+?(720p|1080p).+?(BluRay|WEB-?DL|WEB-?RIP|BDRip|REMUX).+?(x264|x265|HEVC|H\.?264|H\.?265|AVC).+?)\.(.+)$/iu', $filename, $result)) {
            return NameFixResult::fromMatch($result[4], 'HD Movie modern codec', 'File');
        }

        // Standard HD Movies
        if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?([\.\-_ ]\d{4}[\.\-_ ].+?(BDRip|bluray|DVDRip|XVID|WEB-?DL|HDTV)).+)\.(.+)$/iu', $filename, $result)) {
            return NameFixResult::fromMatch($result[4], 'Generic movie 1', 'File');
        }

        if (preg_match('/^([a-z0-9\.\-_]+(19|20)\d\d[a-z0-9\.\-_]+[\.\-_ ](720p|1080p|2160p|4K|BDRip|bluray|DVDRip|x264|x265|XviD|HEVC)[a-z0-9\.\-_]+)\.[a-z]{2,}$/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Generic movie 2', 'File');
        }

        // Streaming service releases
        if (preg_match('/^([A-Za-z0-9\.\-_]+[\.\-_ ](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|TVNZ|VUDU)[\.\-_ ].+?(WEB-?DL|WEB-?RIP).+?)\.(.+)$/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Streaming service release', 'File');
        }

        // Music releases
        if (preg_match('/(.+?([\.\-_ ](CD|FM)|[\.\-_ ]\dCD|CDR|FLAC|SAT|WEB).+?(19|20)\d\d.+?)\\\\.+/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Generic music', 'File');
        }

        if (preg_match('/^(.+?(19|20)\d\d\-([a-z0-9]{3}|[a-z]{2,}|C4))\\\\/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'music groups', 'File');
        }

        // FLAC music releases
        if (preg_match('/^(.+?[\.\-_ ](FLAC|MP3|AAC|OGG)[\.\-_ ].+?[\.\-_ ]\d{4}[\.\-_ ].+?\-[A-Za-z0-9]+)[\\\\\/.]/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Music with codec', 'File');
        }

        // Movie with year in parentheses - AVI format
        if (preg_match('/.+\\\\(.+\((19|20)\d\d\)\.avi)$/i', $filename, $result)) {
            $newName = str_replace('.avi', ' DVDRip XVID NoGroup', $result[1]);

            return NameFixResult::fromMatch($newName, 'Movie (year) avi', 'File');
        }

        // Movie with year in parentheses - ISO format
        if (preg_match('/.+\\\\(.+\((19|20)\d\d\)\.iso)$/i', $filename, $result)) {
            $newName = str_replace('.iso', ' DVD NoGroup', $result[1]);

            return NameFixResult::fromMatch($newName, 'Movie (year) iso', 'File');
        }

        // Movie with year in parentheses - MKV format
        if (preg_match('/.+\\\\(.+\((19|20)\d\d\)\.(mkv|mp4|m4v))$/i', $filename, $result)) {
            $newName = preg_replace('/\.(mkv|mp4|m4v)$/i', ' BDRip x264 NoGroup', $result[1]);

            return NameFixResult::fromMatch($newName, 'Movie (year) mkv/mp4', 'File');
        }

        // Bare classic/movie filenames are common in non-scene Usenet posts.
        // They do not carry quality tags, but the year plus video extension is
        // enough to recover a useful movie title from obfuscated subjects.
        if (! preg_match('/(?:^|[._ -])sample(?:[._ -]|$)/iu', $baseFilename)
            && preg_match('/^([\pL\pN][\pL\pN\s._\',;&!()’`-]{2,}?\(?\b(19|20)\d\d\)?)(?:[._ -][\pL]{2,})?\.(avi)$/iu', $baseFilename, $result)) {
            return NameFixResult::fromMatch(trim($result[1]).' DVDRip XviD NoGroup', 'Bare movie (year) avi', 'File');
        }

        if (! preg_match('/(?:^|[._ -])sample(?:[._ -]|$)/iu', $baseFilename)
            && preg_match('/^([\pL\pN][\pL\pN\s._\',;&!()’`-]{2,}?\(?\b(19|20)\d\d\)?)(?:[._ -][\pL]{2,})?\.(mkv|mp4|m4v)$/iu', $baseFilename, $result)) {
            return NameFixResult::fromMatch(trim($result[1]).' BDRip x264 NoGroup', 'Bare movie (year) mkv/mp4', 'File');
        }

        if (! preg_match('/(?:^|[._ -])(?:sample|proof|subs?|thumbs?)(?:[._ -]|$)/iu', $baseFilename)
            && ! $this->isLikelySoftwareArchivePart($baseFilename)
            && preg_match('/^(.+?(19|20)\d\d.+?)(?:[._ -]part0*\d+|[._ -]r\d{2,3})\.rar$/iu', $baseFilename, $result)) {
            return NameFixResult::fromMatch($this->normalizeBareMovieCandidate($result[1]).' DVDRip XviD NoGroup', 'Movie (year) archive part', 'File');
        }

        // RAR file contents - look for release name in RAR path
        if (preg_match('/^([A-Za-z0-9][\w.\-]+(?:[\.\-_ ][\w.\-]+)+)[\\\\\\/](?:CD\d|Disc\d|DVD\d|Subs?)?[\\\\\\/]?.+\.(rar|r\d{2,3}|zip|7z)$/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'RAR archive path', 'File');
        }

        // Scene release in RAR
        if (preg_match('/^([A-Za-z0-9][\w.\-]+\-[A-Za-z0-9]+)[\\\\\\/].+\.(rar|r\d{2,3})$/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Scene RAR release', 'File');
        }

        // XXX Imagesets
        if (preg_match('/^(.+?IMAGESET.+?)\\\\.+/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'XXX Imagesets', 'File');
        }

        // VIDEOOT releases
        if (preg_match('/^VIDEOOT-[A-Z0-9]+\\\\([\w!.,& ()\[\]\'\`-]{8,}?\b.?)([\-_](proof|sample|thumbs?))*(\.part\d*(\.rar)?|\.rar|\.7z)?(\d{1,3}\.rev|\.vol.+?|\.mp4)/', $filename, $result)) {
            return NameFixResult::fromMatch($result[1].' XXX DVDRIP XviD-VIDEOOT', 'XXX XviD VIDEOOT', 'File');
        }

        // XXX SDPORN
        if (preg_match('/^.+?SDPORN/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], 'XXX SDPORN', 'File');
        }

        // R&C releases
        if (preg_match('/\w[\-\w.\',;& ]+1080i[._ -]DD5[._ -]1[._ -]MPEG2-R&C(?=\.ts)$/i', $filename, $result)) {
            $newResult = str_replace('MPEG2', 'MPEG2.HDTV', $result[0]);

            return NameFixResult::fromMatch($newResult, 'R&C', 'File');
        }

        // NhaNc3 releases
        if (preg_match('/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -]nSD[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -]NhaNC3[\-\w.\',;& ]+\w/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], 'NhaNc3', 'File');
        }

        // TVP releases (alternate pattern)
        if (preg_match('/\wtvp-[\w.\',;]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](720p|1080p|xvid)(?=\.(avi|mkv))$/i', $filename, $result)) {
            $newResult = str_replace('720p', '720p.HDTV.X264', $result[0]);
            $newResult = str_replace('1080p', '1080p.Bluray.X264', $newResult);
            $newResult = str_replace('xvid', 'XVID.DVDrip', $newResult);

            return NameFixResult::fromMatch($newResult, 'tvp', 'File');
        }

        // LOL releases
        if (preg_match('/\w[\-\w.\',;& ]+\d{3,4}\.hdtv-lol\.(avi|mp4|mkv|ts|nfo|nzb)/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], 'Title.211.hdtv-lol.extension', 'File');
        }

        // DL releases
        if (preg_match('/\w[\-\w.\',;& ]+-S\d{1,2}[EX]\d{1,2}-XVID-DL\.avi/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], 'Title-SxxExx-XVID-DL.avi', 'File');
        }

        // Title - SxxExx - Episode title format
        if (preg_match('/\S.*[\w.\-\',;]+\s\-\ss\d{2}[ex]\d{2}\s\-\s[\w.\-\',;].+\./i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], 'Title - SxxExx - Eptitle', 'File');
        }

        // Nintendo DS
        if (preg_match('/\w.+?\)\.nds$/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], ').nds Nintendo DS', 'File');
        }

        // Nintendo 3DS
        if (preg_match('/3DS_\d{4}.+\d{4} - (.+?)\.3ds/i', $filename, $result)) {
            return NameFixResult::fromMatch('3DS '.$result[1], '.3ds Nintendo 3DS', 'File');
        }

        // Nintendo Switch
        if (preg_match('/^(.+?)\[[\w]+\]\.(?:nsp|xci|nsz)$/i', $filename, $result)) {
            return NameFixResult::fromMatch(trim($result[1]).' Switch', 'Nintendo Switch', 'File');
        }

        // PlayStation/Xbox game releases
        if (preg_match('/^(.+?[\.\-_ ](PS[345P]|PSV|XBOX360|XBOXONE|NSW)[\.\-_ ].+?\-[A-Za-z0-9]+)[\\\\\/.]/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Console game release', 'File');
        }

        // EBooks
        if (preg_match('/\w.+?\.(epub|mobi|azw3?|opf|fb2|prc|djvu|cb[rz])/i', $filename, $result)) {
            $newResult = str_replace('.'.$result[1], ' ('.$result[1].')', $result[0]);

            return NameFixResult::fromMatch($newResult, 'EBook', 'File');
        }

        // Audiobooks
        if (preg_match('/^(.+?[\.\-_ ]Audiobook[\.\-_ ].+?)[\\\\\/.]/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Audiobook', 'File');
        }

        if ($this->isLikelySoftwareArchivePart($baseFilename)) {
            return null;
        }

        // Scene release from cleaned filename
        if (preg_match('/^([A-Za-z0-9][\w.\-]+\-[A-Za-z0-9]{2,15})$/i', $cleanedFilename, $result) && preg_match(self::PREDB_REGEX, $cleanedFilename)) {
            return NameFixResult::fromMatch($result[1], 'Cleaned scene name', 'File');
        }

        // Folder name fallback
        if (! preg_match('/(?:^|\s)yEnc$/i', trim($filename))
            && ! $this->isLowInformationName($cleanedFilename)
            && preg_match('/\w+[\-\w.\',;& ]+$/i', $filename, $result)
            && preg_match(self::PREDB_REGEX, $filename)) {
            return NameFixResult::fromMatch($result[0], 'Folder name', 'File');
        }

        return null;
    }

    private function extractFromYencSubject(string $subject): ?NameFixResult
    {
        if (! preg_match('/\byEnc\b/i', $subject)) {
            return null;
        }

        if (! preg_match('/\b(19|20)\d{2}\b/', $subject)) {
            if ($sceneArchiveResult = $this->extractQuotedSceneArchiveFilename($subject)) {
                return $sceneArchiveResult;
            }

            return null;
        }

        $candidate = null;
        if (preg_match('/^(?:REQ[:\s]*)?(.+?\b(?:19|20)\d{2}\b.*?)(?:[._\s-]*\[\d+\/\d+\]\s*(?:-|\")|\s+-\s+"|\s+yEnc\b)/iu', $subject, $match)) {
            $candidate = $match[1];
        } elseif (preg_match('/^(?:REQ[:\s]*)?(.+?\b(?:19|20)\d{2}\b.*?)[._\s-]+\[[^\]]+\]/iu', $subject, $match)) {
            $candidate = $match[1];
        }

        if ($candidate === null) {
            if (preg_match('/^\[(?P<title>[^\[\]]*\b(?:19|20)\d{2}\b[^\[\]]*)\]\s+yEnc\b/iu', $subject, $match)) {
                $candidate = $match['title'];
            }
        }

        if ($candidate === null) {
            if ($supportFileResult = $this->extractQuotedClassicMovieSupportFilename($subject)) {
                return $supportFileResult;
            }

            if ($sceneArchiveResult = $this->extractQuotedSceneArchiveFilename($subject)) {
                return $sceneArchiveResult;
            }

            return null;
        }

        $candidate = trim((string) preg_replace('/\s+-\s*$/', '', $candidate));
        $candidate = trim($candidate, " \t\n\r\0\x0B.-_[]");

        if ($candidate === '' || ! $this->cleaner->isPlausibleReleaseTitle($candidate)) {
            if ($supportFileResult = $this->extractQuotedClassicMovieSupportFilename($subject)) {
                return $supportFileResult;
            }

            if ($bareSubjectResult = $this->extractBareMovieSubjectTitle($candidate, $subject)) {
                return $bareSubjectResult;
            }

            if ($sceneArchiveResult = $this->extractQuotedSceneArchiveFilename($subject)) {
                return $sceneArchiveResult;
            }

            return null;
        }

        return NameFixResult::fromMatch($candidate, 'yEnc subject title', 'File');
    }

    private function extractBareMovieSubjectTitle(string $candidate, string $subject): ?NameFixResult
    {
        if (! preg_match('/\b(19|20)\d{2}\b/u', $candidate)) {
            return null;
        }

        if (! preg_match('/(?:\[\d{1,4}\/\d{1,4}\]|"(?:[^"]+\.(?:part\d{1,4}\.rar|r\d{2,4}|rar|par2?))")/iu', $subject)) {
            return null;
        }

        $candidate = $this->normalizeBareMovieCandidate($candidate);
        if ($candidate === ''
            || $this->isLowInformationName($candidate)
            || $this->cleaner->looksLikeHashedName($candidate)
            || ! preg_match('/[\pL][\pL\pN\s._\',;&!()’`-]{2,}/u', $candidate)) {
            return null;
        }

        return NameFixResult::fromMatch($candidate.' DVDRip XviD NoGroup', 'Bare movie subject title', 'File');
    }

    private function extractQuotedClassicMovieSupportFilename(string $subject): ?NameFixResult
    {
        if (preg_match_all('/"([^"]+)"/', $subject, $matches)) {
            foreach ($matches[1] as $quoted) {
                if ($supportFileResult = $this->extractClassicMovieSupportFilename($quoted)) {
                    return $supportFileResult;
                }
            }
        }

        return null;
    }

    private function extractQuotedSceneArchiveFilename(string $subject): ?NameFixResult
    {
        if (preg_match_all('/"([^"]+)"/', $subject, $matches)) {
            foreach ($matches[1] as $quoted) {
                if ($collectionPartResult = $this->extractClassicMovieCollectionArchivePart($quoted)) {
                    return $collectionPartResult;
                }

                if ($sceneArchiveResult = $this->extractSceneArchiveFilename($quoted)) {
                    return $sceneArchiveResult;
                }
            }
        }

        return null;
    }

    private function extractClassicMovieSupportFilename(string $baseFilename): ?NameFixResult
    {
        if (preg_match('/(?:^|[._ -])(?:sample|proof|subs?|thumbs?)(?:[._ -]|$)/iu', $baseFilename)) {
            return null;
        }

        if (! preg_match('/\.(?:nfo|sfv|par2?|nzb|srr|srs|txt|md5|sha1)$/iu', $baseFilename)) {
            return null;
        }

        $candidate = $this->cleaner->normalizeCandidateTitle($baseFilename);
        $candidate = $this->normalizeBareMovieCandidate($candidate);

        if ($this->isLowInformationName($candidate)
            || ! preg_match('/\b(19|20)\d{2}\b/u', $candidate)
            || ! preg_match('/[\pL\pN][\pL\pN\s._\',;&!()’`-]{2,}/u', $candidate)) {
            return null;
        }

        return NameFixResult::fromMatch($candidate, 'Classic movie support filename', 'File');
    }

    private function extractClassicMovieCollectionArchivePart(string $baseFilename): ?NameFixResult
    {
        if (preg_match('/(?:^|[._ -])(?:sample|proof|subs?|thumbs?)(?:[._ -]|$)/iu', $baseFilename)) {
            return null;
        }

        if ($this->isLikelySoftwareArchivePart($baseFilename)) {
            return null;
        }

        if (! preg_match(
            '/^(?<title>[\pL\pN][\pL\pN\s._\',;&!()’`-]{2,}?)[._ -]+(?:cd|disc|disk|dvd)[._ -]*(?<disc>\d{1,3})[._ -]+of[._ -]+\d{1,3}(?:[._ -]part0*\d+|[._ -]r\d{2,4})\.rar$/iu',
            $baseFilename,
            $match,
        )) {
            return null;
        }

        $title = $this->normalizeBareMovieCandidate((string) $match['title']);
        if ($title === ''
            || $this->isLowInformationName($title)
            || $this->cleaner->looksLikeHashedName($title)
            || ! preg_match('/[\pL][\pL\s._\',;&!()’`-]{2,}/u', $title)) {
            return null;
        }

        return NameFixResult::fromMatch($title.' CD'.(int) $match['disc'].' DVDRip XviD NoGroup', 'Classic movie collection archive part', 'File');
    }

    private function extractSceneArchiveFilename(string $baseFilename): ?NameFixResult
    {
        if (preg_match('/(?:^|[._ -])(?:sample|proof|subs?|thumbs?)(?:[._ -]|$)/iu', $baseFilename)) {
            return null;
        }

        if ($this->isSubtitleSupportFilename($baseFilename)) {
            return null;
        }

        if (! preg_match('/\.(?:part\d{1,4}\.rar|r\d{2,4}|rar|par2?|vol\d+[+\-]\d+\.par2?|7z\.\d{2,4}|\d{3})$/iu', $baseFilename)) {
            return null;
        }

        if ($this->isLikelySoftwareArchivePart($baseFilename)) {
            return null;
        }

        $candidate = $this->stripArchiveSuffixes($baseFilename);

        if ($candidate === ''
            || $this->isLowInformationName($candidate)
            || $this->cleaner->looksLikeHashedName($candidate)
            || ! $this->cleaner->isPlausibleReleaseTitle($candidate)) {
            return null;
        }

        return NameFixResult::fromMatch($candidate, 'Scene archive filename', 'File');
    }

    private function normalizeBareMovieCandidate(string $candidate): string
    {
        $candidate = trim(str_replace(['_', '.'], ' ', $candidate));
        $candidate = preg_replace('/\s+/', ' ', $candidate) ?: $candidate;
        $candidate = trim($candidate, " \t\n\r\0\x0B.-_");
        $candidate = preg_replace('/^\[?\d{1,4}\/\d{1,4}\]?\s*/u', '', $candidate) ?: $candidate;
        $candidate = preg_replace('/^\d{1,4}\]\s*/u', '', $candidate) ?: $candidate;
        $candidate = trim($candidate, " \t\n\r\0\x0B.-_");
        $candidate = preg_replace('/\s+(?:avi|mkv|mp4|mpg|mpeg|vob|iso)$/iu', '', $candidate) ?: $candidate;
        $candidate = preg_replace('/^(?:an?\s+)?(?:mp4|mkv|avi|xvid|divx)\s+(?:file|film|movie)\s+/iu', '', $candidate) ?: $candidate;
        $candidate = preg_replace('/^(?:an?\s+)?((?:19|20)\d{2})\s+(?:mp4|mkv|avi|xvid|divx)\s+(?:file|film|movie)\s+(.+)$/iu', '$2 ($1)', $candidate) ?: $candidate;
        $candidate = preg_replace('/^((?:19|20)\d{2})\s+(?:an?\s+)?(?:mp4|mkv|avi|xvid|divx)\s+(?:file|film|movie)\s+(.+)$/iu', '$2 ($1)', $candidate) ?: $candidate;
        $candidate = preg_replace('/^\(((?:19|20)\d{2})\)\s+(?:an?\s+)?(?:mp4|mkv|avi|xvid|divx)\s+(?:file|film|movie)\s+(.+)$/iu', '$2 ($1)', $candidate) ?: $candidate;

        if (preg_match('/^\(((?:19|20)\d{2})\)\s+(.+)$/u', $candidate, $match)) {
            $candidate = trim($match[2]).' ('.$match[1].')';
        }

        return trim($candidate);
    }

    private function stripArchiveSuffixes(string $candidate): string
    {
        $candidate = trim($candidate);
        $patterns = [
            '/\.vol\d+[+\-]\d+\.par2?$/i',
            '/\.part\d{1,4}\.rar$/i',
            '/\.r\d{2,4}$/i',
            '/\.7z\.\d{2,4}$/i',
            '/\.par2?$/i',
            '/\.rar$/i',
            '/\.\d{3}$/',
        ];

        foreach ($patterns as $pattern) {
            $candidate = preg_replace($pattern, '', $candidate) ?? $candidate;
        }

        return trim($candidate, " \t\n\r\0\x0B.-_");
    }

    private function isLowInformationName(string|false|null $name): bool
    {
        if (! is_string($name)) {
            return true;
        }

        $name = trim($name, " \t\n\r\0\x0B.-_\"'");

        if ($name === '') {
            return true;
        }

        return preg_match('/^(?:nfo|sfv|par2?|nzb|srr|srs|txt|md5|sha1)$/i', $name) === 1;
    }

    private function isSubtitleSupportFilename(string $baseFilename): bool
    {
        return preg_match('/\.(?:srt|sub|idx|ssa|ass|sup|vtt)$/iu', $baseFilename) === 1;
    }

    private function isLikelySoftwareArchivePart(string $baseFilename): bool
    {
        if (! preg_match('/(?:[._ -]part0*\d+|[._ -]r\d{2,3})\.(?:rar|rev)$/i', $baseFilename)) {
            return false;
        }

        return preg_match(
            '/\b(?:setup|installer|patch|keygen|crack|downloader|build|fix|acronis|true[._ -]?image|vegas|pro|x64|x86|'
            .'wondershare|repairit|recoverit|photodirector|cyberlink|musify|ratiborus|kms|'
            .'activator|serial|license|portable|multilingual|win(?:dows)?|macos?)\b/i',
            $baseFilename
        ) === 1;
    }
}
