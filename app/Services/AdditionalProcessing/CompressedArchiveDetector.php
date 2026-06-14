<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

final class CompressedArchiveDetector
{
    /**
     * Match archive subjects that are worth downloading for deeper metadata
     * inspection. Keep this conservative: it should identify archive payloads,
     * not infer release names.
     */
    public static function titleLooksCompressed(string $title): bool
    {
        if ($title === '') {
            return false;
        }

        return preg_match(
            '/(\.(?:7z(?:\.\d{1,4})?|part\d+|[rz]\d+|rar|0+|0*10?|zipr\d{2,3}|zipx?)(?:\s*\.rar)*(?:$|[ ")]|-])|"[A-Za-z0-9][A-Za-z0-9._-]{15,}\.(?:7z(?:\.\d{1,4})?|part\d+\.rar|rar|zipx?|[1-9]\d{1,3})".*\(\d+\/\d{2,}\)$)/i',
            $title
        ) === 1;
    }
}
